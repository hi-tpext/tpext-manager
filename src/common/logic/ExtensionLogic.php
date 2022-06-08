<?php

namespace tpext\manager\common\logic;

class ExtensionLogic
{
    protected $errors = [];

    protected $remoteUrl = 'https://codeberg.org/hi-tpext/extensions/raw/branch/main/extensions.json';

    /**
     * Undocumented function
     *
     * @return array
     */
    final public function getErrors()
    {
        return $this->errors;
    }

     /**
     * Undocumented function
     *
     * @return array
     */
    public function getRemoteJson()
    {
        $jsonFile = app()->getRuntimePath() . 'extend' . DIRECTORY_SEPARATOR . 'extension.json';

        $data = '';

        if (is_file($jsonFile) && time() - filectime($jsonFile) <  60 * 60) {
            //
            $data = file_get_contents($jsonFile);
        } else {
            $data = file_get_contents($this->remoteUrl);
            if ($data) {
                file_put_contents($jsonFile, $data);
            }
        }

        $data = $data ? json_decode($data, true) : [];

        return $data;
    }

    /**
     * 获取通过extend目录方式自定义的扩展
     *
     * @param boolean $reget 是否强制重新扫描文件
     * @return array
     */
    public function getExtendExtensions($reget = false)
    {
        $data = cache('tpext_extend_extensions');

        if (!$reget && $data) {
            return $data;
        }

        $data = $this->scanExtends(app()->getRootPath() . 'extend');

        if (empty($data)) {
            $data = ['empty'];
        }

        cache('tpext_extend_extensions', $data);

        return $data;
    }

    /**
     * Undocumented function
     *
     * @param string $url
     * @param int $install 安装：1，升级：2
     * @return boolean
     */
    public function download($url, $install = 1)
    {
        $body = file_get_contents($url);

        $file = time() . '_' . mt_rand(100, 999) . '.zip';

        $dir = app()->getRuntimePath() . 'extend' . DIRECTORY_SEPARATOR;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (file_put_contents($dir . $file, $body)) {
            $installRes = $this->installExtend($dir . $file, $install);
            @unlink($dir . $file);
            return $installRes;
        }

        return false;
    }

    /**
     * Undocumented function
     *
     * @param string $zipFilePath
     * @param int $install 安装：1，升级：2
     * @return boolean
     */
    public function installExtend($zipFilePath, $install)
    {
        if (!preg_match('/.+?\.zip$/i', $zipFilePath)) {
            trace('不是zip文件：' . $zipFilePath);
            $this->errors[] = '不是zip文件：' . $zipFilePath;
            return false;
        }

        try {
            $zip = new \ZipArchive();

            if ($zip->open($zipFilePath) === true) {
                $extendName = $zip->getNameIndex(0);
                if (!preg_match('/^\w+\/$/', $extendName)) {
                    trace('压缩包格式有误，外层至少有一层目录');
                    $this->errors[] = '压缩包格式有误，外层至少有一层目录';

                    return false;
                }

                $dir = app()->getRootPath() . 'extend' . DIRECTORY_SEPARATOR;

                if (!file_exists($dir)) {
                    mkdir($dir, 0775, true);
                }

                $extendPath = $dir . $extendName;

                if ($install == 1 && is_dir($extendPath)) {
                    trace('扩展目录已存在：/extend/' . $extendName . '，可能是扩展重复，或不同的两个扩展目录冲突');
                    $this->errors[] = '扩展目录已存在：/extend/' . $extendName . '，可能是扩展重复，或不同的两个扩展目录冲突';

                    return false;
                }

                if ($install == 2 && is_dir($extendPath)) { //更新已存在的扩展，备份
                    $zipBackup = new \ZipArchive();
                    $zipBackup->open($dir . rtrim($extendName, '/') . '_bak' . date('YmdHis') . '.zip', \ZipArchive::CREATE);
                    $this->addExtendsFilesToZip($zipBackup, $extendPath, $extendName);
                    $zipBackup->close();
                }

                $res =  $zip->extractTo($dir);
                $zip->close();
                return $res;
            } else {
                trace('打开zip文件失败！' . $zipFilePath);
                $this->errors[] = '打开zip文件失败！' . $zipFilePath;
                return false;
            }
        } catch (\Throwable $e) {
            trace($e->__toString());
            $this->errors[] = '系统错误-' . $e->getMessage();
            return false;
        }
    }

    /**
     * Undocumented function
     *
     * @param \ZipArchive $zipHandler
     * @param string $path
     * @return void
     */
    protected function addExtendsFilesToZip($zipHandler, $path, $sub_dir = '')
    {
        if (!is_dir($path)) {
            return [];
        }

        $dir = opendir($path);

        $sonDir = null;

        while (false !== ($file = readdir($dir))) {

            if (($file != '.') && ($file != '..') && ($file != '.git')) {

                $sonDir = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($sonDir)) {
                    $this->addExtendsFilesToZip($zipHandler, $sonDir, $sub_dir . $file . '/');
                } else {

                    $zipHandler->addFile($sonDir, $sub_dir . $file);  //向压缩包中添加文件
                }
            }
        }
        closedir($dir);
        unset($sonDir);
    }

    /**
     * Undocumented function
     *
     * @param string $path
     * @param array $extends
     * @return array
     */
    protected function scanExtends($path, $extends = [])
    {
        if (!is_dir($path)) {
            return [];
        }

        $dir = opendir($path);

        $reflectionClass = null;

        $sonDir = null;

        while (false !== ($file = readdir($dir))) {

            if (($file != '.') && ($file != '..') && ($file != '.git')) {

                $sonDir = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($sonDir)) {
                    $extends = array_merge($extends, $this->scanExtends($sonDir));
                } else {

                    if (preg_match('/.+?\\\extend\\\(.+?)\.php$/i', str_replace('/', '\\', $sonDir), $mtches)) {

                        $content = file_get_contents($sonDir); //通过文件内容判断是否为扩展。class_exists方式的$autoload有点问题

                        if (
                            preg_match('/is_tpext_extension/i', $content) //在扩展中加个注释表明是扩展。如下：
                            //is_tpext_extension
                            /*is_tpext_extension*/
                            ||
                            (preg_match('/\$version\s*=/i', $content)
                                && preg_match('/\$name\s*=/i', $content)
                                && preg_match('/\$title\s*=/i', $content)
                                && preg_match('/\$description\s*=/i', $content)
                                && preg_match('/\$root\s*=/i', $content)
                            )
                        ) {
                            $extends[] = $mtches[1];
                        }
                    }
                }
            }
        }

        closedir($dir);

        unset($reflectionClass, $sonDir);

        return $extends;
    }
}

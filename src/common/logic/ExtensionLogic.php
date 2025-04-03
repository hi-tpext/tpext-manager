<?php

namespace tpext\manager\common\logic;

use tpext\think\App;
use think\facade\Cache;
use tpext\common\Tool;

class ExtensionLogic
{
    protected $errors = [];

    protected $remoteUrl = 'https://www.gitlink.org.cn/attachments/entries/get_file?download_url=https://www.gitlink.org.cn/api/hi-tpext/extensions/raw/extensions.json?ref=main';

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
        $dir = App::getRuntimePath() . 'extend' . DIRECTORY_SEPARATOR;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $jsonFile = $dir . 'extension.json';

        $data = '';
        if (is_file($jsonFile) && time() - filemtime($jsonFile) <  60 * 60) {
            $data = file_get_contents($jsonFile);
        } else {
            $data = $this->getRemoteFile($this->remoteUrl);
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
        $data = Cache::get('tpext_extend_extensions');

        if (!$reget && $data) {
            return $data;
        }

        $data = $this->scanExtends(App::getRootPath() . 'extend');

        if (empty($data)) {
            $data = ['empty'];
        }

        Cache::set('tpext_extend_extensions', $data);

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
        $body = $this->getRemoteFile($url);

        $file = time() . '_' . mt_rand(100, 999) . '.zip';

        $dir = App::getRuntimePath() . 'extend' . DIRECTORY_SEPARATOR;

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
                $extendName = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $extendName);
                $numFiles = $zip->numFiles;
                $basepath = ''; //基础目录信息，设置后可以让多模块共用一个位置

                for ($i = 0; $i < $numFiles; $i += 1) {
                    if (basename($zip->getNameIndex($i)) == 'basepath.txt') {
                        $basepath = trim($zip->getFromIndex($i));
                        break;
                    }
                }

                if ($basepath) {
                    $basepath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $basepath);
                    $basepath = trim($basepath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                }

                $dir = App::getRootPath() . 'extend' . DIRECTORY_SEPARATOR . $basepath;

                if (!file_exists($dir)) {
                    mkdir($dir, 0775, true);
                }

                $extendPath = $dir . $extendName;

                if ($install == 1 && is_dir($extendPath)) {
                    trace('扩展目录已存在：extend' . DIRECTORY_SEPARATOR . $basepath . $extendName . '，可能是扩展重复，或不同的两个扩展目录冲突');
                    $this->errors[] = '扩展目录已存在：extend' . DIRECTORY_SEPARATOR . $basepath . $extendName . '，可能是扩展重复，或不同的两个扩展目录冲突';

                    return false;
                }

                if ($install == 2 && is_dir($extendPath)) { //更新已存在的扩展，备份
                    $zipBackup = new \ZipArchive();
                    $zipBackup->open(rtrim($extendPath, DIRECTORY_SEPARATOR) . '_bak' . date('YmdHis') . '.zip', \ZipArchive::CREATE);
                    $this->addExtendsFilesToZip($zipBackup, $extendPath, $extendName);
                    $zipBackup->close();
                    Tool::deleteDir($extendPath);
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
     * @param string $subPath
     * @return void
     */
    protected function addExtendsFilesToZip($zipHandler, $path, $subPath = '')
    {
        if (!is_dir($path)) {
            return;
        }

        $dir = opendir($path);

        $sonDir = null;

        while (false !== ($file = readdir($dir))) {

            if (($file != '.') && ($file != '..') && ($file != '.git')) {

                $sonDir = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($sonDir)) {
                    $this->addExtendsFilesToZip($zipHandler, $sonDir, $subPath . $file . '/');
                } else {

                    $zipHandler->addFile($sonDir, $subPath . $file);  //向压缩包中添加文件
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

            if (!in_array($file, ['.', '..', '.git', '.svn', '.idea', '.vscode', '.DS_Store', 'assets', 'controller'])) {

                $sonDir = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($sonDir)) {
                    $extends = array_merge($extends, $this->scanExtends($sonDir));
                } else if (strstr($file, '.php')) {

                    if (preg_match('/.+?\\\extend\\\(.+?)\.php$/i', str_replace('/', '\\', $sonDir), $mtches)) {
                        //通过文件内容判断是否为扩展。class_exists方式的$autoload有点问题
                        $content = file_get_contents($sonDir);
                        if (
                            //在扩展中加个注释表明是扩展。如下：
                            /*tpext_extension*/
                            //或
                            /*is_tpext_extension*/
                            preg_match('/is_tpext_extension/i', $content)
                            || (preg_match('/\$version\s*=/i', $content)
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

    public function getRemoteFile($url)
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: zh-CN,en-US;q=0.7,en;q=0.3',
            'Connection: keep-alive',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/99.0 Chrome/99.0',
            'Referer: ' . preg_replace('/^(https?:\/\/[^\/]+).*$/i', '$1', $url) . '/',
            'Host: ' . preg_replace('/^https?:\/\/([^\/]+).*$/i', '$1', $url),
        ];

        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 300 // 超时时间（单位:s）
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];

        $conetnt = file_get_contents($url, false, stream_context_create($contextOptions));

        return $conetnt;
    }
}

<?php

namespace tpext\manager\common\logic;

use think\facade\Db;
use tpext\think\App;
use tpext\common\Tool;
use think\facade\Config;
use tpext\common\ExtLoader;
use Webman\Config as WConfig;

class DbBackupLogic
{
    protected $lines = [];
    protected $dbConfig = [];
    protected $errors = [];

    protected $filename = '';

    protected $isPgsql = false;

    protected $dbLogic = null;

    public function __construct()
    {
        if (ExtLoader::isWebman()) {
            $this->dbConfig = WConfig::get('thinkorm.connections.' . WConfig::get('thinkorm.default'), WConfig::get('think-orm.connections.' . WConfig::get('think-orm.default'), []));
        } else {
            $this->dbConfig = Config::get('database.connections.' . Config::get('database.default'), []);
        }

        $dbType = strtolower($this->dbConfig['type'] ?? 'mysql');
        $this->isPgsql = ($dbType === 'pgsql' || $dbType === 'postgresql');
        $this->dbLogic = DbLogic::create();
    }

    protected function quoteName($name)
    {
        return $this->dbLogic->quoteIdentifier($name);
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Undocumented function
     *
     * @param string $table
     * @param string $filename
     * @param int $start
     * @param int $size
     * @return array
     */
    public function backupTable($table, $path, $filename = '', $start = 0, $size = 1000)
    {
        if (empty($filename)) {
            $path = App::getRuntimePath() . $path;

            if (!is_dir($path)) {
                trace($path);
                mkdir($path, 0755, true);
            }
            $filename = $path . $table . '.sql';
        }
        $this->filename = $filename;
        return $this->backup($table, $start, $size);
    }

    public function compressDir($path, $filename)
    {
        $path = App::getRuntimePath() . $path;
        $filename = App::getRuntimePath() . $filename;
        $zipBackup = new \ZipArchive();
        $zipBackup->open($filename, \ZipArchive::CREATE);
        $this->addExtendsFilesToZip($zipBackup, $path);
        $zipBackup->close();
        Tool::deleteDir($path);
    }

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
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public function init()
    {
        $this->lines[] = '-- | -----------------------------';
        $this->lines[] = '-- |';
        $this->lines[] = '-- | Host     : ' . $this->dbConfig['hostname'];
        $this->lines[] = '-- | Port     : ' . $this->dbConfig['hostport'];
        $this->lines[] = '-- | Database : ' . $this->dbConfig['database'];
        $this->lines[] = '-- | Time     : ' . date("Y-m-d H:i:s");
        $this->lines[] = '-- | -----------------------------';
        if (!$this->isPgsql) {
            $this->lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        }
    }

    /**
     * Undocumented function
     *
     * @param string $table
     * @param int $start
     * @param int $size
     * @return array
     */
    protected function backup($table, $start, $size)
    {
        if ($start == 0) {
            $this->init();
            $createSql = $this->dbLogic->getCreateTableSql($table);
            $this->lines[] = '';
            $this->lines[] = '-- | -----------------------------';
            $this->lines[] = 'DROP TABLE IF EXISTS ' . $this->quoteName($table) . ';';
            $this->lines[] = '-- | -----------------------------';
            $this->lines[] = '';
            $this->lines[] = "-- | Table structure for `{$table}`";
            $this->lines[] = $createSql;
            $this->lines[] = '';
            $this->lines[] = '-- | -----------------------------';
            $this->lines[] = "-- | Data of table `{$table}`";
            $this->lines[] = '-- | -----------------------------';
            $this->lines[] = '';
            $this->flush();
        }

        $total = Db::table($table)->count();

        if ($total > 0) {
            $pk = $this->getPk($table);
            $list =  Db::table($table)->limit($start, $size)->order($pk)->cursor();
            $i = 0;
            $fields = [];
            foreach ($list as $row) {
                $i += 1;
                if ($i == 1) {
                    $fields = array_keys($row);
                }
                $row = array_map('addslashes', $row);
                $this->lines[] = "('" . str_replace(["\r", "\n"], ['\r', '\n'], implode("', '", $row)) . "')";
                if (count($this->lines) >= 100) {
                    $quotedFields = array_map(function ($f) {
                        return $this->quoteName($f);
                    }, $fields);
                    $this->flush(PHP_EOL . "INSERT INTO " . $this->quoteName($table) . " (" . implode(", ", $quotedFields) . ") VALUES " . PHP_EOL, ',' . PHP_EOL, ';');
                }
            }

            if (count($this->lines) > 0) {
                $quotedFields = array_map(function ($f) {
                    return $this->quoteName($f);
                }, $fields);
                $this->flush(PHP_EOL . "INSERT INTO " . $this->quoteName($table) . " (" . implode(", ", $quotedFields) . ") VALUES " . PHP_EOL, ',' . PHP_EOL, ';');
            }

            return [$start + $i, $total, $i == 0];
        } else {
            $this->lines[] = "-- | {$table} is empty";
            $this->flush();
            return [0, 0, true];
        }
    }

    /**
     * Undocumented function
     *
     * @param string $befor
     * @param string $after
     */
    protected function flush($befor = '', $separator = '', $after = '')
    {
        if (count($this->lines) == 0) {
            return;
        }
        file_put_contents($this->filename, $befor . implode($separator ?: PHP_EOL, $this->lines) . $after, FILE_APPEND | LOCK_EX);
        $this->lines = [];
    }

    protected function getPk($table)
    {
        $pk = Db::table($table)->getPk();
        if (!empty($pk) && is_string($pk)) {
            return $pk;
        }

        $createTime = Db::query("select * from information_schema.columns where table_schema='{$this->dbConfig['database']}' AND table_name='{$table}' AND column_name='create_time'");

        return  $createTime ? 'create_time' : '';
    }
}

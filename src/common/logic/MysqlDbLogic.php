<?php

namespace tpext\manager\common\logic;

use think\facade\Db;
use \think\facade\Log;

class MysqlDbLogic extends AbstractDbLogic
{
    public static $FIELD_TYPES = [
        'tinyint' => 'Tinyint',
        'smallint' => 'Smallint',
        'mediumint' => 'Mediumint',
        'int' => 'Int',
        'bigint' => 'Bigint',
        'decimal' => 'Decimal(n,d)',
        'float' => 'Float(n,d)',
        'double' => 'Double(n,d)',
        'boolean' => 'Boolean',
        'date' => 'Date',
        'datetime' => 'Datetime(n)',
        'timestamp' => 'Timestamp(n)',
        'time' => 'Time(n)',
        'year' => 'Year',
        'char' => 'Char(n)',
        'varchar' => 'Varchar(n)',
        'tinytext' => 'Tinytext',
        'text' => 'Text',
        'mediumtext' => 'Mediumtext',
        'longtext' => 'Longtext',
        'json' => 'Json',
    ];

    /**
     * @param string $columns
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    public function getTables($columns = '*', $where = '', $sortOrder = 'TABLE_NAME ASC')
    {
        if ($where && !preg_match('/\s*and/i', $where)) {
            $where = 'AND ' . $where;
        }
        if (!$sortOrder) {
            $sortOrder = 'TABLE_NAME ASC';
        }
        $tables = Db::query("select {$columns} from information_schema.tables where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_TYPE`='BASE TABLE' AND `TABLE_NAME` NOT LIKE '%_del_at_%' {$where} ORDER BY {$sortOrder}");

        return $tables;
    }

    /**
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    public function getDeletedTables($where = '', $sortOrder = 'TABLE_NAME ASC')
    {
        if ($where && !preg_match('/\s*and/i', $where)) {
            $where = 'AND ' . $where;
        }
        if (!$sortOrder) {
            $sortOrder = 'TABLE_NAME ASC';
        }
        $tables = Db::query("select TABLE_NAME,TABLE_COMMENT from information_schema.tables where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_TYPE`='BASE TABLE' AND `TABLE_NAME` LIKE '%_del_at_%' {$where} ORDER BY {$sortOrder}");

        return $tables;
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @return array|null
     */
    public function getTableInfo($tableName, $columns = '*')
    {
        $tables = Db::query("select {$columns} from information_schema.tables where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_TYPE`='BASE TABLE' AND `TABLE_NAME`='{$tableName}'");

        return count($tables) ? $tables[0] : null;
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    public function getFields($tableName, $columns = '*', $where = '', $sortOrder = 'ORDINAL_POSITION ASC')
    {
        if ($where && !preg_match('/\s*and/i', $where)) {
            $where = 'AND ' . $where;
        }
        if (!$sortOrder) {
            $sortOrder = 'ORDINAL_POSITION ASC';
        }
        $fields = Db::query("select {$columns} from information_schema.columns where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_NAME`='{$tableName}' AND `COLUMN_NAME` NOT LIKE '%_del_at_%' {$where} ORDER BY {$sortOrder}");

        return $fields;
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @param string $columns
     * @return array
     */
    public function getFieldInfo($tableName, $fieldName, $columns = '*')
    {
        $fields = Db::query("select {$columns} from information_schema.columns where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_NAME`='{$tableName}' AND `COLUMN_NAME`='{$fieldName}'");

        return count($fields) ? $fields[0] : null;
    }

    /**
     * @param string $tableName
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    public function getDeletedFields($tableName, $where = '', $sortOrder = 'ORDINAL_POSITION ASC')
    {
        if ($where && !preg_match('/\s*and/i', $where)) {
            $where = 'AND ' . $where;
        }
        if (!$sortOrder) {
            $sortOrder = 'ORDINAL_POSITION ASC';
        }
        $fields = Db::query("select COLUMN_NAME,COLUMN_COMMENT from information_schema.columns where `TABLE_SCHEMA`='{$this->database}' AND `TABLE_NAME`='{$tableName}' AND `COLUMN_NAME` LIKE '%_del_at_%' {$where} ORDER BY {$sortOrder}");

        return $fields;
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return array
     */
    public function getKeys($tableName, $fieldName)
    {
        $keys = Db::query("SELECT s.NON_UNIQUE, s.INDEX_NAME FROM information_schema.statistics s WHERE s.`TABLE_SCHEMA`='{$this->database}' AND s.`TABLE_NAME`='{$tableName}' AND s.`COLUMN_NAME`='{$fieldName}' AND NOT EXISTS (SELECT 1 FROM information_schema.statistics s2 WHERE s2.`TABLE_SCHEMA` = s.`TABLE_SCHEMA` AND s2.`TABLE_NAME` = s.`TABLE_NAME` AND s2.`INDEX_NAME` = s.`INDEX_NAME` AND s2.`COLUMN_NAME` != s.`COLUMN_NAME`)");
        return $keys;
    }

    /**
     * @param string $tableName
     * @param array $data
     * @return boolean
     */
    public function createTable($tableName, $data)
    {
        $tableInfo = $this->getTableInfo($tableName, 'TABLE_NAME');

        if ($tableInfo) {
            $this->errors[] = __admin_lang('msg_table_name_exists');
            return false;
        }

        $pkinfo = isset($data['fields']) ? $data['fields']['pk'] : [];
        $create_time = isset($data['fields']) ? $data['fields']['create_time'] : [];
        $update_time = isset($data['fields']) ? $data['fields']['update_time'] : [];

        if (empty($pkinfo)) {
            $pkinfo = [
                'COLUMN_NAME' => 'id',
                'COLUMN_COMMENT' => __admin_lang('label_pk'),
                'DATA_TYPE' => 'int',
                'LENGTH' => '10',
                'ATTR' =>
                    [
                        'auto_inc',
                        'unsigned',
                    ],
            ];
        }

        $pkinfo['IS_NULLABLE'] = 'NO';
        $pkinfo['COLUMN_DEFAULT'] = '';
        $pkinfo['ATTR'][] = 'primary';
        $attr = $this->buildFieldAttr($pkinfo);

        $create_time_column = '';
        $update_time_column = '';

        if (!empty($create_time)) {
            if (!isset($create_time['__del__']) || $create_time['__del__'] == 0) {
                $create_time_attr = $this->buildFieldAttr($create_time);
                $create_time_column = ",
                `{$create_time['COLUMN_NAME']}` {$create_time_attr} COMMENT '{$create_time['COLUMN_COMMENT']}'";
            }
        }

        if (!empty($update_time)) {
            if (!isset($update_time['__del__']) || $update_time['__del__'] == 0) {
                $update_time_attr = $this->buildFieldAttr($update_time);
                $update_time_column = ",
                `{$update_time['COLUMN_NAME']}` {$update_time_attr} COMMENT '{$update_time['COLUMN_COMMENT']}'";
            }
        }

        $charset = preg_match('/^[1-5]\.[0-5]/', $this->getVersion()) ? 'utf8' : 'utf8mb4';

        $sql = "CREATE TABLE IF NOT EXISTS `$tableName`(
            `{$pkinfo['COLUMN_NAME']}` {$attr} primary key COMMENT '{$pkinfo['COLUMN_COMMENT']}'{$create_time_column}{$update_time_column}
            )ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET={$charset} COMMENT='{$data['TABLE_COMMENT']}'";
        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        return true;
    }

    public function getVersion()
    {
        return Db::query("SELECT VERSION() AS ver")[0]['ver'];
    }

    /**
     * @param string $tableName
     * @param array $info
     * @return boolean
     */
    public function addField($tableName, $info)
    {
        if (($this->isInteger($info['DATA_TYPE']) || $this->isDecimal($info['DATA_TYPE'])) && !in_array('unsigned', $info['ATTR'])) {
            $info['ATTR'][] = 'unsigned';
        }

        $attr = $this->buildFieldAttr($info);

        $sqls = [];
        $sql = '';

        try {

            $after = empty($info['MOVE_AFTER']) || $info['MOVE_AFTER'] == $info['COLUMN_NAME'] ? '' : " after `{$info['MOVE_AFTER']}`";
            $sql = "ALTER TABLE `{$tableName}` add `{$info['COLUMN_NAME']}` $attr COMMENT '{$info['COLUMN_COMMENT']}'$after";
            $sqls[] = $sql;
            Db::execute($sql);

            if (in_array('index', $info['ATTR'])) {
                $sql = "ALTER TABLE `{$tableName}` add  INDEX `idx_{$info['COLUMN_NAME']}` (`{$info['COLUMN_NAME']}`)";
                $sqls[] = $sql;
                Db::execute($sql);
            }
            if (in_array('unique', $info['ATTR'])) {
                $sql = "ALTER TABLE `{$tableName}` add  UNIQUE `unq_{$info['COLUMN_NAME']}` (`{$info['COLUMN_NAME']}`)";
                $sqls[] = $sql;
                Db::execute($sql);
            }

            return true;
        } catch (\Exception $ex) {

            foreach ($sqls as $s) {
                Log::info($s);
            }

            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @param array $info
     * @return boolean
     */
    public function changeField($tableName, $fieldName, $info)
    {
        $keys = $this->getKeys($tableName, $fieldName);

        $primary = '';
        $index_key = '';
        $unique_key = '';

        foreach ($keys as $key) {
            if (strtoupper($key['INDEX_NAME']) == 'PRIMARY') {
                $info['ATTR'][] = 'primary';
                $info['ATTR'][] = 'auto_inc';
                $primary = $key['INDEX_NAME'];
                continue;
            }
            if ($key['NON_UNIQUE'] == 1) {
                $index_key = $key['INDEX_NAME'];
            } else {
                $unique_key = $key['INDEX_NAME'];
            }
        }

        $attr = $this->buildFieldAttr($info);

        $sqls = [];
        $sql = '';

        try {

            $after = empty($info['MOVE_AFTER']) || $info['MOVE_AFTER'] == $info['COLUMN_NAME'] ? '' : " after `{$info['MOVE_AFTER']}`";
            if ($fieldName == $info['COLUMN_NAME']) {
                $sql = "ALTER TABLE `{$tableName}` modify `{$info['COLUMN_NAME']}` $attr COMMENT '{$info['COLUMN_COMMENT']}'$after";
                $sqls[] = $sql;
                Db::execute($sql);
            } else {
                $sql = "ALTER TABLE `{$tableName}` change `{$fieldName}` `{$info['COLUMN_NAME']}` $attr COMMENT '{$info['COLUMN_COMMENT']}'$after";
                $sqls[] = $sql;
                Db::execute($sql);
            }

            if (in_array('index', $info['ATTR'])) {
                if (!$primary && !$index_key) {
                    $sql = "ALTER TABLE `{$tableName}` add  INDEX `idx_{$info['COLUMN_NAME']}` (`{$info['COLUMN_NAME']}`)";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            } else {
                if ($index_key) {
                    $sql = "ALTER TABLE `{$tableName}` drop  INDEX `{$index_key}`";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            }
            if (in_array('unique', $info['ATTR'])) {
                if (!$unique_key) {
                    $sql = "ALTER TABLE `{$tableName}` add  UNIQUE `unq_{$info['COLUMN_NAME']}` (`{$info['COLUMN_NAME']}`)";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            } else {
                if ($unique_key) {
                    $sql = "ALTER TABLE `{$tableName}` drop  INDEX `{$unique_key}`";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            }

            return true;
        } catch (\Exception $ex) {

            foreach ($sqls as $s) {
                Log::info($s);
            }

            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }
    }

    /**
     * @param array $info
     * @return string
     */
    public function buildFieldAttr($info)
    {
        if (!isset($info['ATTR'])) {
            $info['ATTR'] = [];
        }

        if (!isset($info['IS_NULLABLE'])) {
            $info['IS_NULLABLE'] = 0;
        }

        if (!isset($info['COLUMN_DEFAULT'])) {
            $info['COLUMN_DEFAULT'] = '';
        }

        if (!isset($info['LENGTH'])) {
            $info['LENGTH'] = '';
        }

        if (!isset($info['NUMERIC_SCALE'])) {
            $info['NUMERIC_SCALE'] = '';
        }

        $type = $info['DATA_TYPE'];

        $isInteger = $this->isInteger($type);
        $isDecimal = $this->isDecimal($type);
        $isDatetime = $this->isDatetime($type);
        $isChartext = $this->isChartext($type);
        $isText = $this->isText($type);

        $length = $info['LENGTH'];
        $unsigned = in_array('unsigned', $info['ATTR']) && ($isInteger || $isDecimal) ? 'unsigned' : '';
        $not_null = $info['IS_NULLABLE'] == 1 ? '' : 'NOT NULL';
        $default = trim($info['COLUMN_DEFAULT'], "'");
        $auto_inc = $isInteger && $type != 'boolean' && in_array('auto_inc', $info['ATTR']) ? 'AUTO_INCREMENT' : '';

        if (empty($length) || !is_numeric($length)) {
            if ($isInteger) {
                $Length = [
                    'tinyint' => 3,
                    'smallint' => 5,
                    'mediumint' => 8,
                    'int' => 10,
                    'bigint' => 20,
                ];
                $length = isset($Length[$type]) ? $Length[$type] : 10;
            } else if ($isDecimal) {
                $length = 10;
            } else if ($isChartext) {
                $length = 55;
            }
        }

        if ($isInteger || $isChartext) {
            $length = "({$length})";
        } else if ($isDecimal) {
            if (empty($info['NUMERIC_SCALE']) || !is_numeric($info['NUMERIC_SCALE'])) {
                $info['NUMERIC_SCALE'] = 2;
            }
            $length = "({$length},{$info['NUMERIC_SCALE']})";
        } else if ($isDatetime) {
            $length = is_numeric($length) && $length > 0 ? "({$length})" : '';
        } else if ($isText) {
            $length = '';
        }

        if ($type == 'boolean') {
            $type = 'tinyint';
            $length = '(1)';
            $unsigned = 'unsigned';
        } else if ($type == 'year') {

            $length = '(4)';
        }

        if ($not_null) {

            if (strtoupper($default) == 'NULL') {
                $default = '';
            }

            if ($isInteger || $isDecimal) {
                if (!is_numeric($default)) {
                    $default = '0';
                }
            } else if ($isDatetime) {
                if (empty($default)) {
                    if ($type == 'timestamp') {
                        $default = date('Y-m-d H:i:s', date('Z'));
                    } else {
                        $default = date('Y-m-d H:i:s', 0);
                    }
                }
            }
        } else {
            if ($info['COLUMN_NAME'] == 'delete_time') {
                $default = 'NULL';
            } else if ($type == 'timestamp') {
                $not_null = 'NOT NULL';
                if (empty($default)) {
                    $default = date('Y-m-d H:i:s', date('Z'));
                }
            } else if ($isDatetime && empty($default)) {
                $default = 'NULL';
            }
        }

        if (strtoupper($default) == 'NULL') {

            $default = 'DEFAULT NULL';
        } else if ($isDatetime) {
            if ((strtoupper($default) == 'CURRENT_TIMESTAMP' || strtoupper($default) == 'CURRENT_TIMESTAMP()')) {
                $default = "DEFAULT CURRENT_TIMESTAMP";
            } else {
                if ($type == 'year') {
                    $default = is_numeric($default) && $default >= 0 ? $default : date('Y');
                } else if ($type == 'date') {
                    $default = date('Y-m-d', strtotime($default));
                } else if ($type == 'time') {
                    $default = date('H:i:s', strtotime(date('Y-m-d') . ' ' . $default));
                } else {
                    $default = date('Y-m-d H:i:s', strtotime($default));
                }

                $default = "DEFAULT '{$default}'";
            }
        } else if ($isInteger || $isDecimal) {

            if ($unsigned) {
                $default = is_numeric($default) && $default >= 0 ? $default : 0;
            } else {
                $default = is_numeric($default) ? $default : 0;
            }

            $default = "DEFAULT '{$default}'";
        } else {
            $default = "DEFAULT '{$default}'";
        }

        if (in_array('primary', $info['ATTR']) || $auto_inc || $isText) {
            $default = '';
        }

        $attr = "{$type}{$length} {$unsigned} {$not_null} {$default} {$auto_inc}";

        return $attr;
    }

    /**
     * @param string $tableName
     * @return boolean
     */
    public function dropTable($tableName)
    {
        $sql = "DROP TABLE IF EXISTS `{$tableName}`;";

        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        return true;
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return boolean
     */
    public function dropField($tableName, $fieldName)
    {
        $sql = "ALTER TABLE `{$tableName}` drop COLUMN `{$fieldName}`;";

        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        return true;
    }

    /**
     * @param string $tableName
     * @param string $comment
     * @return boolean
     */
    public function changeComment($tableName, $comment)
    {
        $tableInfo = $this->getTableInfo($tableName, 'TABLE_COMMENT');

        if (!$tableInfo) {
            return false;
        }

        if ($tableInfo['TABLE_COMMENT'] == $comment) {
            return false;
        }

        $sql = "ALTER TABLE `{$tableName}` COMMENT '{$comment}'";

        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        return true;
    }

    public function changeTableName($tableName, $new_name)
    {
        if ($tableName == $new_name) {
            return false;
        }

        $sql = "ALTER TABLE `{$tableName}` RENAME TO `{$new_name}`";

        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        return true;
    }

    public function getOptimizeSql($tableName)
    {
        $table = $this->getTableInfo($tableName);
        $engine = $table['ENGINE'] ?? 'InnoDB';

        $sql = $engine == 'MyISAM' ? "OPTIMIZE TABLE `{$tableName}`" : "ALTER TABLE `{$tableName}` ENGINE=InnoDB";
        if ($engine == 'InnoDB') {
            $sql .= ";\nANALYZE TABLE `{$tableName}`";
        }

        return $sql;
    }

    public function optimizeTable($tableName)
    {
        $table = $this->getTableInfo($tableName);
        $engine = $table['ENGINE'] ?? 'InnoDB';

        $sql = $engine == 'MyISAM' ? "OPTIMIZE TABLE `{$tableName}`" : "ALTER TABLE `{$tableName}` ENGINE=InnoDB";

        try {
            Db::execute($sql);
            if ($engine == 'InnoDB') {
                Db::execute("ANALYZE TABLE `{$tableName}`");
            }
            return true;
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }
    }

    public function getCreateTableSql($tableName)
    {
        $tableInfo = Db::query("SHOW CREATE TABLE `{$tableName}`");
        return !empty($tableInfo) ? $tableInfo[0]['Create Table'] . ';' : '';
    }

    public function isInteger($fieldType)
    {
        return in_array($fieldType, [
            'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'boolean',
        ]);
    }

    public function isDecimal($fieldType)
    {
        return in_array($fieldType, [
            'decimal', 'float', 'double',
        ]);
    }

    public function isDatetime($fieldType)
    {
        return in_array($fieldType, [
            'date', 'datetime', 'timestamp', 'time', 'year',
        ]);
    }

    public function isChartext($fieldType)
    {
        return in_array($fieldType, [
            'varchar', 'char',
        ]);
    }

    public function isText($fieldType)
    {
        return in_array($fieldType, [
            'tinytext', 'text', 'mediumtext', 'longtext', 'json',
        ]);
    }
}

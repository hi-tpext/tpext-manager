<?php

namespace tpext\manager\common\logic;

use think\facade\Db;
use \think\facade\Log;

abstract class AbstractDbLogic
{
    protected $database = '';

    protected $prefix = '';

    protected $errors = [];

    protected $config = [];

    protected $dbType = '';

    public function __construct()
    {
        $driver = Db::getConfig('default', 'mysql');

        $connections = Db::getConfig('connections');

        $this->config = $connections[$driver] ?? [];

        if (empty($this->config) || empty($this->config['database'])) {
            return;
        }

        $this->database = $this->config['database'];
        $this->prefix = $this->config['prefix'];
        $this->dbType = $this->config['type'];
    }

    /**
     * @return string
     */
    public function getDbType()
    {
        return $this->dbType;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Undocumented function
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getDatabase()
    {
        return $this->database;
    }

    public function getErrorsText()
    {
        return implode('<br>', $this->errors);
    }

    /**
     * @param string $columns
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    abstract public function getTables($columns = '*', $where = '', $sortOrder = 'TABLE_NAME ASC');

    /**
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    abstract public function getDeletedTables($where = '', $sortOrder = 'TABLE_NAME ASC');

    /**
     * @param string $tableName
     * @param string $columns
     * @return array|null
     */
    abstract public function getTableInfo($tableName, $columns = '*');

    /**
     * @param string $tableName
     * @param string $columns
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    abstract public function getFields($tableName, $columns = '*', $where = '', $sortOrder = 'ORDINAL_POSITION ASC');

    /**
     * @param string $tableName
     * @param string $fieldName
     * @param string $columns
     * @return array
     */
    abstract public function getFieldInfo($tableName, $fieldName, $columns = '*');

    /**
     * @param string $tableName
     * @param string $where
     * @param string $sortOrder
     * @return array
     */
    abstract public function getDeletedFields($tableName, $where = '', $sortOrder = 'ORDINAL_POSITION ASC');

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return array
     */
    abstract public function getKeys($tableName, $fieldName);

    /**
     * @param string $tableName
     * @param array $data
     * @return boolean
     */
    public function updateTable($tableName, $data)
    {
        $tableInfo = $this->getTableInfo($tableName, 'TABLE_NAME,TABLE_COMMENT');

        if (!$tableInfo) {
            $this->errors[] = __admin_lang('msg_table_not_exists');
            return false;
        }

        if (isset($data['TABLE_COMMENT']) && $tableInfo['TABLE_COMMENT'] != $data['TABLE_COMMENT']) {
            if (!$this->changeComment($tableName, $data['TABLE_COMMENT'])) {
                return false;
            }
        }

        if (isset($data['TABLE_NAME']) && $tableInfo['TABLE_NAME'] != $data['TABLE_NAME']) {
            if (!$this->changeTableName($tableName, $data['TABLE_NAME'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $tableName
     * @param array $data
     * @return boolean
     */
    abstract public function createTable($tableName, $data);

    abstract public function getVersion();

    /**
     * @param string $tableName
     * @param array $info
     * @return boolean
     */
    abstract public function addField($tableName, $info);

    /**
     * @param string $tableName
     * @param string $fieldName
     * @param array $info
     * @return boolean
     */
    abstract public function changeField($tableName, $fieldName, $info);

    /**
     * @param array $info
     * @return string
     */
    abstract public function buildFieldAttr($info);

    /**
     * @param string $tableName
     * @return boolean
     */
    abstract public function dropTable($tableName);

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return boolean
     */
    abstract public function dropField($tableName, $fieldName);

    /**
     * @param string $tableName
     * @return boolean
     */
    public function trashTable($tableName)
    {
        return $this->changeTableName($tableName, $tableName . '_del_at_' . time());
    }

    /**
     * @param string $tableName
     * @return boolean
     */
    public function recoveryTable($tableName)
    {
        $arr = explode('_del_at_', $tableName);
        return $this->changeTableName($tableName, $arr[0]);
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return boolean
     */
    public function recoveryField($tableName, $fieldName)
    {
        $arr = explode('_del_at_', $fieldName);

        $field = $this->getFieldInfo($tableName, $fieldName);

        if (!$field) {
            return false;
        }

        $ATTR = [];
        if ($this->hasUnsigned() && strpos($field['COLUMN_TYPE'], 'unsigned')) {
            $ATTR['unsigned'] = 'unsigned';
        }
        $keys = $this->getKeys($tableName, $fieldName);
        foreach ($keys as $key) {
            if (strtoupper($key['INDEX_NAME']) == 'PRIMARY') {
                $ATTR['index'] = 'index';
                continue;
            }

            if ($key['NON_UNIQUE'] == 1) {
                $ATTR['index'] = 'index';
            } else {
                $ATTR['unique'] = 'unique';
            }
        }

        $field['COLUMN_NAME'] = $arr[0];
        $field['ATTR'] = $ATTR;
        $field['IS_NULLABLE'] = 1;

        return $this->changeField($tableName, $fieldName, $field);
    }

    /**
     * @param string $tableName
     * @param string $comment
     * @return boolean
     */
    abstract public function changeComment($tableName, $comment);

    abstract public function changeTableName($tableName, $new_name);

    abstract public function isInteger($fieldType);

    abstract public function isDecimal($fieldType);

    abstract public function isDatetime($fieldType);

    abstract public function isChartext($fieldType);

    abstract public function isText($fieldType);

    public function getDefaultPkType()
    {
        return 'int';
    }

    public function getDefaultDatetimeType()
    {
        return 'datetime';
    }

    /**
     * Get field types for dropdown options.
     */
    public function getFieldTypes()
    {
        return static::$FIELD_TYPES;
    }

    /**
     * Get field attribute options for form checkboxes.
     * Keys: create=新建表时的选项, edit=编辑字段时的选项
     */
    public function getFieldAttrOptions()
    {
        return [
            'create' => ['auto_inc' => __admin_lang('attr_auto_inc'), 'unsigned' => __admin_lang('attr_unsigned')],
            'edit' => ['index' => __admin_lang('attr_index'), 'unique' => __admin_lang('attr_unique'), 'unsigned' => __admin_lang('attr_unsigned')],
        ];
    }

    /**
     * Get default pk field ATTR string for the create-table form preset data.
     */
    public function getDefaultPkAttr()
    {
        return 'auto_inc,unsigned';
    }

    /**
     * Whether this database engine supports column positioning (AFTER clause).
     */
    public function supportsColumnPositioning()
    {
        return true;
    }

    /**
     * Quote an identifier for use in SQL.
     */
    public function quoteIdentifier($name)
    {
        return '`' . $name . '`';
    }

    /**
     * Whether this database engine supports unsigned types.
     */
    public function hasUnsigned()
    {
        return true;
    }

    public function getDataSize($data)
    {
        return round(($data['DATA_LENGTH'] + $data['INDEX_LENGTH']) / 1024 / 1024, 2);
    }

    public function getDataFreeSize($data)
    {
        return round($data['DATA_FREE'] / 1024 / 1024, 2);
    }

    /**
     * 判断是否需要显示优化按钮
     * MySQL: DATA_FREE/DATA_LENGTH > 30% 或碎片 > 100MB
     * PostgreSQL: ANALYZE 轻量无副作用，始终显示
     *
     * @param array $row getTables 返回的一行数据
     * @return boolean
     */
    public function needOptimize($row)
    {
        $rate = $row['DATA_FREE'] / max($row['DATA_LENGTH'], 1);
        return $rate > 0.3 || $row['DATA_FREE'] > 100 * 1024 * 1024;
    }

    public function execute($sql)
    {
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
     * @return boolean
     */
    abstract public function optimizeTable($tableName);

    /**
     * 获取优化表执行的 SQL，用于界面显示
     *
     * @param string $tableName
     * @return string
     */
    abstract public function getOptimizeSql($tableName);

    /**
     * @param string $tableName
     * @return string
     */
    abstract public function getCreateTableSql($tableName);
}

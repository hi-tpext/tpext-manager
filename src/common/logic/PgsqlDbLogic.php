<?php

namespace tpext\manager\common\logic;

use think\facade\Db;
use \think\facade\Log;

class PgsqlDbLogic extends AbstractDbLogic
{
    public static $FIELD_TYPES = [
        'smallint' => 'Smallint (2 bytes)',
        'integer' => 'Integer (4 bytes)',
        'bigint' => 'Bigint (8 bytes)',
        'decimal' => 'Decimal(n,d)',
        'numeric' => 'Numeric(n,d)',
        'real' => 'Real (4 bytes)',
        'double precision' => 'Double precision (8 bytes)',
        'boolean' => 'Boolean',
        'date' => 'Date',
        'timestamp' => 'Timestamp(n)',
        'timestamptz' => 'Timestamptz(n)',
        'time' => 'Time(n)',
        'timetz' => 'Timetz(n)',
        'interval' => 'Interval(n)',
        'char' => 'Char(n)',
        'varchar' => 'Varchar(n)',
        'text' => 'Text',
        'json' => 'JSON',
        'jsonb' => 'JSONB',
        'uuid' => 'UUID',
        'bytea' => 'Bytea',
        'money' => 'Money',
    ];

    protected static $tableColumnMap = [
        'TABLE_CATALOG' => 't.table_catalog',
        'TABLE_NAME' => 't.table_name',
        'TABLE_ROWS' => 'GREATEST(COALESCE(pgc.reltuples::bigint, 0), 0)',
        'CREATE_TIME' => "COALESCE(to_char(
            (SELECT GREATEST(psut.last_analyze, psut.last_autoanalyze, psut.last_vacuum, psut.last_autovacuum)
             FROM pg_stat_user_tables psut
             WHERE psut.relname = t.table_name AND psut.schemaname = 'public'),
            'YYYY-MM-DD HH24:MI:SS'), '')",
        'TABLE_COLLATION' => "(SELECT datcollate FROM pg_catalog.pg_database WHERE datname = current_database())",
        'TABLE_COMMENT' => "COALESCE(cast(obj_description(pgc.oid, 'pg_class') as text), '')",
        'ENGINE' => "'PostgreSQL'",
        'AUTO_INCREMENT' => "COALESCE((SELECT ps.last_value::bigint
             FROM pg_catalog.pg_sequences ps
             WHERE ps.schemaname = 'public'
               AND ps.sequencename = t.table_name || '_id_seq'
             LIMIT 1), 0)",
        'AVG_ROW_LENGTH' => '0',
        'DATA_LENGTH' => 'COALESCE(pg_total_relation_size(pgc.oid), 0)',
        'INDEX_LENGTH' => 'COALESCE(pg_indexes_size(pgc.oid), 0)',
        'DATA_FREE' => "COALESCE(
            (SELECT CASE WHEN psut.n_live_tup + psut.n_dead_tup > 0
                THEN (COALESCE(pg_total_relation_size(pgc.oid), 0) * psut.n_dead_tup::float / (psut.n_live_tup + psut.n_dead_tup))::bigint
                ELSE 0 END
             FROM pg_stat_user_tables psut
             WHERE psut.relname = t.table_name AND psut.schemaname = 'public'), 0
        )",
        'TABLE_TYPE' => 't.table_type',
    ];

    protected static $fieldColumnMap = [
        'COLUMN_NAME' => 'c.column_name',
        'DATA_TYPE' => 'c.data_type',
        'COLUMN_TYPE' => "CASE
                        WHEN c.data_type IN ('integer', 'bigint', 'smallint', 'numeric', 'decimal')
                            AND c.numeric_precision IS NOT NULL THEN
                            CASE WHEN c.data_type IN ('numeric', 'decimal') AND c.numeric_scale > 0
                                THEN c.data_type || '(' || c.numeric_precision || ',' || c.numeric_scale || ')'
                                ELSE c.data_type || '(' || c.numeric_precision || ')'
                            END
                        WHEN c.data_type IN ('character varying') AND c.character_maximum_length IS NOT NULL
                            THEN 'varchar(' || c.character_maximum_length || ')'
                        WHEN c.data_type = 'character' AND c.character_maximum_length IS NOT NULL
                            THEN 'char(' || c.character_maximum_length || ')'
                        WHEN c.data_type IN ('timestamp without time zone', 'timestamp with time zone',
                                             'time without time zone', 'time with time zone', 'interval')
                            AND c.datetime_precision IS NOT NULL AND c.datetime_precision > 0 THEN
                            CASE c.data_type
                                WHEN 'timestamp without time zone' THEN 'timestamp'
                                WHEN 'timestamp with time zone' THEN 'timestamptz'
                                WHEN 'time without time zone' THEN 'time'
                                WHEN 'time with time zone' THEN 'timetz'
                                ELSE c.data_type
                            END || '(' || c.datetime_precision || ')'
                        ELSE c.data_type
                    END",
        'COLUMN_DEFAULT' => 'c.column_default',
        'COLUMN_COMMENT' => "COALESCE(pg_catalog.col_description(pgc.oid, c.ordinal_position::int), '')",
        'IS_NULLABLE' => 'c.is_nullable',
        'NUMERIC_SCALE' => 'c.numeric_scale::int',
        'NUMERIC_PRECISION' => 'c.numeric_precision::int',
        'CHARACTER_MAXIMUM_LENGTH' => 'c.character_maximum_length::int',
        'DATETIME_PRECISION' => 'c.datetime_precision::int',
        'ORDINAL_POSITION' => 'c.ordinal_position',
    ];

    /**
     * Normalize information_schema long type names to short form
     * used in $FIELD_TYPES and $fieldColumnMap.
     */
    public static function normalizeType($dataType)
    {
        $map = [
            'character varying' => 'varchar',
            'character' => 'char',
            'timestamp without time zone' => 'timestamp',
            'timestamp with time zone' => 'timestamptz',
            'time without time zone' => 'time',
            'time with time zone' => 'timetz',
        ];
        return $map[$dataType] ?? $dataType;
    }

    /**
     * Build SELECT clause from MySQL-style column names mapped to PG expressions.
     *
     * @param string $columns  e.g. '*' or 'TABLE_NAME,TABLE_COMMENT'
     * @param array  $map      ['MYSQL_NAME' => 'pg_expression']
     * @return string
     */
    protected function buildSelectClause($columns, $map)
    {
        $columns = trim($columns);
        if ($columns === '*') {
            $parts = [];
            foreach ($map as $alias => $expr) {
                $parts[] = "{$expr} AS \"{$alias}\"";
            }
            return implode(",\n                    ", $parts);
        }

        $cols = array_map('trim', explode(',', $columns));
        $parts = [];
        foreach ($cols as $col) {
            $col = trim($col, '`"\' ');
            // 解析 "base AS alias" 语法，将 base 映射为 PG 表达式，保留用户指定的别名
            if (preg_match('/^(.+)\s+as\s+(.+)$/i', $col, $m)) {
                $base = trim($m[1], '`"\' ');
                $userAlias = trim($m[2], '`"\' ');
                $upper = strtoupper($base);
                if (isset($map[$upper])) {
                    $parts[] = "{$map[$upper]} AS \"{$userAlias}\"";
                } elseif (isset($map[$base])) {
                    $parts[] = "{$map[$base]} AS \"{$userAlias}\"";
                }
                // 映射中找不到的跳过
            } else {
                $upper = strtoupper($col);
                if (isset($map[$upper])) {
                    $parts[] = "{$map[$upper]} AS \"{$upper}\"";
                } elseif (isset($map[$col])) {
                    $parts[] = "{$map[$col]} AS \"{$col}\"";
                }
            }
        }
        return implode(",\n                    ", $parts);
    }

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

        $selectClause = $this->buildSelectClause($columns, self::$tableColumnMap);

        if (empty($selectClause)) {
            return [];
        }

        $sql = "SELECT
                    {$selectClause}
                FROM information_schema.tables t
                LEFT JOIN pg_catalog.pg_class pgc ON t.table_name = pgc.relname
                    AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
                WHERE t.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                  AND t.table_catalog = '{$this->database}'
                  AND t.table_name NOT LIKE '%_del_at_%'
                  {$where}
                ORDER BY {$sortOrder}";

        return Db::query($sql);
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

        $sql = "SELECT
                    t.table_name AS \"TABLE_NAME\",
                    COALESCE(cast(obj_description(pgc.oid, 'pg_class') as text), '') AS \"TABLE_COMMENT\"
                FROM information_schema.tables t
                LEFT JOIN pg_catalog.pg_class pgc ON t.table_name = pgc.relname
                    AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
                WHERE t.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                  AND t.table_catalog = '{$this->database}'
                  AND t.table_name LIKE '%_del_at_%'
                  {$where}
                ORDER BY {$sortOrder}";

        return Db::query($sql);
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @return array|null
     */
    public function getTableInfo($tableName, $columns = '*')
    {

        $selectClause = $this->buildSelectClause($columns, self::$tableColumnMap);

        if (empty($selectClause)) {
            return null;
        }

        $sql = "SELECT
                    {$selectClause}
                FROM information_schema.tables t
                LEFT JOIN pg_catalog.pg_class pgc ON t.table_name = pgc.relname
                    AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
                WHERE t.table_schema = 'public'
                  AND t.table_type = 'BASE TABLE'
                  AND t.table_catalog = '{$this->database}'
                  AND t.table_name = '{$tableName}'";

        $tables = Db::query($sql);

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
            $sortOrder = 'ordinal_position ASC';
        }

        $selectClause = $this->buildSelectClause($columns, self::$fieldColumnMap);

        if (empty($selectClause)) {
            return [];
        }

        $sql = "SELECT
                    {$selectClause}
                FROM information_schema.columns c
                JOIN pg_catalog.pg_class pgc ON c.table_name = pgc.relname
                    AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
                WHERE c.table_schema = 'public'
                  AND c.table_name = '{$tableName}'
                  AND c.column_name NOT LIKE '%_del_at_%'
                  {$where}
                ORDER BY {$sortOrder}";

        $fields = Db::query($sql);

        // Normalize long PG type names to short form for UI compatibility
        foreach ($fields as &$f) {
            if (isset($f['DATA_TYPE'])) {
                $f['DATA_TYPE'] = static::normalizeType($f['DATA_TYPE']);
            }
            // Strip PG ::type cast suffix from COLUMN_DEFAULT
            // e.g. 'hello'::character varying → 'hello', 0::integer → 0
            if (isset($f['COLUMN_DEFAULT']) && !empty($f['COLUMN_DEFAULT']) && !preg_match('/^\w+\(/', $f['COLUMN_DEFAULT'])) {
                $f['COLUMN_DEFAULT'] = preg_replace('/::.+$/', '', $f['COLUMN_DEFAULT']);
            }
            // PG: both "no default" and "DEFAULT NULL" appear as SQL NULL in
            // information_schema. For nullable columns this means NULL default;
            // for NOT NULL columns this means no default at all.
            if (isset($f['COLUMN_DEFAULT']) && $f['COLUMN_DEFAULT'] === null) {
                $f['COLUMN_DEFAULT'] = (isset($f['IS_NULLABLE']) && $f['IS_NULLABLE'] == 'YES') ? 'NULL' : '';
            }
        }
        unset($f);

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

        $fields = $this->getFields($tableName, $columns, "AND c.column_name = '{$fieldName}'");

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
            $sortOrder = 'ordinal_position ASC';
        }

        $sql = "SELECT
                    c.column_name AS \"COLUMN_NAME\",
                    COALESCE(pg_catalog.col_description(pgc.oid, c.ordinal_position::int), '') AS \"COLUMN_COMMENT\"
                FROM information_schema.columns c
                JOIN pg_catalog.pg_class pgc ON c.table_name = pgc.relname
                    AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
                WHERE c.table_schema = 'public'
                  AND c.table_name = '{$tableName}'
                  AND c.column_name LIKE '%_del_at_%'
                  {$where}
                ORDER BY {$sortOrder}";

        return Db::query($sql);
    }

    /**
     * @param string $tableName
     * @param string $fieldName
     * @return array
     */
    public function getKeys($tableName, $fieldName)
    {

        $sql = "SELECT
                    CASE WHEN ix.indisprimary THEN 'PRIMARY' ELSE i.relname END AS \"INDEX_NAME\",
                    CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS \"NON_UNIQUE\",
                    a.attname AS \"COLUMN_NAME\"
                FROM pg_catalog.pg_class t
                JOIN pg_catalog.pg_index ix ON t.oid = ix.indrelid
                JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
                JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                JOIN pg_catalog.pg_namespace n ON t.relnamespace = n.oid
                WHERE t.relkind = 'r'
                  AND n.nspname = 'public'
                  AND t.relname = '{$tableName}'
                  AND a.attname = '{$fieldName}'
                  AND ix.indnatts = 1"; //只读取单列键

        return Db::query($sql);
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
            $this->errors[] = '表名已存在';
            return false;
        }

        $pkinfo = isset($data['fields']) ? $data['fields']['pk'] : [];
        $create_time = isset($data['fields']) ? $data['fields']['create_time'] : [];
        $update_time = isset($data['fields']) ? $data['fields']['update_time'] : [];

        if (empty($pkinfo)) {
            $pkinfo = [
                'COLUMN_NAME' => 'id',
                'COLUMN_COMMENT' => '主键',
                'DATA_TYPE' => 'integer',
                'LENGTH' => '',
                'ATTR' =>
                    [
                        'auto_inc',
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
                $create_time['LENGTH'] = '';
                $create_time['IS_NULLABLE'] = 'NO';
                if (empty($create_time['COLUMN_DEFAULT'])) {
                    $create_time['COLUMN_DEFAULT'] = 'CURRENT_TIMESTAMP';
                }
                $create_time['ATTR'] = [];
                $create_time_attr = $this->buildFieldAttr($create_time);
                $create_time_column = ",
                \"{$create_time['COLUMN_NAME']}\" {$create_time_attr}";
            }
        }

        if (!empty($update_time)) {
            if (!isset($update_time['__del__']) || $update_time['__del__'] == 0) {
                $update_time['LENGTH'] = '';
                $update_time['IS_NULLABLE'] = 'NO';
                if (empty($update_time['COLUMN_DEFAULT'])) {
                    $update_time['COLUMN_DEFAULT'] = 'CURRENT_TIMESTAMP';
                }
                $update_time['ATTR'] = [];
                $update_time_attr = $this->buildFieldAttr($update_time);
                $update_time_column = ",
                \"{$update_time['COLUMN_NAME']}\" {$update_time_attr}";
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (
            \"{$pkinfo['COLUMN_NAME']}\" {$attr} primary key{$create_time_column}{$update_time_column}
        )";

        try {
            Db::execute($sql);
        } catch (\Exception $ex) {
            Log::info($sql);
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }

        // Set table comment
        if (!empty($data['TABLE_COMMENT'])) {
            $commentSql = "COMMENT ON TABLE \"{$tableName}\" IS '{$data['TABLE_COMMENT']}'";
            try {
                Db::execute($commentSql);
            } catch (\Exception $ex) {
                Log::info($commentSql);
                Log::error($ex->__toString());
            }
        }

        // Set pk column comment
        $commentSql = "COMMENT ON COLUMN \"{$tableName}\".\"{$pkinfo['COLUMN_NAME']}\" IS '{$pkinfo['COLUMN_COMMENT']}'";
        try {
            Db::execute($commentSql);
        } catch (\Exception $ex) {
            Log::info($commentSql);
            Log::error($ex->__toString());
        }

        // Set create_time column comment
        if (!empty($create_time_column) && !empty($create_time['COLUMN_COMMENT'])) {
            $commentSql = "COMMENT ON COLUMN \"{$tableName}\".\"{$create_time['COLUMN_NAME']}\" IS '{$create_time['COLUMN_COMMENT']}'";
            try {
                Db::execute($commentSql);
            } catch (\Exception $ex) {
                Log::info($commentSql);
                Log::error($ex->__toString());
            }
        }

        // Set update_time column comment
        if (!empty($update_time_column) && !empty($update_time['COLUMN_COMMENT'])) {
            $commentSql = "COMMENT ON COLUMN \"{$tableName}\".\"{$update_time['COLUMN_NAME']}\" IS '{$update_time['COLUMN_COMMENT']}'";
            try {
                Db::execute($commentSql);
            } catch (\Exception $ex) {
                Log::info($commentSql);
                Log::error($ex->__toString());
            }
        }

        // Update statistics so that pg_stat_user_tables and pg_class.reltuples
        // reflect the new table (gives us creation time and row count).
        try {
            Db::execute("ANALYZE \"{$tableName}\"");
        } catch (\Exception $ex) {
        }

        return true;
    }

    public function getDefaultPkType()
    {
        return 'integer';
    }

    public function getDefaultDatetimeType()
    {
        return 'timestamp';
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldTypes()
    {
        return static::$FIELD_TYPES;
    }

    /**
     * {@inheritdoc}
     * PostgreSQL doesn't have unsigned types.
     */
    public function getFieldAttrOptions()
    {
        return [
            'create' => ['auto_inc' => '自增'],
            'edit' => ['auto_inc' => '自增', 'index' => '索引', 'unique' => '唯一'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultPkAttr()
    {
        return 'auto_inc';
    }

    public function quoteIdentifier($name)
    {
        return '"' . $name . '"';
    }

    public function hasUnsigned()
    {
        return false;
    }

    public function supportsColumnPositioning()
    {
        return false;
    }

    public function getVersion()
    {
        return Db::query("SELECT version() AS ver")[0]['ver'];
    }

    /**
     * @param string $tableName
     * @param array $info
     * @return boolean
     */
    public function addField($tableName, $info)
    {
        $attr = $this->buildFieldAttr($info);

        $sqls = [];

        try {
            $sql = "ALTER TABLE \"{$tableName}\" ADD COLUMN \"{$info['COLUMN_NAME']}\" {$attr}";
            $sqls[] = $sql;
            Db::execute($sql);

            // Set column comment
            if (!empty($info['COLUMN_COMMENT'])) {
                $commentSql = "COMMENT ON COLUMN \"{$tableName}\".\"{$info['COLUMN_NAME']}\" IS '{$info['COLUMN_COMMENT']}'";
                $sqls[] = $commentSql;
                Db::execute($commentSql);
            }

            if (in_array('index', $info['ATTR'])) {
                $sql = "CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_{$info['COLUMN_NAME']}\" ON \"{$tableName}\" (\"{$info['COLUMN_NAME']}\")";
                $sqls[] = $sql;
                Db::execute($sql);
            }
            if (in_array('unique', $info['ATTR'])) {
                $sql = "CREATE UNIQUE INDEX IF NOT EXISTS \"unq_{$tableName}_{$info['COLUMN_NAME']}\" ON \"{$tableName}\" (\"{$info['COLUMN_NAME']}\")";
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

        $index_key = '';
        $unique_key = '';

        foreach ($keys as $key) {
            if ($key['NON_UNIQUE'] == 1) {
                $index_key = $key['INDEX_NAME'];
            } else {
                $unique_key = $key['INDEX_NAME'];
            }
        }

        $sqls = [];

        try {
            // Rename column if name changed
            if ($fieldName != $info['COLUMN_NAME']) {
                $sql = "ALTER TABLE \"{$tableName}\" RENAME COLUMN \"{$fieldName}\" TO \"{$info['COLUMN_NAME']}\"";
                $sqls[] = $sql;
                Db::execute($sql);
            }

            // buildFieldAttr 是唯一权威来源，changeField 只提取并应用其结果
            $typeSql = $this->buildFieldAttr($info);
            $typePart = $this->extractTypeFromAttr($typeSql);
            $hasDefault = preg_match('/\s+DEFAULT\s+(.+)$/i', $typeSql, $dm);
            $defaultVal = $hasDefault ? $dm[1] : null;

            $notNull = $info['IS_NULLABLE'] == 1 ? false : true;
            $isAutoInc = in_array('auto_inc', $info['ATTR']);

            // Check if column currently has a sequence default
            $checkSeq = Db::query("SELECT column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='{$tableName}' AND column_name='{$info['COLUMN_NAME']}'");
            $hasSeq = $checkSeq && !empty($checkSeq[0]['column_default']) && strpos($checkSeq[0]['column_default'], 'nextval') !== false;

            if ($isAutoInc) {
                if (!$hasSeq) {
                    $seqName = "{$tableName}_{$info['COLUMN_NAME']}_seq";
                    Db::execute("DROP SEQUENCE IF EXISTS \"{$seqName}\"");
                    Db::execute("CREATE SEQUENCE \"{$seqName}\"");
                    Db::execute("ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" SET DEFAULT nextval('\"{$seqName}\"')");
                    Db::execute("ALTER SEQUENCE \"{$seqName}\" OWNED BY \"{$tableName}\".\"{$info['COLUMN_NAME']}\"");
                }
            } else {
                if ($hasSeq) {
                    $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" DROP DEFAULT";
                    $sqls[] = $sql;
                    Db::execute($sql);
                    if (preg_match("/nextval\('\"?([^\"']+)\"?/", $checkSeq[0]['column_default'], $m)) {
                        try {
                            Db::execute("DROP SEQUENCE IF EXISTS \"{$m[1]}\"");
                        } catch (\Exception $ex) {
                        }
                    }
                }
            }

            // Change type (for auto_inc use raw data type; PG doesn't accept ALTER TYPE SERIAL)
            $alterType = $isAutoInc ? $info['DATA_TYPE'] : $typePart;
            $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" TYPE {$alterType}";
            if (in_array($info['DATA_TYPE'], ['integer', 'bigint', 'smallint'])) {
                $sql .= " USING \"{$info['COLUMN_NAME']}\"::{$info['DATA_TYPE']}";
            }
            $sqls[] = $sql;
            Db::execute($sql);

            // Set default (before NOT NULL; trust buildFieldAttr's output)
            if (!$isAutoInc) {
                if ($hasDefault) {
                    $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" SET DEFAULT {$defaultVal}";
                    $sqls[] = $sql;
                    Db::execute($sql);
                    if ($notNull) {
                        $upd = "UPDATE \"{$tableName}\" SET \"{$info['COLUMN_NAME']}\" = {$defaultVal} WHERE \"{$info['COLUMN_NAME']}\" IS NULL";
                        $sqls[] = $upd;
                        Db::execute($upd);
                    }
                } else {
                    $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" DROP DEFAULT";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            }

            // Set NOT NULL / DROP NOT NULL
            if ($notNull) {
                $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" SET NOT NULL";
                $sqls[] = $sql;
                Db::execute($sql);
            } else {
                $sql = "ALTER TABLE \"{$tableName}\" ALTER COLUMN \"{$info['COLUMN_NAME']}\" DROP NOT NULL";
                $sqls[] = $sql;
                Db::execute($sql);
            }

            // Set column comment
            if (!empty($info['COLUMN_COMMENT'])) {
                $commentSql = "COMMENT ON COLUMN \"{$tableName}\".\"{$info['COLUMN_NAME']}\" IS '{$info['COLUMN_COMMENT']}'";
                $sqls[] = $commentSql;
                Db::execute($commentSql);
            }

            // Handle indexes
            if (in_array('index', $info['ATTR'])) {
                if ($index_key) {
                    // Index already exists, no action needed
                } else {
                    $sql = "CREATE INDEX IF NOT EXISTS \"idx_{$tableName}_{$info['COLUMN_NAME']}\" ON \"{$tableName}\" (\"{$info['COLUMN_NAME']}\")";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            } else {
                if ($index_key) {
                    $sql = "DROP INDEX IF EXISTS \"{$index_key}\"";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            }

            if (in_array('unique', $info['ATTR'])) {
                if (!$unique_key) {
                    $sql = "CREATE UNIQUE INDEX IF NOT EXISTS \"unq_{$tableName}_{$info['COLUMN_NAME']}\" ON \"{$tableName}\" (\"{$info['COLUMN_NAME']}\")";
                    $sqls[] = $sql;
                    Db::execute($sql);
                }
            } else {
                if ($unique_key && $unique_key != 'PRIMARY') {
                    $sql = "DROP INDEX IF EXISTS \"{$unique_key}\"";
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
     * Extract the type definition from a full attribute string (remove DEFAULT/NOT NULL)
     */
    protected function extractTypeFromAttr($attr)
    {
        // Remove DEFAULT, NOT NULL, NULL parts
        $attr = preg_replace('/\s+DEFAULT\s+(\S+\s*)*/i', '', $attr);
        $attr = preg_replace('/\s+(NOT\s+)?NULL\s*/i', '', $attr);
        return trim($attr);
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
        $not_null = $info['IS_NULLABLE'] == 1 ? '' : 'NOT NULL';
        $default = trim($info['COLUMN_DEFAULT'], "'");
        $auto_inc = $isInteger && $type != 'boolean' && in_array('auto_inc', $info['ATTR']);

        // PG: SERIAL 替代 AUTO_INCREMENT
        if ($auto_inc) {
            $serialMap = [
                'smallint' => 'SMALLSERIAL',
                'integer' => 'SERIAL',
                'bigint' => 'BIGSERIAL',
            ];
            return isset($serialMap[$type]) ? $serialMap[$type] : 'SERIAL';
        }

        // 长度默认值（PG 整型无显示宽度）
        if (empty($length) || !is_numeric($length)) {
            if ($isDecimal) {
                $length = 10;
            } else if ($isChartext) {
                $length = 55;
            }
        }

        // 长度格式化（PG 整型/日期时间/文本无显示长度）
        if ($isChartext) {
            $length = "({$length})";
        } else if ($isDecimal) {
            if (empty($info['NUMERIC_SCALE']) || !is_numeric($info['NUMERIC_SCALE'])) {
                $info['NUMERIC_SCALE'] = 2;
            }
            $length = "({$length},{$info['NUMERIC_SCALE']})";
        } else if ($isDatetime) {
            $length = is_numeric($length) ? "({$length})" : '(0)';
        } else if ($isText || $isInteger) {
            $length = '';
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
                    $default = date('Y-m-d H:i:s', date('Z'));
                }
            }
        } else {
            if ($info['COLUMN_NAME'] == 'delete_time') {
                $default = 'NULL';
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
                $default = "DEFAULT '{$default}'";
            }
        } else if ($type == 'boolean') {
            // PG boolean 是原生类型，默认值不加引号
            $default = in_array(strtolower($default), ['true', 'false', '1', '0']) ? $default : 'false';
            $default = "DEFAULT {$default}";
        } else if ($isInteger || $isDecimal) {
            // PG 数值默认值不加引号
            $default = is_numeric($default) ? $default : 0;

            $default = "DEFAULT {$default}";
        } else {
            // 函数表达式（如 gen_random_uuid()）不加引号
            if (strpos($default, '(') !== false && substr($default, -1) === ')') {
                $default = "DEFAULT {$default}";
            } else {
                $default = "DEFAULT '{$default}'";
            }
        }

        if (in_array('primary', $info['ATTR'])) {
            $default = '';
        }

        $attr = "{$type}{$length} {$not_null} {$default}";

        return trim(preg_replace('/\s+/', ' ', $attr));
    }

    /**
     * @param string $tableName
     * @return boolean
     */
    public function dropTable($tableName)
    {
        $sql = "DROP TABLE IF EXISTS \"{$tableName}\"";

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
        $sql = "ALTER TABLE \"{$tableName}\" DROP COLUMN \"{$fieldName}\"";

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

        $sql = "COMMENT ON TABLE \"{$tableName}\" IS '{$comment}'";

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
     * @param string $new_name
     * @return boolean
     */
    public function changeTableName($tableName, $new_name)
    {
        if ($tableName == $new_name) {
            return false;
        }

        $sql = "ALTER TABLE \"{$tableName}\" RENAME TO \"{$new_name}\"";

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
     * @return string
     */
    public function getOptimizeSql($tableName)
    {
        return "VACUUM ANALYZE \"{$tableName}\"";
    }

    /**
     * 使用父类基于死元组空间比率的判断
     * 碎片率 > 20% 或碎片 > 100MB 时显示优化按钮
     */
    public function needOptimize($row)
    {
        $rate = $row['DATA_FREE'] / max($row['DATA_LENGTH'], 1);
        return $rate > 0.2 || $row['DATA_FREE'] > 100 * 1024 * 1024;
    }

    public function optimizeTable($tableName)
    {
        try {
            Db::execute("VACUUM ANALYZE \"{$tableName}\"");
            return true;
        } catch (\Exception $ex) {
            Log::error($ex->__toString());
            $this->errors[] = $ex->getMessage();
            return false;
        }
    }

    /**
     * @param string $tableName
     * @return string
     */
    public function getCreateTableSql($tableName)
    {

        // Query all indexes
        $indexes = Db::query(
            "SELECT
                CASE WHEN ix.indisprimary THEN 'PRIMARY'
                     WHEN ix.indisunique THEN 'UNIQUE'
                     ELSE 'INDEX'
                END AS constraint_type,
                i.relname AS index_name,
                a.attname AS column_name,
                array_position(ix.indkey, a.attnum) AS col_order
            FROM pg_catalog.pg_class t
            JOIN pg_catalog.pg_index ix ON t.oid = ix.indrelid
            JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
            JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            JOIN pg_catalog.pg_namespace n ON t.relnamespace = n.oid
            WHERE t.relkind = 'r'
              AND n.nspname = 'public'
              AND t.relname = '{$tableName}'
            ORDER BY ix.indisprimary DESC, ix.indisunique DESC, i.relname, col_order"
        );

        $pkNames = [];
        $constraintLines = [];
        $indexLines = [];
        $seenIndexes = [];

        foreach ($indexes as $idx) {
            if ($idx['constraint_type'] === 'PRIMARY') {
                $pkNames[] = $idx['column_name'];
            } elseif ($idx['constraint_type'] === 'UNIQUE') {
                $key = $idx['index_name'];
                if (!isset($seenIndexes[$key])) {
                    $seenIndexes[$key] = ['type' => 'UNIQUE', 'name' => $key, 'cols' => []];
                }
                $seenIndexes[$key]['cols'][] = $idx['column_name'];
            } else {
                $key = $idx['index_name'];
                if (!isset($seenIndexes[$key])) {
                    $seenIndexes[$key] = ['type' => 'INDEX', 'name' => $key, 'cols' => []];
                }
                $seenIndexes[$key]['cols'][] = $idx['column_name'];
            }
        }

        foreach ($seenIndexes as $c) {
            $quotedCols = array_map(function ($n) {
                return '"' . $n . '"';
            }, $c['cols']);
            if ($c['type'] === 'UNIQUE') {
                $constraintLines[] = '    CONSTRAINT "' . $c['name'] . '" UNIQUE (' . implode(', ', $quotedCols) . ')';
            } else {
                $indexLines[] = 'CREATE INDEX "' . $c['name'] . '" ON "' . $tableName . '" (' . implode(', ', $quotedCols) . ');';
            }
        }

        $cols = Db::query(
            "SELECT
                c.column_name,
                c.data_type,
                c.character_maximum_length,
                c.column_default,
                c.is_nullable,
                c.numeric_precision,
                c.numeric_scale,
                c.datetime_precision,
                c.ordinal_position,
                COALESCE(pg_catalog.col_description(pgc.oid, c.ordinal_position::int), '') AS column_comment
            FROM information_schema.columns c
            JOIN pg_catalog.pg_class pgc ON c.table_name = pgc.relname
                AND pgc.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')
            WHERE c.table_schema = 'public'
              AND c.table_name = '{$tableName}'
            ORDER BY c.ordinal_position"
        );

        if (empty($cols)) {
            return '';
        }

        $lines = ["CREATE TABLE \"{$tableName}\" ("];
        $colLines = [];
        $commentLines = [];

        // Add table comment
        $tableComment = Db::query(
            "SELECT obj_description(pgc.oid, 'pg_class') AS comment
            FROM pg_catalog.pg_class pgc
            JOIN pg_catalog.pg_namespace n ON pgc.relnamespace = n.oid
            WHERE n.nspname = 'public' AND pgc.relname = '{$tableName}'"
        );

        if (!empty($tableComment[0]['comment'])) {
            $commentLines[] = "COMMENT ON TABLE \"{$tableName}\" IS '{$tableComment[0]['comment']}';";
        }

        foreach ($cols as $col) {
            $colName = $col['column_name'];
            $parts = ['    "' . $colName . '"'];

            $default = $col['column_default'];
            $isSerial = $default && strpos($default, 'nextval') !== false;

            $type = static::normalizeType($col['data_type']);

            if ($isSerial) {
                // SERIAL/BIGSERIAL replaces explicit type + DEFAULT
                if ($type == 'bigint') {
                    $parts[] = 'BIGSERIAL';
                } elseif ($type == 'smallint') {
                    $parts[] = 'SMALLSERIAL';
                } else {
                    $parts[] = 'SERIAL';
                }
            } else {
                if ($type == 'varchar' && $col['character_maximum_length']) {
                    $parts[] = "varchar({$col['character_maximum_length']})";
                } elseif ($type == 'char' && $col['character_maximum_length']) {
                    $parts[] = "char({$col['character_maximum_length']})";
                } elseif ($type == 'numeric' && $col['numeric_precision']) {
                    if ($col['numeric_scale'] > 0) {
                        $parts[] = "numeric({$col['numeric_precision']},{$col['numeric_scale']})";
                    } else {
                        $parts[] = "numeric({$col['numeric_precision']})";
                    }
                } elseif (in_array($type, ['timestamp', 'timestamptz', 'time', 'timetz', 'interval']) && isset($col['datetime_precision']) && $col['datetime_precision'] !== null) {
                    $parts[] = "{$type}({$col['datetime_precision']})";
                } else {
                    $parts[] = $type;
                }

                if ($col['is_nullable'] == 'NO') {
                    $parts[] = 'NOT NULL';
                }

                if ($default !== null && $default !== '') {
                    // Strip PG ::type cast suffix for cleaner output
                    if (!preg_match('/^\w+\(/', $default)) {
                        $default = preg_replace('/::.+$/', '', $default);
                    }
                    $parts[] = 'DEFAULT ' . $default;
                }
            }

            $colLines[] = implode(' ', $parts);

            if ($col['column_comment']) {
                $commentLines[] = "COMMENT ON COLUMN \"{$tableName}\".\"{$colName}\" IS '{$col['column_comment']}';";
            }
        }

        // Add PRIMARY KEY constraint
        if (!empty($pkNames)) {
            $quotedPks = array_map(function ($n) {
                return '"' . $n . '"';
            }, $pkNames);
            $colLines[] = '    PRIMARY KEY (' . implode(', ', $quotedPks) . ')';
        }

        // Add UNIQUE constraints
        foreach ($constraintLines as $cl) {
            $colLines[] = $cl;
        }

        $lines[] = implode(',' . PHP_EOL, $colLines);
        $lines[] = ');';

        if (!empty($commentLines)) {
            $lines = array_merge($lines, $commentLines);
        }

        // Add regular index creation statements
        if (!empty($indexLines)) {
            $lines = array_merge($lines, $indexLines);
        }

        return implode(PHP_EOL, $lines);
    }

    public function isInteger($fieldType)
    {
        return in_array($fieldType, [
            'smallint',
            'integer',
            'bigint',
            'serial',
            'smallserial',
            'bigserial',
            'real',
            'double precision',
            'money',
        ]);
    }

    public function isDecimal($fieldType)
    {
        return in_array($fieldType, [
            'decimal',
            'numeric',
        ]);
    }

    public function isDatetime($fieldType)
    {
        return in_array($fieldType, [
            'date',
            'timestamp',
            'timestamptz',
            'time',
            'timetz',
            'interval',
        ]);
    }

    public function isChartext($fieldType)
    {
        return in_array($fieldType, [
            'varchar',
            'char',
        ]);
    }

    public function isText($fieldType)
    {
        return in_array($fieldType, [
            'text',
            'json',
            'jsonb',
            'bytea',
            'uuid',
            'xml',
        ]);
    }
}

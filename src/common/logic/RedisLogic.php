<?php

namespace tpext\manager\common\logic;

use tpext\manager\common\Module;

class RedisLogic
{
    const TYPE_NOT_FOUND = 0;
    const TYPE_STRING = 1;
    const TYPE_SET = 2;
    const TYPE_LIST = 3;
    const TYPE_ZSET = 4;
    const TYPE_HASH = 5;

    /**
     * @var \Redis|\Predis\Client
     */
    protected $redis = null;

    protected $connected = false;

    protected $error = '';

    protected $driver = '';

    protected $currentDb = 0;

    /**
     * 获取驱动类型
     *
     * @return string
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * 获取 Redis 实例
     *
     * @return \Redis|\Predis\Client|null
     */
    public function getInstance()
    {
        if (!$this->connected) {
            $this->connect();
        }

        return $this->redis;
    }

    /**
     * 获取当前数据库编号
     *
     * @return int
     */
    public function getDbNum()
    {
        return $this->currentDb;
    }

    /**
     * 检测可用驱动
     *
     * @return string
     */
    protected function detectDriver()
    {
        if (extension_loaded('redis') && class_exists('\Redis')) {
            return 'phpredis';
        }
        if (class_exists('\Predis\Client')) {
            return 'predis';
        }
        return '';
    }

    /**
     * 连接 Redis
     *
     * @param int|null $db
     * @return bool
     */
    public function connect($db = null)
    {
        if ($this->connected) {
            return true;
        }

        $this->driver = $this->detectDriver();

        if (empty($this->driver)) {
            $this->error = 'No Redis driver available (install phpredis extension or predis/predis)';
            $this->connected = false;
            return false;
        }

        try {
            $host = Module::getInstance()->config('redis_host', '127.0.0.1');
            $port = (int)Module::getInstance()->config('redis_port', 6379);
            $password = Module::getInstance()->config('redis_password', '');
            $defaultDb = (int)Module::getInstance()->config('redis_db', 0);

            $selectDb = $db !== null ? (int)$db : $defaultDb;

            if ($this->driver === 'phpredis') {
                $this->redis = new \Redis;

                if (!$this->redis->connect($host, $port, 3)) {
                    $this->error = "Failed to connect to Redis at {$host}:{$port}";
                    $this->connected = false;
                    return false;
                }

                if (!empty($password) && !$this->redis->auth($password)) {
                    $this->error = 'Redis authentication failed';
                    $this->connected = false;
                    return false;
                }

                if ($selectDb > 0) {
                    $this->redis->select($selectDb);
                }

                $this->currentDb = $selectDb;
            } else {
                // predis
                $params = [
                    'host' => $host,
                    'port' => $port,
                ];

                if (!empty($password)) {
                    $params['password'] = $password;
                }

                $params['database'] = $selectDb;

                $this->redis = new \Predis\Client($params);
                $this->redis->connect();
                $this->currentDb = $selectDb;
            }

            $this->connected = true;
            return true;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->connected = false;
            return false;
        }
    }

    /**
     * 切换数据库
     *
     * @param int $db
     * @return bool
     */
    public function selectDb($db)
    {
        if (!$this->connected) {
            return $this->connect($db);
        }

        try {
            $this->redis->select((int)$db);
            $this->currentDb = (int)$db;
            return true;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * 获取数据库数量
     *
     * @return int
     */
    public function getDatabases()
    {
        if (!$this->connected && !$this->connect()) {
            return 16;
        }

        if ($this->driver === 'phpredis') {
            try {
                $config = $this->redis->config('GET', 'databases');
                if (isset($config['databases'])) {
                    return (int)$config['databases'];
                }
            } catch (\Exception $e) {
                // ignore
            }
        }

        return 16;
    }

    /**
     * 获取当前数据库的键总数
     *
     * @return int
     */
    public function getDbSize()
    {
        if (!$this->connected && !$this->connect()) {
            return 0;
        }

        try {
            return $this->redis->dbSize();
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return 0;
        }
    }

    /**
     * 扫描键列表（使用 SCAN，生产安全）
     *
     * @param string $pattern 匹配模式
     * @param int $count 每页数量
     * @param int $page 页码
     * @param int $total 输出总匹配数
     * @return array
     */
    public function scanKeys($pattern = '*', $count = 20, $page = 1, &$total = 0)
    {
        if (!$this->connected && !$this->connect()) {
            return [];
        }

        try {
            $prefix = Module::getInstance()->config('redis_prefix', '');
            if (!empty($prefix) && strpos($pattern, $prefix) !== 0) {
                $pattern = $prefix . $pattern;
            }

            $allKeys = [];
            $iterator = null;

            while (true) {
                $keys = $this->redis->scan($iterator, $pattern, 200);
                if ($keys === false) {
                    break;
                }
                foreach ($keys as $k) {
                    $allKeys[] = $k;
                }
                if ($iterator == 0) {
                    break;
                }
            }

            $allKeys = array_unique($allKeys);
            sort($allKeys);

            $total = count($allKeys);

            $offset = ($page - 1) * $count;
            return array_slice($allKeys, $offset, $count);
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return [];
        }
    }

    /**
     * 获取键信息（类型、TTL、大小）
     *
     * @param string $key
     * @return array
     */
    public function getKeyInfo($key)
    {
        if (!$this->connected && !$this->connect()) {
            return [];
        }

        try {
            $type = $this->normalizeType($this->redis->type($key));
            $ttl = $this->redis->ttl($key);
            $encoding = '';

            if ($this->driver === 'phpredis') {
                try {
                    $encoding = $this->redis->object('encoding', $key);
                } catch (\Exception $e) {
                    // predis 不支持 object 命令
                }
            }

            return [
                'key' => $key,
                'type' => $this->getTypeName($type),
                'type_code' => $type,
                'ttl' => $ttl,
                'encoding' => $encoding,
                'size' => $this->getKeySizeByType($key, $type),
            ];
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return [
                'key' => $key,
                'type' => 'unknown',
                'type_code' => 0,
                'ttl' => -2,
                'encoding' => '',
                'size' => 0,
            ];
        }
    }

    /**
     * 获取多个键的类型信息
     *
     * @param array $keys
     * @return array
     */
    public function getKeysInfo(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->getKeyInfo($key);
        }
        return $result;
    }

    /**
     * 获取键的完整数据
     *
     * @param string $key
     * @return array
     */
    public function getKeyData($key)
    {
        if (!$this->connected && !$this->connect()) {
            return [];
        }

        try {
            $type = $this->normalizeType($this->redis->type($key));
            $ttl = $this->redis->ttl($key);

            $data = [
                'key' => $key,
                'type' => $this->getTypeName($type),
                'type_code' => $type,
                'ttl' => $ttl,
            ];

            switch ($type) {
                case self::TYPE_STRING:
                    $data['value'] = $this->redis->get($key);
                    break;
                case self::TYPE_HASH:
                    $data['value'] = $this->redis->hGetAll($key);
                    break;
                case self::TYPE_LIST:
                    $data['value'] = $this->redis->lRange($key, 0, -1);
                    break;
                case self::TYPE_SET:
                    $data['value'] = $this->redis->sMembers($key);
                    break;
                case self::TYPE_ZSET:
                    $data['value'] = $this->redis->zRange($key, 0, -1, true);
                    break;
                default:
                    $data['value'] = null;
                    break;
            }

            return $data;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return [
                'key' => $key,
                'type' => 'error',
                'type_code' => 0,
                'ttl' => -2,
                'value' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 删除单个键
     *
     * @param string $key
     * @return bool
     */
    public function deleteKey($key)
    {
        if (!$this->connected && !$this->connect()) {
            return false;
        }

        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    /**
     * 批量删除键
     *
     * @param array $keys
     * @return int 成功删除的数量
     */
    public function deleteKeys(array $keys)
    {
        if (!$this->connected && !$this->connect()) {
            return 0;
        }

        try {
            $deleted = 0;
            foreach ($keys as $key) {
                if ($this->redis->del($key)) {
                    $deleted++;
                }
            }
            return $deleted;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            return 0;
        }
    }

    /**
     * 检查连接状态
     *
     * @return bool
     */
    public function isConnected()
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $this->redis->ping();
            return true;
        } catch (\Exception $e) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * 获取错误信息
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 获取类型名称
     *
     * @param int $type
     * @return string
     */
    protected function getTypeName($type)
    {
        $types = [
            self::TYPE_STRING => 'string',
            self::TYPE_SET => 'set',
            self::TYPE_LIST => 'list',
            self::TYPE_ZSET => 'zset',
            self::TYPE_HASH => 'hash',
            self::TYPE_NOT_FOUND => 'none',
        ];

        return $types[$type] ?? 'unknown';
    }

    /**
     * 统一类型值（phpredis 返回 int，predis 返回 string）
     *
     * @param int|string $type
     * @return int
     */
    protected function normalizeType($type)
    {
        if (is_int($type)) {
            return $type;
        }

        $map = [
            'string' => self::TYPE_STRING,
            'set' => self::TYPE_SET,
            'list' => self::TYPE_LIST,
            'zset' => self::TYPE_ZSET,
            'hash' => self::TYPE_HASH,
            'none' => self::TYPE_NOT_FOUND,
        ];

        return $map[strtolower((string)$type)] ?? self::TYPE_NOT_FOUND;
    }

    /**
     * 根据类型获取键的数据长度
     *
     * @param string $key
     * @param int $type
     * @return int
     */
    protected function getKeySizeByType($key, $type)
    {
        try {
            switch ($type) {
                case self::TYPE_STRING:
                    return $this->redis->strlen($key);
                case self::TYPE_HASH:
                    return $this->redis->hLen($key);
                case self::TYPE_LIST:
                    return $this->redis->lLen($key);
                case self::TYPE_SET:
                    return $this->redis->sCard($key);
                case self::TYPE_ZSET:
                    return $this->redis->zCard($key);
                default:
                    return 0;
            }
        } catch (\Exception $e) {
            return 0;
        }
    }
}

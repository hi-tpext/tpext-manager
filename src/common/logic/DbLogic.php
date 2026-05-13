<?php

namespace tpext\manager\common\logic;

use think\facade\Db;

/**
 * DbLogic 工厂类
 * 根据数据库类型返回对应的实现实例
 * @mixin AbstractDbLogic
 * 
 */

class DbLogic
{
    protected $handler = null;

    public function __construct()
    {
        $this->handler = self::create();
    }

    /**
     * 根据数据库配置创建对应的 DbLogic 实例
     *
     * @return AbstractDbLogic
     */
    public static function create()
    {
        $type = Db::getConfig('default', 'mysql');

        if (strtolower($type) == 'pgsql') {
            return new PgsqlDbLogic;
        }

        return new MysqlDbLogic;
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->handler, $name], $arguments);
    }
}

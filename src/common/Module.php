<?php

namespace tpext\manager\common;

use tpext\common\Module as baseModule;

class Module extends baseModule
{
    protected $version = '1.0.3';

    protected $name = 'tpext.manager';

    protected $title = 'tpext综合管理';

    protected $description = '提供对扩展的管理[安装/卸载/资源刷新/配置]/数据库管理';

    protected $root = __DIR__ . '/../../';

    protected $modules = [
        'admin' => ['config', 'extension', 'dbtable', 'creator', 'redisdata'],
    ];

    protected $versions = [
        '1.0.1' => '',
        '1.0.2' => '1.0.2.sql',
    ];
}

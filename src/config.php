<?php

return [
    'model_namespace' => 'common',
    'redis_host' => '127.0.0.1',
    'redis_port' => 6379,
    'redis_password' => '',
    'redis_db' => 0,
    'redis_prefix' => '',
    //Config description
    '__config__' => [
        'model_namespace' => ['type' => 'radio', 'label' => 'Model namespace', 'options' => ['common' => 'app\\common\\model', 'admin' => 'app\\admin\\model']],
        'redis_host' => ['type' => 'text', 'label' => 'Redis主机', 'size' => [2, 8], 'required' => true],
        'redis_port' => ['type' => 'number', 'label' => 'Redis端口', 'size' => [2, 8], 'required' => true],
        'redis_password' => ['type' => 'password', 'label' => 'Redis密码', 'size' => [2, 8]],
        'redis_db' => ['type' => 'number', 'label' => '默认数据库', 'size' => [2, 8], 'help' => '0-15'],
        'redis_prefix' => ['type' => 'text', 'label' => '键前缀', 'size' => [2, 8]],
    ],
];

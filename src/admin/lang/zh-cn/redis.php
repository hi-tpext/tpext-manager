<?php
/**
 * Redis controller language file (zh-CN)
 */
return [
    // Page titles
    'page_redis_manage' => 'Redis管理',
    'page_view_key' => '查看键数据',
    'page_change_db' => '切换数据库',

    // Field labels
    'key' => '键名',
    'type' => '类型',
    'ttl' => 'TTL(秒)',
    'value' => '值',
    'score' => '分数',
    'member' => '成员',
    'field' => '字段',
    'index' => '序号',
    'encoding' => '编码',
    'size' => '大小',
    'kwd' => '键名匹配',
    'db' => '选择数据库',
    'current_db' => '当前数据库',
    'total_keys' => '键总数',
    'search_kwd' => '搜索键名',
    'search_pattern' => '键名模式',
    'connection_status' => '连接状态',
    'connected' => '已连接',
    'disconnected' => '未连接',
    'data_size' => '数据大小',

    // Operations
    'btn_view' => '查看',
    'btn_delete' => '删除',
    'btn_refresh' => '刷新',
    'btn_search' => '搜索',
    'btn_select_db' => '切换',
    'btn_clear_search' => '清除搜索',
    'opt_search_kwd' => '键名匹配',

    // Messages
    'msg_redis_not_connected' => 'Redis未连接，请检查配置',
    'msg_redis_extension_not_loaded' => 'PHP Redis扩展未安装',
    'msg_connect_success' => 'Redis连接成功',
    'msg_connect_failed' => 'Redis连接失败',
    'msg_delete_success' => '成功删除%s个键',
    'msg_delete_failed' => '删除失败',
    'msg_key_not_exists' => '键不存在或已过期',
    'msg_param_error' => '参数有误',
    'msg_no_data' => '暂无数据',
    'msg_confirm_delete' => '确认删除选中的键？此操作不可恢复！',
    'msg_switch_db_success' => '已切换到数据库[%s]',
    'msg_data_too_large' => '数据过大，仅展示前1000条记录',
    'msg_operation_not_allowed' => '不支持的操作',
    'msg_edit_not_supported' => '暂不支持在线修改Redis键数据',
    'msg_add_not_supported' => '暂不支持通过此界面新增Redis键',

    // TTL
    'msg_ttl_persist' => '永不过期',
    'msg_ttl_expired' => '已过期',
    'ttl_day' => '天',
    'ttl_hour' => '时',
    'ttl_minute' => '分',
    'ttl_second' => '秒',

    // Help
    'help_search_pattern' => '输入关键词模糊搜索，支持 * ? 通配符',
    'help_ttl' => '-1表示永不过期，-2表示已过期',
];

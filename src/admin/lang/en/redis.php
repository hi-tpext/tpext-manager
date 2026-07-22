<?php
/**
 * Redis controller language file (English)
 */
return [
    // Page titles
    'page_redis_manage' => 'Redis Management',
    'page_view_key' => 'View Key Data',
    'page_change_db' => 'Switch Database',

    // Field labels
    'key' => 'Key',
    'type' => 'Type',
    'ttl' => 'TTL(s)',
    'value' => 'Value',
    'score' => 'Score',
    'member' => 'Member',
    'field' => 'Field',
    'index' => 'Index',
    'encoding' => 'Encoding',
    'size' => 'Size',
    'kwd' => 'Key Pattern',
    'db' => 'Select Database',
    'current_db' => 'Current Database',
    'total_keys' => 'Total Keys',
    'search_kwd' => 'Search Key',
    'search_pattern' => 'Key Pattern',
    'connection_status' => 'Connection Status',
    'connected' => 'Connected',
    'disconnected' => 'Disconnected',
    'data_size' => 'Data Size',

    // Operations
    'btn_view' => 'View',
    'btn_delete' => 'Delete',
    'btn_refresh' => 'Refresh',
    'btn_search' => 'Search',
    'btn_select_db' => 'Switch',
    'btn_clear_search' => 'Clear Search',
    'opt_search_kwd' => 'Key Pattern',

    // Messages
    'msg_redis_not_connected' => 'Redis not connected, please check configuration',
    'msg_redis_extension_not_loaded' => 'PHP Redis extension not loaded',
    'msg_connect_success' => 'Redis connected successfully',
    'msg_connect_failed' => 'Redis connection failed',
    'msg_delete_success' => 'Successfully deleted %s key(s)',
    'msg_delete_failed' => 'Deletion failed',
    'msg_key_not_exists' => 'Key does not exist or has expired',
    'msg_param_error' => 'Invalid parameters',
    'msg_no_data' => 'No data',
    'msg_confirm_delete' => 'Are you sure you want to delete the selected keys? This operation cannot be undone!',
    'msg_switch_db_success' => 'Switched to database [%s]',
    'msg_data_too_large' => 'Data is too large, only showing the first 1000 records',
    'msg_operation_not_allowed' => 'Operation not allowed',
    'msg_edit_not_supported' => 'Editing Redis key data is not supported yet',
    'msg_add_not_supported' => 'Adding new Redis keys via this interface is not supported',

    // TTL
    'msg_ttl_persist' => 'Persistent',
    'msg_ttl_expired' => 'Expired',
    'ttl_day' => 'd',
    'ttl_hour' => 'h',
    'ttl_minute' => 'm',
    'ttl_second' => 's',

    // Help
    'help_search_pattern' => 'Enter keyword for fuzzy search, supports * ? wildcards',
    'help_ttl' => '-1 means no expiration, -2 means expired',
];

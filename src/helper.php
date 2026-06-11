<?php

use tpext\common\ExtLoader;
use tpext\manager\common\event\Extensions;
use think\facade\Lang;

$classMap = [
    'tpext\\manager\\common\\Module'
];

ExtLoader::addClassMap($classMap);

if (ExtLoader::isWebman()) {
    ExtLoader::watch('tpext_find_extensions',  Extensions::class, true, '/extend/ dir scan');
}

if (!function_exists('__admin_lang')) {
    function __admin_lang($name = null, $vars = [], $range = '')
    {
        return Lang::get($name, $vars, $range);
    }
}
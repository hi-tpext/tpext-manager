<?php

namespace tpext\manager\admin\controller;

use think\Controller;
use tpext\manager\common\Module;
use tpext\manager\common\logic\RedisLogic;
use tpext\builder\traits\actions\HasBase;
use tpext\builder\traits\actions\HasIndex;

/**
 * Redis管理
 * @title Redis管理
 */
class Redisdata extends Controller
{
    use HasBase;
    use HasIndex;

    /**
     * @var RedisLogic
     */
    protected $redisLogic;

    protected function initialize()
    {
        Module::getInstance()->loadLang('redis');

        $this->pageTitle = __admin_lang('page_redis_manage');
        $this->pk = '__key__';
        $this->pagesize = 20;
        $this->sortOrder = '';

        $this->redisLogic = new RedisLogic;
    }

    /**
     * 初始化 Redis 连接并选择数据库
     */
    protected function initRedis()
    {
        $db = input('db/d', 0);

        $this->redisLogic->connect($db);

        if (!$this->redisLogic->isConnected()) {
            $this->indexText = __admin_lang('msg_redis_not_connected') . ': '
                . $this->redisLogic->getError();
        }
    }

    /**
     * 解析复合键名 db_{N}_{原始键}
     *
     * @param string $compositeKey
     * @return array ['db' => int, 'key' => string]
     */
    protected function parseKey($compositeKey)
    {
        if (preg_match('/^db_(\d+)_(.+)$/', $compositeKey, $matches)) {
            return ['db' => (int) $matches[1], 'key' => $matches[2]];
        }
        return ['db' => input('db/d', 0), 'key' => $compositeKey];
    }

    /**
     * 筛选条件
     *
     * @return array
     */
    protected function filterWhere()
    {
        $searchData = request()->get();

        $where = [];

        if (isset($searchData['kwd']) && $searchData['kwd'] !== '') {
            $where['kwd'] = $searchData['kwd'];
        }

        return $where;
    }

    /**
     * 构建搜索
     *
     * @return void
     */
    protected function buildSearch()
    {
        $search = $this->search;

        $dbOptions = [];
        for ($i = 0; $i < 16; $i++) {
            $dbOptions[$i] = 'DB' . $i;
        }

        $search->tabLink('db')->options($dbOptions);

        $search->text('kwd', '', 4)
            ->maxlength(255)
            ->placeholder(__admin_lang('help_search_pattern'));
    }

    /**
     * 生成数据，从 Redis 扫描键列表
     *
     * @param array $where
     * @param string $sortOrder
     * @param integer $page
     * @param integer $total
     * @return array
     */
    protected function buildDataList($where = [], $sortOrder = '', $page = 1, &$total = -1)
    {
        $this->initRedis();

        if (!$this->redisLogic->isConnected()) {
            $total = 0;
            return [];
        }

        $kwd = $where['kwd'] ?? '';
        if ($kwd !== '' && strpos($kwd, '*') === false && strpos($kwd, '?') === false) {
            $kwd = '*' . $kwd . '*';
        }
        $pattern = $kwd !== '' ? $kwd : '*';
        $keys = $this->redisLogic->scanKeys($pattern, $this->pagesize, $page, $total);

        if (empty($keys)) {
            return [];
        }

        $keysInfo = $this->redisLogic->getKeysInfo($keys);
        $data = [];
        $startIndex = ($page - 1) * $this->pagesize + 1;
        $currentDb = $this->redisLogic->getDbNum();
        foreach ($keysInfo as $i => $info) {
            $info['__key__'] = 'db_' . $currentDb . '_' . $info['key'];
            $info['index'] = $startIndex + $i;
            $data[] = $info;
        }

        return $data;
    }

    /**
     * 构建表格
     *
     * @param array $data
     * @param boolean $isExporting
     * @return void
     */
    protected function buildTable(&$data = [], $isExporting = false)
    {
        $table = $this->table;

        $table->show('index');
        $table->show('key');
        $table->match('type')->options([
            'string' => 'string',
            'hash' => 'hash',
            'list' => 'list',
            'set' => 'set',
            'zset' => 'zset',
            'none' => 'none',
        ])->mapClassGroup([
                    ['string', 'info'],
                    ['hash', 'success'],
                    ['list', 'warning'],
                    ['set', 'danger'],
                    ['zset', 'primary'],
                ]);
        $table->raw('ttl')->to(function ($val) {
            return $this->formatTtl($val);
        });
        $table->show('size');

        $table->getToolbar()
            ->btnDelete()
            ->btnRefresh()
            ->btnToggleSearch();

        $table->getActionbar()
            ->btnView()
            ->btnDelete();
    }

    /**
     * 构建表单
     * $isEdit: 0-新增, 1-编辑, 2-查看
     *
     * @param int|boolean $isEdit
     * @param array $data
     */
    protected function buildForm($isEdit, &$data = [])
    {
        $form = $this->form;

        if ($isEdit == 2) {
            // 查看模式
            $form->show('key');
            $form->show('current_db');
            $form->show('type');
            $form->show('ttl')->to(function ($val) {
                return strip_tags($this->formatTtl($val));
            });

            $value = isset($data['value']) ? $data['value'] : null;
            $displayValue = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : (is_string($value) ? $value : var_export($value, true));

            $form->html('value')->display(
                '<pre style="white-space:pre-wrap;word-break:break-all;">{$data}</pre>',
                ['data' => $displayValue]
            )->size(2, 10);

            $form->readonly();
        } else if ($isEdit) {
            // 编辑模式：暂不支持
            $form->html('', '')->value('<div class="alert alert-warning">'
                . __admin_lang('msg_edit_not_supported') . '</div>');
        } else {
            // 新增模式：暂不支持
            $form->html('', '')->value('<div class="alert alert-warning">'
                . __admin_lang('msg_add_not_supported') . '</div>');
        }
    }

    /**
     * 格式化 TTL
     *
     * @param int $ttl
     * @return string
     */
    protected function formatTtl($ttl)
    {
        if ($ttl == -1) {
            return '<span class="label label-success">' . __admin_lang('msg_ttl_persist') . '</span>';
        }
        if ($ttl == -2) {
            return '<span class="label label-default">' . __admin_lang('msg_ttl_expired') . '</span>';
        }
        if ($ttl <= 0) {
            return '<span class="label label-default">0</span>';
        }

        $days = floor($ttl / 86400);
        $hours = floor(($ttl % 86400) / 3600);
        $minutes = floor(($ttl % 3600) / 60);
        $seconds = $ttl % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . __admin_lang('ttl_day');
        }
        if ($hours > 0) {
            $parts[] = $hours . __admin_lang('ttl_hour');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . __admin_lang('ttl_minute');
        }
        if ($seconds > 0 || empty($parts)) {
            $parts[] = $seconds . __admin_lang('ttl_second');
        }

        return '<span class="label label-warning">' . implode('', $parts) . '</span>';
    }

    /**
     * 保存数据（Redis 管理不涉及添加/编辑键，保留方法体为空）
     *
     * @param integer $id
     * @return mixed
     */
    protected function save($id = 0)
    {
        $this->error(__admin_lang('msg_operation_not_allowed'));
    }

    /**
     * 查看键数据
     * @title 查看键数据
     */
    public function view()
    {
        $key = input('id', '');

        if (empty($key)) {
            return $this->builder()->layer()->close(0, __admin_lang('msg_param_error'));
        }

        $parsed = $this->parseKey($key);

        $builder = $this->builder(__admin_lang('page_view_key'), $parsed['key']);

        $this->redisLogic->connect($parsed['db']);

        $data = $this->redisLogic->getKeyData($parsed['key']);

        if (empty($data) || $data['type_code'] == RedisLogic::TYPE_NOT_FOUND) {
            return $builder->layer()->close(0, __admin_lang('msg_key_not_exists'));
        }

        $data['current_db'] = '#' . $parsed['db'];

        $form = $builder->form();
        $this->form = $form;
        $this->buildForm(2, $data);
        $form->fill($data);

        return $builder->render();
    }

    /**
     * 删除键
     * @title 删除键
     */
    public function delete()
    {
        $ids = input('post.ids', '');

        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        // 解析复合键，提取第一个键的 db 用于连接
        $first = $this->parseKey(reset($ids));
        $this->redisLogic->connect($first['db']);

        // 提取所有真实键名
        $realKeys = array_map(function ($id) {
            $parsed = $this->parseKey($id);
            return $parsed['key'];
        }, $ids);

        $deleted = $this->redisLogic->deleteKeys($realKeys);

        if ($deleted > 0) {
            $this->success(sprintf(__admin_lang('msg_delete_success'), $deleted));
        } else {
            $this->error(__admin_lang('msg_delete_failed') . ': ' . $this->redisLogic->getError());
        }
    }

}

<?php

namespace tpext\manager\admin\controller;

use think\facade\Db;
use tpext\think\App;
use think\Controller;
use think\facade\Session;
use tpext\common\ExtLoader;
use tpext\manager\common\logic\DbLogic;
use tpext\manager\common\logic\DbBackupLogic;
use tpext\manager\common\logic\AbstractDbLogic;
use tpext\manager\common\Module;
use tpext\builder\traits\actions\HasBase;
use tpext\builder\traits\actions\HasIndex;

/**
 * Undocumented class
 * @title 数据表管理
 */
class Dbtable extends Controller
{
    use HasBase;
    use HasIndex;

    /**
     * Undocumented variable
     *
     * @var AbstractDbLogic
     */
    protected $dbLogic;

    protected $prefix;

    protected function initialize()
    {
        Module::getInstance()->loadLang('dbtable');

        $this->pageTitle = __admin_lang('page_dbtable_manage');

        if (!config('app_debug')) {
            $this->indexText = __admin_lang('msg_not_recommend_production');
        }

        $this->pk = 'TABLE_NAME';
        $this->dbLogic = DbLogic::create();
        $this->prefix = $this->dbLogic->getPrefix();
        $this->sortOrder = 'TABLE_NAME ASC';
        $this->pagesize = 9999; //不产生分页
    }

    protected function filterWhere()
    {
        $searchData = request()->get();

        $where = '';

        if (!empty($searchData['kwd'])) {
            $qkwd = $searchData['kwd'];
            $where .= " AND (TABLE_NAME LIKE '%{$qkwd}%' OR TABLE_COMMENT LIKE '%{$qkwd}%')";
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

        $search->text('kwd', __admin_lang('opt_search_kwd'), 4)->maxlength(55);
    }

    /**
     * 生成数据，如数据不是从`$this->dataModel`得来时，可重写此方法
     * 比如使用db()助手方法、多表join、或以一个自定义数组为数据源
     *
     * @param array $where
     * @param string $sortOrder
     * @param integer $page
     * @param integer $total
     * @return array|\think\Collection|\Generator
     */
    protected function buildDataList($where = [], $sortOrder = '', $page = 1, &$total = -1)
    {
        $data = $this->dbLogic->getTables('TABLE_NAME,TABLE_ROWS,CREATE_TIME,TABLE_COLLATION,TABLE_COMMENT,ENGINE,AUTO_INCREMENT,AVG_ROW_LENGTH,DATA_LENGTH,INDEX_LENGTH,DATA_FREE', '', $sortOrder);

        $total = count($data);

        return $data;
    }

    protected function getProtectedTables()
    {
        ExtLoader::clearCache();
        $extensions = ExtLoader::getExtensions();
        $protectedTables = [];
        foreach ($extensions as $key => $instance) {
            $protectedTables = array_merge($protectedTables, $instance->getProtectedTables());
        }
        array_walk($protectedTables, function (&$value, $key) {
            $value = preg_replace('/__PREFIX__/is', $this->prefix, $value);
        });

        return $protectedTables;
    }

    public function managevalidate()
    {
        $builder = $this->builder();

        $checkFile = App::getRootPath() . 'extend' . DIRECTORY_SEPARATOR . 'validate.txt';

        if (request()->isGet()) {
            if (!file_exists($checkFile)) {
                file_put_contents($checkFile, $this->randstr());
            }

            $form = $builder->form();
            $form->password('validate')->required()->help(!file_exists($checkFile) ? __admin_lang('help_validate_create') : __admin_lang('help_validate'));

            return $builder->render();
        }

        $validate = input('validate');

        if (!file_exists($checkFile)) {
            $this->error(__admin_lang('msg_file_not_exists'));
        }

        $try_validate = Session::get('admin_try_db_manage_validate');
        $errors = 0;

        if (Session::has('admin_try_db_manage_validate_errors')) {
            $errors = Session::get('admin_try_db_manage_validate_errors') > 300 ? 300
                : Session::get('admin_try_db_manage_validate_errors');
        }

        if ($errors > 0 && $try_validate) {

            $time_gone = time() - $try_validate;

            if ($time_gone < $errors) {
                $this->error(sprintf(__admin_lang('msg_too_many_errors'), $errors - $time_gone));
            }
        }

        if (trim($validate) !== trim(file_get_contents($checkFile))) {
            $errors += 1;
            Session::set('admin_try_db_manage_validate', time());
            Session::set('admin_try_db_manage_validate_errors', $errors);
            $this->error(__admin_lang('msg_validate_failed'));
        }

        Session::set('admin_try_db_manage_ok', time());

        $this->success(__admin_lang('msg_validate_done'), url('trash'), '', 1);
    }

    private function randstr($randLength = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHJKLMNPQEST123456789';

        $len = strlen($chars);
        $randStr = '';

        for ($i = 0; $i < $randLength; $i++) {
            $randStr .= $chars[rand(0, $len - 1)];
        }

        return $randStr;
    }

    public function add()
    {
        if (request()->isPost()) {
            return $this->save();
        }

        $builder = $this->builder($this->pageTitle, $this->addText);
        $form = $builder->form();
        $data = [];
        $this->form = $form;
        $this->buildForm(false, $data);
        $form->fill($data);
        return $builder->render();
    }

    public function edit()
    {
        $id = input('id');

        if (request()->isPost()) {
            return $this->save($id);
        }

        $builder = $this->builder($this->pageTitle, $this->editText);
        $data = $this->dbLogic->getTableInfo($id);
        if (!$data) {
            return $builder->layer()->close(0, __admin_lang('msg_data_not_exists'));
        }
        $form = $builder->form();
        $this->form = $form;
        $this->buildForm(true, $data);
        $form->fill($data);

        return $builder->render();
    }

    /**
     * 构建表单
     *
     * @param boolean $isEdit
     * @param array $data
     */
    protected function buildForm($isEdit, &$data = [])
    {
        $form = $this->form;

        $form->text('TABLE_NAME')->required()->maxlength(50)->help($isEdit ? __admin_lang('help_table_name_edit') : __admin_lang('help_table_name'))->default($this->prefix);
        $form->text('TABLE_COMMENT')->required()->maxlength(50)->help(__admin_lang('help_table_comment'));

        if ($isEdit) {

            $form->raw('fields')->value('<a href="#" id="go-fields">' . __admin_lang('btn_go_to_fields') . '</a>');

            $url = url('fieldlist', ['name' => $data['TABLE_NAME']]);

            $this->builder()->addScript("

            $('#go-fields').click(function(){
                var index = parent.layer.getFrameIndex(window.name);

                parent.layer.style(index, {
                    width: ($(parent.window).width() * 0.98) + 'px',
                    left : ($(parent.window).width() * 0.01) + 'px',
                });

                location.href ='{$url}';
            });

            ");

            $data['DATA_SIZE'] = $this->dbLogic->getDataSize($data);

            $form->tab(__admin_lang('help_basic_info'));
            $form->show('TABLE_ROWS');
            $form->show('AUTO_INCREMENT');
            $form->show('DATA_SIZE')->to('{val}MB');
            $form->show('TABLE_COLLATION');
            $form->show('ENGINE');
            $form->show('CREATE_TIME');

            $form->tab(__admin_lang('help_sql_script'));
            $createTableSql = $this->dbLogic->getCreateTableSql($data['TABLE_NAME']);
            $form->raw('sql', ' ')->value(!empty($createTableSql) ? '<pre>' . $createTableSql . '</pre>' : '-')->size(0, 12);
            $protectedTables = $this->getProtectedTables();
            if (in_array($data['TABLE_NAME'], $protectedTables)) {
                $form->readonly();
            }
        } else {
            $pkdata = [
                ['id' => 'pk', 'COLUMN_NAME' => 'id', 'COLUMN_COMMENT' => __admin_lang('label_pk'), 'DATA_TYPE' => $this->dbLogic->getDefaultPkType(), 'LENGTH' => 10, 'ATTR' => $this->dbLogic->getDefaultPkAttr(), '__can_delete__' => 0],
                ['id' => 'create_time', 'COLUMN_NAME' => 'create_time', 'COLUMN_COMMENT' => __admin_lang('label_create_time'), 'DATA_TYPE' => $this->dbLogic->getDefaultDatetimeType(), 'LENGTH' => 0, 'ATTR' => '', '__can_delete__' => 1],
                ['id' => 'update_time', 'COLUMN_NAME' => 'update_time', 'COLUMN_COMMENT' => __admin_lang('label_update_time'), 'DATA_TYPE' => $this->dbLogic->getDefaultDatetimeType(), 'LENGTH' => 0, 'ATTR' => '', '__can_delete__' => 1],
            ];
            //预设字段，在此处就不允许再添加其他字段了。
            $form->items('fields')->dataWithId($pkdata)->canAdd(false)->size(2, 10)
                ->with(
                    $form->text('COLUMN_NAME')->required(),
                    $form->text('COLUMN_COMMENT')->required(),
                    $form->select('DATA_TYPE')->options($this->dbLogic->getFieldTypes())->required()->getWrapper()->addStyle('width:160px;'),
                    $form->text('LENGTH')->getWrapper()->addStyle('width:100px;'),
                    $form->checkbox('ATTR')->options($this->dbLogic->getFieldAttrOptions()['create'])->getWrapper()->addStyle('width:160px;')
                );
        }
    }

    /**
     * 保存数据 范例
     *
     * @param integer $id
     * @return mixed
     */
    protected function save($id = 0)
    {
        $data = request()->only([
            'TABLE_NAME',
            'TABLE_COMMENT',
            'fields',
        ], 'post');

        if ($this->prefix && strpos($data['TABLE_NAME'], $this->prefix)) {
            $data['TABLE_NAME'] = $this->prefix . $data['TABLE_NAME'];
        }

        $result = $this->validate($data, [
            'TABLE_NAME|' . __admin_lang('table_name') => 'require|regex:[a-zA-Z_][a-zA-Z_\d]*',
            'TABLE_COMMENT|' . __admin_lang('table_comment') => 'require',
        ]);

        if (true !== $result) {
            $this->error($result);
        }

        if ($id) {
            $res = $this->dbLogic->updateTable($id, $data);
        } else {

            $res = $this->dbLogic->createTable($data['TABLE_NAME'], $data);
        }

        if (!$res) {
            $this->error(__admin_lang('msg_save_failed') . $this->dbLogic->getErrorsText());
        }

        if ($id) {
            return $this->builder()->layer()->closeRefresh(1, __admin_lang('msg_save_success'));
        }

        $script = "<script>

        parent.$('.search-refresh').trigger('click');
        var index = parent.layer.getFrameIndex(window.name);
        parent.layer.style(index, {
            width: ($(parent.window).width() * 0.98) + 'px',
            left : ($(parent.window).width() * 0.01) + 'px',
        });

        </script>";

        $this->success(__admin_lang('msg_create_table_success'), url('fieldlist', ['name' => $data['TABLE_NAME']]), ['script' => $script], 0.5);
    }

    /**
     * 构建表格
     *
     * @return void
     */
    protected function buildTable(&$data = [], $isExporting = false)
    {
        $protectedTables = $this->getProtectedTables();
        $table = $this->table;
        $table->text('TABLE_NAME')->mapClass($protectedTables, 'disabled')->autoPost('', true)->getWrapper()->addStyle('width:260px');
        $table->text('TABLE_COMMENT')->autoPost('', true)->getWrapper()->addStyle('width:260px');
        $table->raw('TABLE_ROWS');
        $table->show('AUTO_INCREMENT');
        $table->show('DATA_LENGTH')->to('{val} MB');
        $table->raw('DATA_FREE')->to(function ($val, $row) {
            if ($this->dbLogic->needOptimize($row)) {
                return $val . ' MB' . '<a data-url="' . url('optimize', ['name' => $row['TABLE_NAME']]) . '" onclick="layerOpen(this)" href="javascript:;" title="' . __admin_lang('page_optimize') . '" data-layer-size="600px,auto">[' . __admin_lang('btn_optimize') . ']</a>';
            } else {
                return $val . ' MB';
            }
        });
        $table->show('TABLE_COLLATION');
        $table->show('ENGINE');
        $table->show('CREATE_TIME')->getWrapper()->addStyle('width:160px');

        foreach ($data as &$d) {
            $d['DATA_LENGTH'] = $this->dbLogic->getDataSize($d);
            $d['DATA_FREE'] = $this->dbLogic->getDataFreeSize($d);
            $d['TABLE_ROWS'] = '<a target="_blank" title="' . __admin_lang('btn_view_data') . '" href="' . url('datalist', ['name' => $d['TABLE_NAME']]) . '">' . $d['TABLE_ROWS'] . '</a>';
        }

        unset($d);

        $table->getToolbar()
            ->btnAdd('', '', 'btn-primary', 'mdi-plus', 'data-layer-size="1200px,98%"')
            ->btnRefresh()
            ->btnToggleSearch()
            ->btnLink(url('trash'), __admin_lang('btn_trash'), 'btn-danger', 'mdi-delete-variant')
            ->btnOpenChecked(url('backup'), __admin_lang('btn_backup'), 'btn-info', 'mdi-backup-restore', 'data-layer-size="600px;350px;"');

        $table->getActionbar()
            ->btnEdit()
            ->btnLink('fields', url('fieldlist', ['name' => '__data.pk__']), '', 'btn-success', 'mdi-format-list-bulleted-type', 'title="' . __admin_lang('btn_field_manage') . '" data-layer-size="98%,98%"')
            ->btnLink('relations', url('/admin/creator/relations', ['id' => '__data.pk__']), '', 'btn-info', 'mdi-link-variant', 'title="' . __admin_lang('btn_table_relations') . '" data-layer-size="1210px,98%"')
            ->btnLink('lang', url('/admin/creator/lang', ['id' => '__data.pk__']), '', 'btn-danger', 'mdi-translate', 'title="' . __admin_lang('btn_lang_gen') . '"')
            ->btnDelete();

        $table->sortable('TABLE_NAME,TABLE_ROWS,CREATE_TIME,TABLE_COLLATION,AUTO_INCREMENT,DATA_LENGTH,DATA_FREE');
    }

    /**
     * Undocumented function
     * @title 碎片优化
     *
     * @return mixed
     */
    public function optimize($name)
    {
        $builder = $this->builder(__admin_lang('page_optimize'), $name);
        $optimizeSql = $this->dbLogic->getOptimizeSql($name);
        if (request()->isGet()) {
            $form = $builder->form();
            $form->raw('sql')->value("<pre>{$optimizeSql}</pre>");
            $form->raw('op_tips')->value('<p>' . __admin_lang('help_optimize') . '</p>');
            $form->btnSubmit(__admin_lang('btn_execute'));
            $form->btnLayerClose(__admin_lang('btn_cancel'), '6 col-xl-6 col-lg-6 col-sm-6 col-xs-6', 'btn-default');

            return $builder;
        } else {
            $res = $this->dbLogic->optimizeTable($name);
            if ($res) {
                return $builder->layer()->closeRefresh(1, __admin_lang('msg_optimize_success'));
            } else {
                return $builder->layer()->closeRefresh(0, __admin_lang('msg_optimize_failed'));
            }
        }
    }

    /**
     * Undocumented function
     * @title 回收站
     *
     * @return mixed
     */
    public function trash()
    {
        if (empty(Session::get('admin_try_db_manage_ok'))) {
            $this->error(__admin_lang('msg_validate_first'), url('managevalidate'), '', 1);
        }

        $builder = $this->builder($this->pageTitle, __admin_lang('page_trash'));
        $table = $builder->table();
        $table->match('type')->options(['table' => __admin_lang('label_table'), 'field' => __admin_lang('label_field')])->mapClassGroup([['table', 'success'], ['field', 'info']]);
        $table->raw('name');
        $table->show('comment');
        $table->show('delete_time')->getWrapper()->addStyle('width:180px');
        $table->raw('table_data')->getWrapper()->addStyle('width:180px');

        $data = [];

        $deletedTables = $this->dbLogic->getDeletedTables();

        foreach ($deletedTables as $dtable) {
            $arr = explode('_del_at_', $dtable['TABLE_NAME']);
            $data[] = [
                'id' => $dtable['TABLE_NAME'],
                'name' => $dtable['TABLE_NAME'],
                'comment' => $dtable['TABLE_COMMENT'],
                'type' => 'table',
                'delete_time' => date('Y-m-d H:i:s', $arr[1]),
                'table_data' => '<a target="_blank" title="' . __admin_lang('btn_view_data') . '" href="' . url('datalist', ['name' => $dtable['TABLE_NAME']]) . '">' . __admin_lang('btn_view_data') . '</a>',
            ];
        }

        unset($dtable);

        $deletedTables = $this->dbLogic->getTables();

        foreach ($deletedTables as $dtable) {
            $deletedFields = $this->dbLogic->getDeletedFields($dtable['TABLE_NAME']);

            foreach ($deletedFields as $field) {
                $arr = explode('_del_at_', $field['COLUMN_NAME']);
                $data[] = [
                    'id' => $dtable['TABLE_NAME'] . '.' . $field['COLUMN_NAME'],
                    'name' => $dtable['TABLE_NAME'] . '<i style="color:green;">@</i>' . $field['COLUMN_NAME'],
                    'comment' => $field['COLUMN_COMMENT'],
                    'type' => 'field',
                    'delete_time' => date('Y-m-d H:i:s', $arr[1]),
                    'table_data' => '<a target="_blank" title="' . __admin_lang('btn_view_data') . '" href="' . url('datalist', ['name' => $dtable['TABLE_NAME'], 'show_field' => $field['COLUMN_NAME']]) . '">' . __admin_lang('btn_view_data') . '</a>',
                ];
            }
        }

        $table->fill($data);

        $table->getActionbar()
            ->btnDelete(url('destroy'), __admin_lang('btn_destroy'), 'btn-danger', 'mdi-delete', 'title="' . __admin_lang('page_destroy') . '"', __admin_lang('msg_destroy_confirm'))
            ->btnPostRowid('recovery', url('recovery'), __admin_lang('btn_recovery'), 'btn-success', 'mdi-backup-restore', 'title="' . __admin_lang('page_recovery') . '"');

        $table->useCheckbox(false);
        $table->useToolbar(false);

        if (request()->isAjax()) {
            return $table->partial()->render();
        }

        return $builder->render();
    }

    /**
     * Undocumented function
     * @title 批量备份数据库
     *
     * @return mixed
     */
    public function backup()
    {
        $ids = input('get.ids', '');
        $ids = array_filter(explode(',', $ids), 'strlen');
        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }
        $tab_index = input('get.tab_index', 0);
        $save_path = input('get.save_path', '');
        if (empty($save_path)) {
            $save_path = 'dbbackup' . DIRECTORY_SEPARATOR . date('ymdHi') . DIRECTORY_SEPARATOR;
        }
        $filename = input('get.filename', '');
        $start = input('get.start', 0);
        $table = $ids[$tab_index] ?? '';
        $builder = $this->builder();
        $logic = new DbBackupLogic;
        $filename = $logic->getFilename();
        if ($tab_index >= count($ids)) {
            $save_path = rtrim($save_path, DIRECTORY_SEPARATOR);
            $logic->compressDir(preg_replace('/^(.+?dbbackup)\d+$/i', '', $save_path), $save_path . '.zip');
            $builder->display(__admin_lang('msg_backup_done'), ['filename' => 'runtime' . DIRECTORY_SEPARATOR . (ExtLoader::isWebman() ? '' : 'admin' . DIRECTORY_SEPARATOR) . $save_path . '.zip']);
        } else {
            $res = $logic->backupTable($table, $save_path, $filename, $start);
            if ($res[2]) {
                $tab_index += 1;
                $url = url('backup') . '?' . http_build_query(['ids' => implode(',', $ids), 'save_path' => $save_path, 'filename' => '', 'tab_index' => $tab_index]);
                $builder->display('<div class="hidden" id="goon">' . __admin_lang('msg_backup_continue_hint') . '<a href="{$url|raw}">' . __admin_lang('btn_continue') . '</a></div><img src="/assets/tpextbuilder/js/layer/theme/default/loading-1.gif">' . __admin_lang('msg_backup_table_done') . '<script>setTimeout(function(){location.href="{$url|raw}"},1000);setTimeout(function(){$("#goon").removeClass("hidden")},20000);</script>', ['table' => $table, 'url' => $url, 'total' => $res[1], 'next' => $ids[$tab_index] ?? '--']);
            } else {
                $url = url('backup') . '?' . http_build_query(['ids' => implode(',', $ids), 'save_path' => $save_path, 'filename' => $filename, 'start' => $res[0], 'tab_index' => $tab_index]);
                $builder->display('<div class="hidden" id="goon">' . __admin_lang('msg_backup_continue_hint') . '<a href="{$url|raw}">' . __admin_lang('btn_continue') . '</a></div><img src="/assets/tpextbuilder/js/layer/theme/default/loading-1.gif">' . __admin_lang('msg_backup_table_progress') . '<script>setTimeout(function(){location.href="{$url|raw}"},1000);setTimeout(function(){$("#goon").removeClass("hidden")},20000);</script>', ['table' => $table, 'url' => $url, 'total' => $res[1], 'count' => $res[0]]);
            }
        }

        return $builder->render();
    }

    /**
     * Undocumented function
     * @title 恢复已删除的表或字段
     *
     * @return mixed
     */
    public function recovery()
    {
        $ids = input('post.ids', '');
        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $res = 0;
        foreach ($ids as $id) {
            if (strpos($id, '.') !== false) {
                $arr = explode('.', $id);
                if ($this->dbLogic->recoveryField($arr[0], $arr[1])) {
                    $res += 1;
                }
            } else {
                if ($this->dbLogic->recoveryTable($id)) {
                    $res += 1;
                }
            }
        }

        if ($res) {
            $this->success(sprintf(__admin_lang('msg_recovery_success'), $res), '', ['script' => '<script>parent.$(".search-refresh").trigger("click");</script>']);
        } else {
            $this->error(__admin_lang('msg_recovery_failed') . $this->dbLogic->getErrorsText());
        }
    }

    /**
     * Undocumented function
     * @title 彻底删除表或字段
     * @return mixed
     */
    public function destroy()
    {
        if (empty(Session::get('admin_try_db_manage_ok'))) {
            $this->error(__admin_lang('msg_validate_first'), url('managevalidate'), '', 1);
        }

        $ids = input('post.ids', '');
        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $res = 0;
        foreach ($ids as $id) {
            if (strpos($id, '.') !== false) {
                $arr = explode('.', $id);
                if ($this->dbLogic->dropField($arr[0], $arr[1])) {
                    $res += 1;
                }
            } else {
                if ($this->dbLogic->dropTable($id)) {
                    $res += 1;
                }
            }
        }

        if ($res) {
            $this->success(sprintf(__admin_lang('msg_delete_success'), $res));
        } else {
            $this->error(__admin_lang('msg_delete_failed') . $this->dbLogic->getErrorsText());
        }
    }

    public function autopost()
    {
        $id = input('post.id', '');
        $name = input('post.name', '');
        $value = input('post.value', '');

        if (empty($id) || empty($name)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $res = 0;

        if ($name == 'TABLE_COMMENT') {
            $res = $this->dbLogic->changeComment($id, $value);
        } else if ($name == 'TABLE_NAME') {
            $res = $this->dbLogic->changeTableName($id, $value);
        }

        if ($res) {
            $this->success(__admin_lang('msg_edit_success'));
        } else {
            $this->error(__admin_lang('msg_edit_failed_no_change') . $this->dbLogic->getErrorsText());
        }
    }

    public function delete()
    {
        $ids = input('post.ids', '');
        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }
        $protectedTables = $this->getProtectedTables();
        $res = 0;
        foreach ($ids as $id) {
            if (in_array($id, $protectedTables)) {
                $this->error(__admin_lang('msg_table_not_allowed_delete'));
            }
            if ($this->dbLogic->trashTable($id)) {
                $res += 1;
            }
        }

        if ($res) {
            $this->success(sprintf(__admin_lang('msg_delete_success'), $res));
        } else {
            $this->error(__admin_lang('msg_delete_failed') . $this->dbLogic->getErrorsText());
        }
    }

    /**
     * Undocumented function
     *
     * @title 字段管理
     * @return mixed
     */
    public function fieldlist()
    {
        $name = input('name');

        if (request()->isPost()) {
            return $this->savefields($name);
        }

        $builder = $this->builder(__admin_lang('page_field_manage'), $name);

        $form = $builder->form();

        $fields = $this->dbLogic->getFields($name, 'COLUMN_NAME,COLUMN_TYPE,COLUMN_DEFAULT,COLUMN_COMMENT,IS_NULLABLE,NUMERIC_SCALE,NUMERIC_PRECISION,CHARACTER_MAXIMUM_LENGTH,DATETIME_PRECISION,DATA_TYPE');

        $keys = [];

        $moveTo = [];

        foreach ($fields as &$field) {
            if ($this->dbLogic->isInteger($field['DATA_TYPE'])) {
                $field['LENGTH'] = 0;
            } else if ($this->dbLogic->isDecimal($field['DATA_TYPE']) || $this->dbLogic->isChartext($field['DATA_TYPE'])) {
                $field['LENGTH'] = preg_replace('/^\w+\((\d+).+?$/', '$1', $field['COLUMN_TYPE']);
            } else if ($this->dbLogic->isDatetime($field['DATA_TYPE'])) {
                $field['LENGTH'] = !empty($field['DATETIME_PRECISION']) ? $field['DATETIME_PRECISION'] : 0;
            } else {
                $field['LENGTH'] = 0;
            }

            if (strtolower($field['COLUMN_NAME']) == 'id') {
                $field['__can_delete__'] = 0;
            }

            $keys = $this->dbLogic->getKeys($name, $field['COLUMN_NAME']);

            $field['ATTR'] = '';

            $ATTR = [];

            if ($this->dbLogic->hasUnsigned() && strpos($field['COLUMN_TYPE'], 'unsigned')) {
                $ATTR['unsigned'] = 'unsigned';
            }

            foreach ($keys as $key) {
                if (strtoupper($key['INDEX_NAME']) == 'PRIMARY') {
                    $field['__can_delete__'] = 0;
                    $ATTR['index'] = 'index';
                    continue;
                }

                if ($key['NON_UNIQUE'] == 1) {
                    $ATTR['index'] = 'index';
                } else {
                    $ATTR['unique'] = 'unique';
                }
            }

            $field['ATTR'] = implode(',', $ATTR);
            $field['DATA_TYPE'] = strtolower($field['DATA_TYPE']);
            $field['IS_NULLABLE'] = $field['IS_NULLABLE'] == 'YES';

            if (is_null($field['COLUMN_DEFAULT'])) {
                $field['COLUMN_DEFAULT'] = 'NULL';
            } elseif (preg_match('/^nextval\(/', $field['COLUMN_DEFAULT'])) {
                $ATTR['auto_inc'] = 'auto_inc';
                $field['ATTR'] = implode(',', $ATTR);
                $field['COLUMN_DEFAULT'] = '0';
            } else {
                $field['COLUMN_DEFAULT'] = trim($field['COLUMN_DEFAULT'], "'");
            }

            $moveTo[$field['COLUMN_NAME']] = $field['COLUMN_NAME'] . __admin_lang('label_after');
        }

        unset($keys, $field);

        $form->items('fields', ' ')->dataWithId($fields, 'COLUMN_NAME')->size(0, 12);
        $form->text('COLUMN_NAME')->required();
        $form->text('COLUMN_COMMENT')->required();
        $form->select('DATA_TYPE')->options($this->dbLogic->getFieldTypes())->required()->default('varchar')->getWrapper()->addStyle('width:120px;');
        $form->text('LENGTH')->default(0)->getWrapper()->addStyle('width:80px;');
        $form->text('NUMERIC_SCALE')->default(0)->getWrapper()->addStyle('width:60px;');
        $form->text('COLUMN_DEFAULT')->default('');
        $form->switchBtn('IS_NULLABLE')->getWrapper()->addStyle('width:70px;');
        $form->checkbox('ATTR')->options($this->dbLogic->getFieldAttrOptions()['edit'])->getWrapper()->addStyle('width:200px;');

        if ($this->dbLogic->supportsColumnPositioning()) {
            $form->select('MOVE_AFTER')->placeholder(__admin_lang('move_after'))->rendering(function ($field) use ($moveTo) {
                $options = $moveTo;
                unset($options[$field->data['COLUMN_NAME']]); //自身字段名从选项中移除
                $field->options($options);
            })->getWrapper()->addStyle('width:180px;');
        }

        $form->fieldsEnd();

        $protectedTables = $this->getProtectedTables();
        if (in_array($name, $protectedTables)) {
            $form->readonly();
        }

        return $builder->render();
    }

    private function savefields($name)
    {
        $postfields = input('post.fields/a');

        $errors = [];

        foreach ($postfields as $key => &$pfield) {
            $result = $this->validate($pfield, [
                'COLUMN_NAME|' . __admin_lang('column_name') => 'require|regex:[a-zA-Z_][a-zA-Z_\d]*',
                'COLUMN_COMMENT|' . __admin_lang('column_comment') => 'require',
                'DATA_TYPE|' . __admin_lang('data_type') => 'require',
            ]);

            if (true !== $result) {
                $errors[] = '[' . $pfield['COLUMN_NAME'] . ']' . $result;
                continue;
            }

            //switch 关闭状态，无键值
            if (!isset($pfield['IS_NULLABLE'])) {
                $pfield['IS_NULLABLE'] = '0';
            }

            //checkbox 未选中任何一个时，无键值
            $pfield['ATTR'] = isset($pfield['ATTR']) ? $pfield['ATTR'] : [];

            if (strpos($key, '__new__') !== false) {

                $this->dbLogic->addField($name, $pfield);
            } else {

                if (isset($pfield['__del__']) && $pfield['__del__'] == 1) {
                    $pfield['COLUMN_NAME'] .= '_del_at_' . time();
                    $pfield['IS_NULLABLE'] = '1';
                }

                $this->dbLogic->changeField($name, $key, $pfield);
            }
        }

        $errors = array_merge($errors, $this->dbLogic->getErrors());

        if (!empty($errors)) {
            $this->error(sprintf(__admin_lang('msg_save_failed'), implode('<br>', $errors)));
        }

        $this->success(__admin_lang('msg_save_success_refreshing'), url('fieldlist', ['name' => $name]), ['script' => '<script>parent.$(".search-refresh").trigger("click");</script>'], 1);
    }

    /**
     * Undocumented function
     *
     * @title 查看数据
     * @return mixed
     */
    public function datalist()
    {
        $name = input('name');

        $tableInfo = $this->dbLogic->getTableInfo($name);

        $builder = $this->builder(__admin_lang('page_view_data'), $name . '[' . $tableInfo['TABLE_COMMENT'] . ']');

        $table = $builder->table();

        $show_field = input('show_field', '');

        $page = input('__page__/d', 1);
        $page = $page < 1 ? 1 : $page;

        $pk = Db::table($name)->getPk();

        $sortOrder = input('__sort__', !empty($pk) && is_string($pk) ? $pk . ' desc' : '');

        $pagesize = input('__pagesize__/d', 0);

        $pagesize = $pagesize ?: 16;

        $data = Db::table($name)->order($sortOrder)->limit(($page - 1) * $pagesize, $pagesize)->select();

        $fields = $this->dbLogic->getFields($name, 'COLUMN_NAME,COLUMN_COMMENT');

        $deletedFields = $this->dbLogic->getDeletedFields($name);

        $fieldNames = [];

        foreach ($fields as $field) {
            $fieldNames[] = $field['COLUMN_NAME'];

            $table->show($field['COLUMN_NAME'], $field['COLUMN_NAME'] . '<br>' . $field['COLUMN_COMMENT'])->cut(100)->getWrapper()->addStyle('max-width:400px;max-height:100px;');
        }

        unset($field);

        foreach ($deletedFields as $field) {
            $table->show($field['COLUMN_NAME'], ($field['COLUMN_NAME'] == $show_field ? '<i style="color:red;">=></i>' : '') . $field['COLUMN_NAME'] . '<label class="label label-danger">[' . __admin_lang('label_deleted') . ']</label>' . '<br>' . $field['COLUMN_COMMENT'])
                ->cut(100)->getWrapper()->addStyle('max-width:400px;max-height:100px;');
        }

        $table->fill($data);

        $table->paginator(Db::table($name)->count(), $pagesize);

        $table->sortOrder($sortOrder);

        if (!empty($pk) && is_string($pk)) {
            $table->getActionbar()
                ->btnView(url('dataview', ['name' => $name, 'id' => "__data.{$pk}__", 'pk' => $pk]));
        } else {
            $table->useActionbar(false);
        }

        $table->getToolbar()
            ->btnRefresh()
            ->useExport(false);

        $table->sortable($fieldNames);

        $this->builder()->addStyleSheet('
            .field-show
            {
                max-width:100%;max-height:100%;overflow:auto;margin:auto auto;
            }
        ');

        if (request()->isAjax()) {
            return $table->partial()->render();
        }

        return $builder->render();
    }

    /**
     * Undocumented function
     *
     * @title 查看数据-详情
     * @return mixed
     */
    public function dataview()
    {
        $name = input('name');
        $id = input('id');
        $pk = input('pk');

        $tableInfo = $this->dbLogic->getTableInfo($name);

        $builder = $this->builder(__admin_lang('page_view_data_detail'), $name . '[' . $tableInfo['TABLE_COMMENT'] . ']');

        $form = $builder->form();

        $data = Db::table($name)->where($pk, $id)->find();

        $fields = $this->dbLogic->getFields($name, 'COLUMN_NAME,COLUMN_COMMENT');

        foreach ($fields as $field) {

            $form->show($field['COLUMN_NAME'], $field['COLUMN_NAME'] . '(' . $field['COLUMN_COMMENT'] . ')')->fullSize(3);
        }

        $form->fill($data);

        $form->readonly();

        return $builder->render();
    }
}

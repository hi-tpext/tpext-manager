<?php

namespace tpext\manager\admin\controller;

use tpext\think\App;
use think\Controller;
use think\helper\Str;
use tpext\common\ExtLoader;
use tpext\manager\common\Module;
use tpext\builder\common\Wrapper;
use tpext\manager\common\logic\DbLogic;
use tpext\manager\common\logic\AbstractDbLogic;
use tpext\builder\traits\actions\HasBase;
use tpext\builder\traits\actions\HasIndex;
use tpext\manager\common\logic\CreatorLogic;
use tpext\manager\common\model\TableRelation;

/**
 * Undocumented class
 * @title 构建器
 */
class Creator extends Controller
{
    use HasBase;
    use HasIndex;

    /**
     * Undocumented variable
     *
     * @var CreatorLogic
     */
    protected $creatorLogic;

    /**
     * Undocumented variable
     *
     * @var AbstractDbLogic
     */
    protected $dbLogic;

    protected $prefix;

    /**
     * Undocumented variable
     *
     * @var TableRelation
     */
    protected $relationModel;

    protected function initialize()
    {
        Module::getInstance()->loadLang('creator');

        $this->pageTitle = __admin_lang('page_creator');
        $this->pk = 'TABLE_NAME';

        $this->creatorLogic = new CreatorLogic;
        $this->dbLogic = DbLogic::create();

        $this->prefix = $this->dbLogic->getPrefix();

        $this->sortOrder = 'TABLE_NAME ASC';
        $this->pagesize = 9999; //不产生分页

        $this->relationModel = new TableRelation;
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
        $data = $this->dbLogic->getTables('TABLE_NAME,CREATE_TIME,TABLE_COMMENT,TABLE_ROWS,AUTO_INCREMENT', $where, $sortOrder);

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

    public function edit()
    {
        $id = input('id');

        $builder = $this->builder($this->pageTitle, $this->editText);

        $protectedTables = $this->getProtectedTables();
        if (in_array($id, $protectedTables)) {
            return $builder->layer()->close(0, __admin_lang('msg_table_not_allowed'));
        }

        if (request()->isGet()) {
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

        return $this->save($id);
    }

    /**
     * 构建表单
     *
     * @param boolean $isEdit
     * @param array $data
     */
    protected function buildForm($isEdit, &$data = [])
    {
        $table = preg_replace('/^' . $this->prefix . '(.+)$/', '$1', $data['TABLE_NAME']);

        $form = $this->form;
        $fields = $this->dbLogic->getFields($data['TABLE_NAME'], 'COLUMN_NAME,COLUMN_TYPE,COLUMN_DEFAULT,COLUMN_COMMENT,IS_NULLABLE,NUMERIC_SCALE,NUMERIC_PRECISION,CHARACTER_MAXIMUM_LENGTH,DATA_TYPE');
        $form->hidden('TABLE_NAME');
        $form->raw('model_namespace', __admin_lang('model_namespace'))->value('<b>app\\' . Module::getInstance()->config('model_namespace') . '\\model\\</b>' . __admin_lang('help_model_namespace'));
        $form->text('controller')->default(ucfirst(strtolower(Str::studly($table))))->help(__admin_lang('help_controller_name'));
        $form->text('controller_title')->default($data['TABLE_COMMENT'])->required();
        $form->hidden('model_title')->default($data['TABLE_COMMENT']);

        $form->switchBtn('table_build')->default(1);
        $form->checkbox('table_toolbars')->options(['add' => __admin_lang('btn_add'), 'delete' => __admin_lang('btn_delete'), 'export' => __admin_lang('btn_export'), 'enable' => __admin_lang('btn_enable'), 'import' => __admin_lang('btn_import')])
            ->default('add,delete,export')->checkallBtn()->help(__admin_lang('help_table_toolbars'));
        $form->checkbox('table_actions')->options(['edit' => __admin_lang('btn_edit'), 'view' => __admin_lang('btn_view'), 'delete' => __admin_lang('btn_delete'), 'enable' => __admin_lang('btn_enable')])
            ->default('edit,view,delete')->checkallBtn()->help(__admin_lang('help_table_actions'));
        $form->text('enable_field', __admin_lang('enable_field'))->help(__admin_lang('help_enable_field'));

        foreach ($fields as &$field) {
            $field['DISPLAYER_TYPE'] = 'show';

            $hasSearch = true;
            if (preg_match('/^\w*?(?:openid|salt|token)$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)s?$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)s?$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^\w*?icon$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^(?:delete_time|delete_at)$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^\w*?(?:map|lat|lng|latitude|longitude)$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^\w*?(?:number|num|quantity|qty)$/i', $field['COLUMN_NAME'])) {
                $hasSearch = false;
            } else if (preg_match('/^(?:updated?_time|updated?_at)$/i', $field['COLUMN_NAME']) || in_array($field['COLUMN_NAME'], ['id', 'sort'])) {
                $hasSearch = false;
            }

            if ($hasSearch) {
                $field['ATTR'][] = 'search';
            }

            $field['FIELD_RELATION'] = '';

            $relation = $this->relationModel->where(['local_table_name' => $data['TABLE_NAME'], 'foreign_key' => $field['COLUMN_NAME']])->find();

            if ($relation) {
                $field['FIELD_RELATION'] = $relation['relation_name'] . '.name';
            }

            if (
                $this->dbLogic->isInteger($field['DATA_TYPE'])
                || $this->dbLogic->isDecimal($field['DATA_TYPE'])
                || (preg_match('/^.*(date|time)$/i', $field['COLUMN_NAME']))
                || $field['COLUMN_NAME'] == 'sort'
            ) {
                $field['ATTR'][] = 'sortable';
            }

            if ($field['FIELD_RELATION']) {
                $field['DISPLAYER_TYPE'] = 'belongsTo';
            } else if (preg_match('/^(?:parent_id|pid)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'belongsTo';
                $field['FIELD_RELATION'] = 'parent.name';
            } else if (preg_match('/^(\w+)_id$/i', $field['COLUMN_NAME'], $mch)) {
                $field['DISPLAYER_TYPE'] = 'belongsTo';
                $field['FIELD_RELATION'] = Str::camel($mch[1]) . '.name';
            } else if (preg_match('/^(\w+)_ids$/i', $field['COLUMN_NAME'], $mch)) {
                $field['DISPLAYER_TYPE'] = 'matches';
                $field['FIELD_RELATION'] = strtolower($mch[1]) . '[text, id]';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'image';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'images';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'file';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'files';
            } else if (preg_match('/^(?:is_\w+|has_\w+|on_\w+|enabled?)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'switchBtn';
            } else if (preg_match('/^\w*?(?:status|state)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'match';
            } else if (preg_match('/^\w*?(?:password|pwd)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:openid|salt|token)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?icon$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^(?:delete_time|delete_at)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:map|lat|lng|latitude|longitude)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^\w*?(?:number|num|quantity|qty)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            }
        }

        $form->items('TABLE_FIELDS', ' ')->dataWithId($fields, 'COLUMN_NAME')->size(0, 12)->showLabel(false)
            ->with(
                $form->text('COLUMN_NAME')->readonly(),
                $form->text('COLUMN_TYPE')->readonly()->getWrapper()->addStyle('width:140px;'),
                $form->text('COLUMN_COMMENT')->readonly(),
                $form->select('DISPLAYER_TYPE')->texts(array_keys(Wrapper::getDisplayersMap()))
                    ->beforOptions(['_' => __admin_lang('label_none'), 'belongsTo' => 'belongsTo'])->required(),
                $form->checkbox('ATTR')->options(['sortable' => __admin_lang('label_sortable'), 'search' => __admin_lang('label_search')]),
                $form->text('FIELD_RELATION')
            )->canNotAddOrDelete();

        foreach ($fields as &$field) {
            $field['DISPLAYER_TYPE'] = 'text';
            $field['FIELD_RELATION'] = '';
            $field['ATTR'] = [];

            $relation = $this->relationModel->where(['local_table_name' => $data['TABLE_NAME'], 'foreign_key' => $field['COLUMN_NAME']])->where('relation_type', 'in', ['belongs_to', 'has_one'])->find();

            if ($relation) {
                $field['FIELD_RELATION'] = '/admin/' . strtolower(Str::studly($relation['relation_name'])) . '/selectpage';
            }

            if ($field['FIELD_RELATION']) {
                $field['DISPLAYER_TYPE'] = 'select';
            } else if ($field['COLUMN_NAME'] == 'id') {
                $field['DISPLAYER_TYPE'] = 'hidden';
            } else if (preg_match('/^(?:parent_id|pid)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'select';
                $field['FIELD_RELATION'] = 'selectpage';
            } else if (preg_match('/^(\w+)_id$/i', $field['COLUMN_NAME'], $mch)) {
                $field['DISPLAYER_TYPE'] = 'select';
                $field['FIELD_RELATION'] = '/admin/' . strtolower(Str::studly($mch[1])) . '/selectpage';
            } else if (preg_match('/^(\w+)_ids$/i', $field['COLUMN_NAME'], $mch)) {
                $field['DISPLAYER_TYPE'] = 'multipleSelect';
                $field['FIELD_RELATION'] = '/admin/' . strtolower(Str::studly($mch[1])) . '/selectpage';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'image';
            } else if (preg_match('/^\w*?(?:img|image|pic|photo|avatar|logo)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'images';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'file';
            } else if (preg_match('/^\w*?(?:file|video|audio|pkg)s$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'files';
            } else if (preg_match('/^\w*?icon$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'icon';
            } else if (preg_match('/^(?:delete_time|delete_at)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = '_';
            } else if (preg_match('/^(?:created?_time|add_time|created?_at|updated?_time|updated?_at)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'show';
            } else if (preg_match('/date$/', $field['COLUMN_NAME']) || preg_match('/date$/i', $field['COLUMN_TYPE'])) {
                $field['DISPLAYER_TYPE'] = 'date';
            } else if (preg_match('/time$/', $field['COLUMN_NAME']) || preg_match('/(?:datetime|timestamp)$/i', $field['COLUMN_TYPE'])) {
                $field['DISPLAYER_TYPE'] = 'datetime';
            } else if (preg_match('/^\w*?content$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'editor';
            } else if (preg_match('/^\w*?(?:remark|desc|description)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'textarea';
            } else if (preg_match('/^(?:is_\w+|has_\w+|on_\w+|enabled?)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'switchBtn';
            } else if (preg_match('/^\w*?(?:status|state)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'radio';
            } else if (preg_match('/^\w*?(?:password|pwd)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'password';
            } else if (preg_match('/^\w*?(?:openid|salt|token)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'show';
            } else if (preg_match('/^\w*?(?:tags|kwds)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'tags';
            } else if (preg_match('/^\w*?(?:map|lat|lng|latitude|longitude)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'map';
            } else if (preg_match('/^\w*?color$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'color';
            } else if (preg_match('/^\w*?(?:number|num|quantity|qty)$/i', $field['COLUMN_NAME'])) {
                $field['DISPLAYER_TYPE'] = 'number';
            }
        }

        $form->switchBtn('form_build')->default(1);
        $form->items('FORM_FIELDS', ' ')->dataWithId($fields, 'COLUMN_NAME')->size(0, 12)->showLabel(false)
            ->with(
                $form->text('COLUMN_NAME')->readonly(),
                $form->text('COLUMN_TYPE')->readonly()->getWrapper()->addStyle('width:140px;'),
                $form->text('COLUMN_COMMENT')->readonly(),
                $form->select('DISPLAYER_TYPE')->texts(array_keys(Wrapper::getDisplayersMap()))
                    ->beforOptions(['_' => __admin_lang('label_none'), 'belongsTo' => 'belongsTo'])->required(),
                $form->checkbox('ATTR')->options(['required' => __admin_lang('label_required')]),
                $form->text('FIELD_RELATION')
            )->canNotAddOrDelete();
    }

    /**
     * 保存数据 范例
     *
     * @param integer $id
     * @return mixed
     */
    protected function save($id = 0)
    {
        $data = request()->post();

        if ($data['table_build'] == 0 && $data['form_build'] == 0) {
            $this->error(__admin_lang('msg_select_table_or_form'));
        }

        $tableToolbars = $data['table_toolbars'] ?? [];
        $tableActions = $data['table_actions'] ?? [];

        if ($data['form_build'] == 0 && (in_array('add', $tableToolbars) || in_array('edit', $tableActions) || in_array('view', $tableActions))) {
            $this->error(__admin_lang('msg_form_required'));
        }

        $this->creatorLogic->make($data, $this->prefix, Module::getInstance()->config('model_namespace'));

        $dir = '';
        $controllerName = '';

        if (preg_match('/^\w+$/', $data['controller'])) {
            $dir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'controller', '']);

            $controllerName = ucfirst(strtolower(Str::studly($data['controller'])));
        } else if (preg_match('/^(\w+)[\/](\w+)$/', $data['controller'], $mch)) {
            $dir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'controller', strtolower($mch[1]), '']);

            $controllerName = ucfirst(strtolower(Str::studly($mch[2])));
        } else {
            $this->error(__admin_lang('msg_controller_name_error'));
        }

        $fileName = $dir . $controllerName . '.php';

        if (!$this->creatorLogic->saveFile($dir, $fileName, implode(PHP_EOL, $this->creatorLogic->getLins()))) {
            $this->error(sprintf(__admin_lang('msg_controller_file_save_failed'), $fileName));
        }

        $modelNamespace = '';
        $mdir = '';
        if (Module::getInstance()->config('model_namespace') == 'common') {
            $modelNamespace = 'app\\common\\model';
            $mdir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'common', 'model', '']);
        } else {
            $modelNamespace = 'app\\admin\\model';
            $mdir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'model', '']);
        }

        if (!is_dir($mdir)) {
            mkdir($mdir, 0755, true);
        }

        $table = preg_replace('/^' . $this->prefix . '(.+)$/', '$1', $data['TABLE_NAME']);

        $modelName = Str::studly($table);

        $modelFileName = $mdir . $modelName . '.php';

        $relations = $this->relationModel->where('local_table_name', $data['TABLE_NAME'])->select();

        $res = 0;
        if (!is_file($modelFileName)) {
            $res = file_put_contents($modelFileName, implode(PHP_EOL, $this->creatorLogic->getModelLines($modelNamespace, $table, $data, $relations, $this->prefix)));
        } else {
            $res = file_put_contents($modelFileName, implode(PHP_EOL, $this->creatorLogic->getModelRelationLines($modelFileName, $relations, $this->prefix)));
        }

        $fields = $this->dbLogic->getFields($data['TABLE_NAME'], 'COLUMN_NAME,COLUMN_COMMENT');

        $ldir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'lang', App::getDefaultLang(), '']);

        if (!is_dir($ldir)) {
            mkdir($ldir, 0755, true);
        }

        if (!is_file($ldir . strtolower($modelName) . '.php')) {
            file_put_contents($ldir . strtolower($modelName) . '.php', implode(PHP_EOL, $this->creatorLogic->getLangLines($data, $fields)));
        }

        if ($res) {
            return $this->builder()->layer()->closeRefresh(1, sprintf(__admin_lang('msg_controller_generate_success'), $fileName));
        } else {
            return $this->builder()->layer()->closeRefresh(1, sprintf(__admin_lang('msg_controller_model_generate_success'), $fileName));
        }
    }

    /**
     * 构建表格
     *
     * @return void
     */
    protected function buildTable(&$data = [], $isExporting = false)
    {
        $table = $this->table;

        $table->show('TABLE_NAME');
        $table->show('TABLE_COMMENT');
        $table->raw('TABLE_ROWS');
        $table->show('AUTO_INCREMENT');
        $table->show('CREATE_TIME');
        $table->raw('TABLE_RELATIONS');

        $table->getToolbar()
            ->btnLink(url('scanModels'), __admin_lang('btn_scan_models'), 'btn-warning', 'mdi-search-web', 'title="' . __admin_lang('page_scan_models') . '"')
            ->btnRefresh()
            ->btnToggleSearch();

        $table->getActionbar()
            ->btnEdit('', __admin_lang('btn_generate'), 'btn-success', 'mdi-code-braces', 'title="' . __admin_lang('btn_generate') . '" data-layer-size="1210px,98%"')
            ->btnLink('relations', url('relations', ['id' => '__data.pk__']), __admin_lang('btn_relations'), 'btn-info', 'mdi-link-variant', 'title="' . __admin_lang('page_table_relations') . '" data-layer-size="1210px,98%"')
            ->btnLink('lang', url('lang', ['id' => '__data.pk__']), __admin_lang('btn_lang'), 'btn-danger', 'mdi-translate', 'title="' . __admin_lang('page_lang_gen') . '"');

        $table->useCheckbox(false);

        foreach ($data as &$d) {
            $relations = $this->relationModel->where('local_table_name', $d['TABLE_NAME'])->column('relation_name');
            $names = [];
            foreach ($relations as $rl) {
                $names[] = '<label class="label label-dark">' . $rl . '</label>';
            }

            $d['TABLE_RELATIONS'] = count($names) ? implode('、', $names) : '<label class="label label-default">' . __admin_lang('label_no_relations') . '</label>';
        }
    }

    /**
     * Undocumented function
     * @title 扫描模型关联
     * @return mixed
     */
    public function scanModels()
    {
        $modelNamespace = '';
        if (Module::getInstance()->config('model_namespace') == 'common') {
            $modelNamespace = 'app\\common\\model';
        } else {
            $modelNamespace = 'app\\admin\\model';
        }
        $logic = new CreatorLogic;
        $logic->scanModelsForNamespace($modelNamespace);

        return $this->builder()->layer()->closeRefresh(1, __admin_lang('msg_scan_done'));
    }

    /**
     * Undocumented function
     * @title 表关联管理
     * @return mixed
     */
    public function relations()
    {
        $id = input('id');

        $builder = $this->builder($this->pageTitle, __admin_lang('page_table_relations'));
        $protectedTables = $this->getProtectedTables();
        if (in_array($id, $protectedTables)) {
            return $builder->layer()->close(0, __admin_lang('msg_table_not_allowed_op'));
        }

        $tableInfo = $this->dbLogic->getTableInfo($id);

        if (!$tableInfo) {
            return $builder->layer()->close(0, __admin_lang('msg_data_not_exists'));
        }

        $modelNamespace = '';
        $mdir = '';
        if (Module::getInstance()->config('model_namespace') == 'common') {
            $modelNamespace = 'app\\common\\model';
            $mdir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'common', 'model', '']);
        } else {
            $modelNamespace = 'app\\admin\\model';
            $mdir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'model', '']);
        }

        if (!is_dir($mdir)) {
            mkdir($mdir, 0755, true);
        }

        $table = preg_replace('/^' . $this->prefix . '(.+)$/', '$1', $id);

        $modelName = Str::studly($table);

        $modelFileName = $mdir . $modelName . '.php';

        if (request()->isGet()) {

            $tables = $this->dbLogic->getTables('TABLE_NAME');

            $relations = $this->relationModel->where('local_table_name', $id)->select();

            foreach ($relations as $key => &$pdata) {
                if ($pdata['relation_type'] == 'belongs_to') {
                    $pdata['field_name'] = $pdata['foreign_key'];
                    $pdata['relation_key'] = $pdata['local_key'];
                } else {
                    $pdata['relation_key'] = $pdata['foreign_key'];
                    $pdata['field_name'] = $pdata['local_key'];
                }
            }

            $fields = $this->dbLogic->getFields($id, 'COLUMN_NAME');

            $form = $builder->form();

            $form->tab(__admin_lang('relations'));

            $form->show('TABLE_NAME')->value($id);
            $form->raw('model_namespace')->value('<b>app\\' . Module::getInstance()->config('model_namespace') . '\\model\\</b>' . __admin_lang('help_model_namespace'));
            if (is_file($modelFileName)) {
                $form->raw('tips')->value(sprintf(__admin_lang('msg_model_exists_overwrite'), '<b>' . str_replace(App::getRootPath(), '', $modelFileName) . '</b>'));
            }
            $form->text('model_title')->default($tableInfo['TABLE_COMMENT'])->required();

            $form->items('relations')->dataWithId($relations)->size(12, 12)->with(
                $form->select('field_name')->required()->optionsData($fields, 'COLUMN_NAME', 'COLUMN_NAME'),
                $form->select('relation_type')->required()->options(['belongs_to' => 'belongsTo', 'has_one' => 'hasOne', 'has_many' => 'hasMany'])->default('belongs_to'),
                $form->select('foreign_table_name')->required()->optionsData($tables, 'TABLE_NAME', 'TABLE_NAME')->withNext(
                    $form->select('relation_key')->required()->dataUrl(url('slecltfields'), 'COLUMN_NAME', 'COLUMN_NAME')
                ),
                $form->text('relation_name')
            );


            $form->tab(__admin_lang('help_relation_demo'));
            $form->raw('demo', '')->size(12, 12)->showLabel(false)->value(__admin_lang('label_example') . '：<pre>' .
                '
//Product basic info table
class ShopGoods extends Model
{
    protected $name = \'shop_goods\';

    public function category()    //category: relation name. If not set, derived from the related table name in camelCase: shopCategory.
    {
        //     category_id  : field         [category_id] field in [shop_goods] table
        //              id  : related field  [id] field in [shop_category] table
        //       belongsTo  : relation type
        //    shop_category : related table   table name [shop_category] corresponding to [ShopCategory] model
        return \$this->belongsTo(ShopCategory::class, \'category_id\', \'id\');
    }

    public function extendInfo()   // extendInfo: relation name. If not set, derived from the related table name in camelCase: shopGoodsExtend.
    {
        //              id   : field         [id] field in [shop_goods] table
        //        goods_id   : related field  [goods_id] field in [shop_goods_extend] table
        //          hasOne   : relation type
        // shop_goods_extend : related table   table name [shop_goods_extend] corresponding to [ShopGoodsExtend] model
        return \$this->hasOne(ShopGoodsExtend::class, \'extend_id\', \'id\');
    }

    // $data = ShopGoods::where(\'id\', 1)->find();
    // For camelCase relations like [shopCategory], there are 3 ways to access:
    // 1.Keep original    => $data[\'shopCategory\'];
    // 2.All lowercase    => $data[\'shopcategory\']; (PHP feature: function/method names are case-insensitive)
    // 3.Camel to snake   => $data[\'shop_category\'];
    //Usage
    //$table->show(\'shopCategory.name\', \'Category\');
    //$form->show(\'shop_category.name\', \'Category\');
}

//Product category table
class ShopCategory extends Model
{
    protected $name = \'shop_category\';
}

//Product extension info table
class ShopGoodsExtend extends Model
{
    protected $name = \'shop_goods_extend\';
}

'
                . '</pre>')
                ->help(__admin_lang('help_relation_demo'));

            return $builder->render();
        }

        $relations = input('post.relations/a', []);

        if (count($relations)) {
            $errors = [];
            $changes = 0;

            foreach ($relations as $key => &$pdata) {
                $pdata['local_table_name'] = $id;
                $dataModel = new TableRelation;

                $result = $this->validate($pdata, [
                    'field_name|' . __admin_lang('field_name') => 'require',
                    'relation_type|' . __admin_lang('relation_type') => 'require',
                    'foreign_table_name|' . __admin_lang('foreign_table_name') => 'require',
                    'relation_key|' . __admin_lang('relation_key') => 'require',
                    'relation_name|' . __admin_lang('relation_name') => 'regex:[a-z0-9A-Z_]{0,}',
                ]);

                if (true !== $result) {
                    $errors[] = '[' . $pdata['field_name'] . ']' . $result;
                    continue;
                }

                if ($pdata['local_table_name'] == $pdata['foreign_table_name'] && $pdata['field_name'] == $pdata['relation_key']) {
                    $errors[] = '[' . $pdata['field_name'] . ']' . __admin_lang('msg_field_relation_error');
                    continue;
                }

                $is_del = isset($pdata['__del__']) && $pdata['__del__'] == 1;
                $is_add = strpos($key, '__new__') !== false;

                if ($pdata['relation_type'] == 'belongs_to') {
                    $pdata['foreign_key'] = $pdata['field_name'];
                    $pdata['local_key'] = $pdata['relation_key'];
                } else {
                    $pdata['foreign_key'] = $pdata['relation_key'];
                    $pdata['local_key'] = $pdata['field_name'];
                }

                if (empty($pdata['relation_name'])) {
                    $pdata['relation_name'] = Str::camel(preg_replace('/^' . $this->prefix . '/', '', $pdata['foreign_table_name']) . ($pdata['relation_type'] == 'has_many' ? 's' : ''));
                }

                if ($is_add) {
                    $res = $dataModel->save($pdata);
                    if ($res) {
                        $changes += 1;
                    } else {
                        $errors[] = '[' . $pdata['field_name'] . ']' . __admin_lang('msg_field_save_error');
                    }
                } else {
                    if ($is_del) {
                        $res = $dataModel::destroy($key);
                        if ($res) {
                            $changes += 1;
                        }
                    } else {
                        $res = 0;
                        $exists = $dataModel->where(['id' => $key])->find();
                        if ($exists) {
                            $res = $exists->force()->save($pdata);
                        }

                        if ($res) {
                            $changes += 1;
                        } else {
                            $errors[] = '[' . $pdata['field_name'] . ']' . __admin_lang('msg_field_save_error');
                        }
                    }
                }
            }

            if ($changes) {
                if (!empty($errors)) {
                    $this->error(sprintf(__admin_lang('msg_save_relation_failed'), implode('<br>', $errors)));
                }
            } else {
                $this->error(sprintf(__admin_lang('msg_save_relation_failed'), implode('<br>', $errors)));
            }
        }

        $relations = $this->relationModel->where('local_table_name', $id)->select();

        $data = request()->post();

        $res = 0;
        if (!is_file($modelFileName)) {
            $res = file_put_contents($modelFileName, implode(PHP_EOL, $this->creatorLogic->getModelLines($modelNamespace, $table, $data, $relations, $this->prefix)));
        } else {
            if (count($relations)) {
                $res = file_put_contents($modelFileName, implode(PHP_EOL, $this->creatorLogic->getModelRelationLines($modelFileName, $relations, $this->prefix)));
            } else {
                $res = 1;
            }
        }

        if ($res) {
            return $builder->layer()->closeRefresh(1, sprintf(__admin_lang('msg_save_success'), $modelFileName));
        } else {
            $this->error(sprintf(__admin_lang('msg_model_file_save_failed'), $modelFileName));
        }
    }

    /**
     * Undocumented function
     * @title 下拉选择字段
     * @return mixed
     */
    public function slecltfields()
    {
        $table = input('prev_val');
        $selected = input('selected');
        $q = input('q');

        if ($selected) {
            return json(
                [
                    'data' => [['COLUMN_NAME' => $selected]],
                ]
            );
        }
        $where = '';

        if ($q) {
            $where = "COLUMN_NAME LIKE '%{$q}%'";
        }

        $fields = $this->dbLogic->getFields($table, 'COLUMN_NAME', $where);

        return json(
            [
                'data' => $fields,
                'has_more' => false
            ]
        );
    }

    /**
     * Undocumented function
     * @title 翻译生成
     * @return mixed
     */
    public function lang()
    {
        $id = input('id');

        $builder = $this->builder($this->pageTitle, __admin_lang('page_lang_gen'));
        $protectedTables = $this->getProtectedTables();
        if (in_array($id, $protectedTables)) {
            return $builder->layer()->close(0, __admin_lang('msg_table_not_allowed'));
        }
        $fields = $this->dbLogic->getFields($id, 'COLUMN_NAME,COLUMN_TYPE,COLUMN_COMMENT');

        $tableInfo = $this->dbLogic->getTableInfo($id);

        $table = preg_replace('/^' . $this->prefix . '(.+)$/', '$1', $id);

        $modelName = Str::studly($table);

        $ldir = App::getRootPath() . implode(DIRECTORY_SEPARATOR, ['app', 'admin', 'lang', App::getDefaultLang(), '']);

        if (!is_dir($ldir)) {
            mkdir($ldir, 0755, true);
        }

        $filePath = $ldir . strtolower($modelName) . '.php';

        if (!$tableInfo) {
            return $builder->layer()->close(0, __admin_lang('msg_data_not_exists'));
        }

        if (request()->isGet()) {

            $form = $builder->form();

            $form->show('TABLE_NAME')->value($id);
            $form->hidden('controller_title')->value($tableInfo['TABLE_COMMENT'])->required();

            if (is_file($filePath)) { //翻译文件存在，读取
                $langData = include $filePath;
                foreach ($langData as $key => $val) {
                    $find = false;
                    foreach ($fields as &$field) {
                        if ($field['COLUMN_NAME'] == $key) {
                            $field['COLUMN_COMMENT'] = $val;
                            $field['__can_delete__'] = 0;
                            $field['__readonly__fields__'] = ['COLUMN_NAME'];
                            $find = true;
                            break;
                        }
                    }

                    if (!$find) {
                        $fields[] = [
                            'COLUMN_NAME' => $key,
                            'COLUMN_COMMENT' => $val,
                            'COLUMN_TYPE' => '--',
                            '__can_delete__' => 1
                        ];
                    }
                }

                $form->raw('tips')->value(sprintf(__admin_lang('msg_lang_exists_overwrite'), '<b>' . str_replace(App::getRootPath(), '', $filePath) . '</b>'));
            } else {
                foreach ($fields as &$field) {
                    $field['__can_delete__'] = 0;
                }
            }

            $form->items('FORM_FIELDS', ' ')->dataWithId($fields, 'COLUMN_NAME')->size(12, 12)
                ->with(
                    //不推荐rendering的方式，使用上面设置__readonly__fields__的方式代替
                    $form->text('COLUMN_NAME')->rendering(function ($field) {
                        if (!isset($field->data['__can_delete__']) || $field->data['__can_delete__'] == 0) {
                            $field->readonly();
                        } else {
                            $field->readonly(false);
                        }
                    })->required(),
                    $form->show('COLUMN_TYPE')->default('--'),
                    $form->text('COLUMN_COMMENT')->required()
                )->help(__admin_lang('help_lang_items'));

            $this->builder()->addScript("$('#items-FORM_FIELDS-temple .row-COLUMN_NAME').removeAttr('readonly');");

            return $builder->render();
        }

        $data = request()->post();

        $newData = [];

        foreach ($data['FORM_FIELDS'] as $field) {
            if (!(isset($field['__del__']) && $field['__del__'] == 1)) {
                $newData[$field['COLUMN_NAME']] = $field;
            }
        }
        $data['FORM_FIELDS'] = [];

        $res = file_put_contents($filePath, implode(PHP_EOL, $this->creatorLogic->getLangLines($data, $newData)));

        if ($res) {
            return $this->builder()->layer()->closeRefresh(1, sprintf(__admin_lang('msg_lang_generate_success'), $filePath));
        } else {
            $this->error(__admin_lang('msg_lang_file_save_failed'));
        }
    }
}

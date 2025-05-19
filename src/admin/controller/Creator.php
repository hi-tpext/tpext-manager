<?php

namespace tpext\manager\admin\controller;

use tpext\think\App;
use think\Controller;
use think\helper\Str;
use tpext\common\ExtLoader;
use tpext\manager\common\Module;
use tpext\builder\common\Wrapper;
use tpext\manager\common\logic\DbLogic;
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
     * @var DbLogic
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
        $this->pageTitle = '构建器';
        $this->pk = 'TABLE_NAME';

        $this->creatorLogic = new CreatorLogic;
        $this->dbLogic = new DbLogic;

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
            $where .= " AND (`TABLE_NAME` LIKE '%{$searchData['kwd']}%' OR `TABLE_COMMENT` LIKE '%{$searchData['kwd']}%')";
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

        $search->text('kwd', '表名/表注释', 4)->maxlength(55);
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
            return $builder->layer()->close(0, '此表不能允许生成代码');
        }

        if (request()->isGet()) {
            $data = $this->dbLogic->getTableInfo($id);
            if (!$data) {
                return $builder->layer()->close(0, '数据不存在');
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
        $form->raw('model_namespace', 'model命名空间')->value('<b>app\\' . Module::getInstance()->config('model_namespace') . '\\model\\</b>可在扩展配置中修改');
        $form->text('controller', 'Controller名称')->default(ucfirst(strtolower(Str::studly($table))))->help('支持二级目录，如：shop/order');
        $form->text('controller_title', '控制器注释')->default($data['TABLE_COMMENT'])->required();
        $form->hidden('model_title')->default($data['TABLE_COMMENT']);

        $form->switchBtn('table_build', '表格生成')->default(1);
        $form->checkbox('table_toolbars', '表格工具')->options(['add' => '添加', 'delete' => '批量删除', 'export' => '导出', 'enable' => '批量禁用/启用', 'import' => '导入'])
            ->default('add,delete,export')->checkallBtn()->help('未选择任何一项则禁用工具栏');
        $form->checkbox('table_actions', '表格动作')->options(['edit' => '编辑', 'view' => '查看', 'delete' => '删除', 'enable' => '禁用/启用'])
            ->default('edit,view,delete')->checkallBtn()->help('未选择任何一项则禁用动作栏');
        $form->text('enable_field', '禁用/启用字段名称')->help('若使用[禁用/启用]工具栏、动作栏');

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
                $form->text('COLUMN_NAME', '字段名')->readonly(),
                $form->text('COLUMN_TYPE', '字段类型')->readonly()->getWrapper()->addStyle('width:140px;'),
                $form->text('COLUMN_COMMENT', '字段注释')->readonly(),
                $form->select('DISPLAYER_TYPE', '生成类型')->texts(array_keys(Wrapper::getDisplayersMap()))
                    ->beforOptions(['_' => '无', 'belongsTo' => 'belongsTo'])->required(),
                $form->checkbox('ATTR', '属性')->options(['sortable' => '排序', 'search' => '搜索']),
                $form->text('FIELD_RELATION', '其他信息')
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

        $form->switchBtn('form_build', '表单生成')->default(1);
        $form->items('FORM_FIELDS', ' ')->dataWithId($fields, 'COLUMN_NAME')->size(0, 12)->showLabel(false)
            ->with(
                $form->text('COLUMN_NAME', '字段名')->readonly(),
                $form->text('COLUMN_TYPE', '字段类型')->readonly()->getWrapper()->addStyle('width:140px;'),
                $form->text('COLUMN_COMMENT', '字段注释')->readonly(),
                $form->select('DISPLAYER_TYPE', '生成类型')->texts(array_keys(Wrapper::getDisplayersMap()))
                    ->beforOptions(['_' => '无', 'belongsTo' => 'belongsTo'])->required(),
                $form->checkbox('ATTR', '属性')->options(['required' => '必填']),
                $form->text('FIELD_RELATION', '其他信息')
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
            $this->error('请选择创建表格或表单');
        }

        $tableToolbars = $data['table_toolbars'] ?? [];
        $tableActions = $data['table_actions'] ?? [];

        if ($data['form_build'] == 0 && (in_array('add', $tableToolbars) || in_array('edit', $tableActions) || in_array('view', $tableActions))) {
            $this->error('有添加/删除/查看时必须生成表单[form]');
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
            $this->error('控制器名称有误');
        }

        $fileName = $dir . $controllerName . '.php';

        if (!$this->creatorLogic->saveFile($dir, $fileName, implode(PHP_EOL, $this->creatorLogic->getLins()))) {
            $this->error('控制器文件保失败：' . $fileName);
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
            return $this->builder()->layer()->closeRefresh(1, '生成控制器成功，文件保存在：' . $fileName);
        } else {
            return $this->builder()->layer()->closeRefresh(1, '生成控制器成功，文件保存在：' . $fileName . '，model文件生成失败');
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

        $table->show('TABLE_NAME', '表名');
        $table->show('TABLE_COMMENT', '表注释');
        $table->raw('TABLE_ROWS', '记录条数');
        $table->show('AUTO_INCREMENT', '自增id');
        $table->show('CREATE_TIME', '创建时间');
        $table->raw('TABLE_RELATIONS', '表关联');

        $table->getToolbar()
            ->btnLink(url('scanModels'), '模型关联扫描', 'btn-warning', 'mdi-search-web', 'title="查找模型中手动定义的关联并存入数据库"')
            ->btnRefresh()
            ->btnToggleSearch();

        $table->getActionbar()
            ->btnEdit('', '生成', 'btn-success', 'mdi-code-braces', 'title="代码生成" data-layer-size="1210px,98%"')
            ->btnLink('relations', url('relations', ['id' => '__data.pk__']), '关联', 'btn-info', 'mdi-link-variant', 'title="设置表关联" data-layer-size="1210px,98%"')
            ->btnLink('lang', url('lang', ['id' => '__data.pk__']), '翻译', 'btn-danger', 'mdi-translate', 'title="生成翻译文件"');

        $table->useCheckbox(false);

        foreach ($data as &$d) {
            $relations = $this->relationModel->where('local_table_name', $d['TABLE_NAME'])->column('relation_name');
            $names = [];
            foreach ($relations as $rl) {
                $names[] = '<label class="label label-dark">' . $rl . '</label>';
            }

            $d['TABLE_RELATIONS'] = count($names) ? implode('、', $names) : '<label class="label label-default">暂无表关联</label>';
        }
    }

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

        return $this->builder()->layer()->closeRefresh(1, '已扫描');
    }

    /**
     * Undocumented function
     * @title 表关联管理
     * @return mixed
     */
    public function relations()
    {
        $id = input('id');

        $builder = $this->builder($this->pageTitle, '表关联');
        $protectedTables = $this->getProtectedTables();
        if (in_array($id, $protectedTables)) {
            return $builder->layer()->close(0, '此表不能允许此操作');
        }

        $tableInfo = $this->dbLogic->getTableInfo($id);

        if (!$tableInfo) {
            return $builder->layer()->close(0, '数据不存在');
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

            $form->tab('关联设置');

            $form->show('TABLE_NAME', '表名称')->value($id);
            $form->raw('model_namespace', 'model命名空间')->value('<b>app\\' . Module::getInstance()->config('model_namespace') . '\\model\\</b>可在扩展配置中修改');
            if (is_file($modelFileName)) {
                $form->raw('tips', '提示')->value('已存在模型文件，将被覆盖：<b>' . str_replace(App::getRootPath(), '', $modelFileName) . '</b>');
            }
            $form->text('model_title', 'model注释')->default($tableInfo['TABLE_COMMENT'])->required();

            $form->items('relations', '关联')->dataWithId($relations)->size(12, 12)->with(
                $form->select('field_name', '字段')->required()->optionsData($fields, 'COLUMN_NAME', 'COLUMN_NAME'),
                $form->select('relation_type', '关联类型')->required()->options(['belongs_to' => 'belongsTo', 'has_one' => 'hasOne', 'has_many' => 'hasMany'])->default('belongs_to'),
                $form->select('foreign_table_name', '关联表')->required()->optionsData($tables, 'TABLE_NAME', 'TABLE_NAME')->withNext(
                    $form->select('relation_key', '关联字段')->required()->dataUrl(url('slecltfields'), 'COLUMN_NAME', 'COLUMN_NAME')
                ),
                $form->text('relation_name', '关联名称')
            );


            $form->tab('示列&说明');
            $form->raw('demo', '')->size(12, 12)->showLabel(false)->value('实列：<pre>' .
                '
//产品基本信息表
class ShopGoods extends Model
{
    protected $name = \'shop_goods\';

    public function category()    //category:关联名称，若不填写，则根据关联表名转驼峰得到：shopCategory.
    {
        //     category_id  : 字段         [shop_goods]表中的[category_id]字段
        //              id  : 关联字段      [shop_category]表中的[id]字段
        //       belongsTo  : 关联类型
        //    shop_category : 关联表        [ShopCategory]模式对应的表名[shop_category]
        return \$this->belongsTo(ShopCategory::class, \'category_id\', \'id\');
    }

    public function extendInfo()   // extendInfo:关联名称，若不填写，则根据关联表明转驼峰得到：shopGoodsExtend.
    {
        //              id   : 字段        [shop_goods]表中的[id]字段
        //        goods_id   : 关联字段    [shop_goods_extend]表中的[goods_id]字段
        //          hasOne   : 关联类型
        // shop_goods_extend : 关联表      [ShopGoodsExtend]模式对应的表名[shop_goods_extend]
        return \$this->hasOne(ShopGoodsExtend::class, \'extend_id\', \'id\');
    }

    // $data = ShopGoods::where(\'id\', 1)->find();
    // 对于驼峰命名的关联如[shopCategory]，获取时有3种方式：
    // 1.不变化       => $data[\'shopCategory\']；
    // 2.全部小写     => $data[\'shopcategory\']；（php特性：函数名、方法名不区分大小写）
    // 3.驼峰转下划线  => $data[\'shop_category\']；
    //使用
    //$table->show(\'shopCategory.name\', \'分类\');
    //$form->show(\'shop_category.name\', \'分类\');
}

//产品分类表
class ShopCategory extends Model
{
    protected $name = \'shop_category\';
}

//产品扩展信息表
class ShopGoodsExtend extends Model
{
    protected $name = \'shop_goods_extend\';
}

'
                . '</pre>')
                ->help('设置是单向的，在`shop_goods`表中设置的关联，将在[ShopGoods]模型中添加[category]、[extendInfo]两个关联，但不会在[ShopCategory]、[ShopGoodsExtend]模型中生成相对于[ShopGoods]的关联');

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
                    'field_name|字段' => 'require',
                    'relation_type|关联类型' => 'require',
                    'foreign_table_name|关联表' => 'require',
                    'relation_key|关联字段' => 'require',
                    'relation_name|关联名称' => 'regex:[a-z0-9A-Z_]{0,}',
                ]);

                if (true !== $result) {
                    $errors[] = '字段[' . $pdata['field_name'] . ']' . $result;
                    continue;
                }

                if ($pdata['local_table_name'] == $pdata['foreign_table_name'] && $pdata['field_name'] == $pdata['relation_key']) {
                    $errors[] = '字段[' . $pdata['field_name'] . ']' . '关联错误';
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
                        $errors[] = '字段[' . $pdata['field_name'] . ']保存出错';
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
                            $errors[] = '字段[' . $pdata['field_name'] . ']保存出错';
                        }
                    }
                }
            }

            if ($changes) {
                if (!empty($errors)) {
                    $this->error('保存关联信息失败-' . implode('<br>', $errors));
                }
            } else {
                $this->error('保存关联信息失败-' . implode('<br>', $errors));
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
            return $builder->layer()->closeRefresh(1, '保存成功-' . $modelFileName);
        } else {
            $this->error('保存Model文件失败-' . $modelFileName);
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

        $builder = $this->builder($this->pageTitle, '翻译生成');
        $protectedTables = $this->getProtectedTables();
        if (in_array($id, $protectedTables)) {
            return $builder->layer()->close(0, '此表不能允许生成代码');
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
            return $builder->layer()->close(0, '数据不存在');
        }

        if (request()->isGet()) {

            $form = $builder->form();

            $form->show('TABLE_NAME', '表名称')->value($id);
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

                $form->raw('tips', '提示')->value('已存在翻译文件，将覆被盖：<b>' . str_replace(App::getRootPath(), '', $filePath) . '</b>');
            } else {
                foreach ($fields as &$field) {
                    $field['__can_delete__'] = 0;
                }
            }

            $form->items('FORM_FIELDS', '翻译')->dataWithId($fields, 'COLUMN_NAME')->size(12, 12)
                ->with(
                    //不推荐rendering的方式，使用上面设置__readonly__fields__的方式代替
                    $form->text('COLUMN_NAME', '字段名')->rendering(function ($field) {
                        if (!isset($field->data['__can_delete__']) || $field->data['__can_delete__'] == 0) {
                            $field->readonly();
                        } else {
                            $field->readonly(false);
                        }
                    })->required(),
                    $form->show('COLUMN_TYPE', '字段类型')->default('--'),
                    $form->text('COLUMN_COMMENT', '字段注释')->required()
                )->help('可以添加或删除数据表中不存在字段的键值对');

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
            return $this->builder()->layer()->closeRefresh(1, '生成成功，翻译文件保存在：' . $filePath);
        } else {
            $this->error('翻译文件保存失败');
        }
    }
}

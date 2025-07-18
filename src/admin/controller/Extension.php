<?php

namespace tpext\manager\admin\controller;

use tpext\think\App;
use think\Controller;
use think\facade\Config;
use think\facade\Session;
use tpext\common\ExtLoader;
use tpext\common\TpextCore;
use tpext\manager\common\Module;
use tpext\builder\common\Table;
use tpext\builder\common\Builder;
use tpext\common\Module as BaseModule;
use tpext\builder\common\Module as builderRes;
use tpext\manager\common\logic\ExtensionLogic;
use tpext\common\model\Extension as ExtensionModel;
use tpext\lightyearadmin\common\Resource as LightyearRes;
use tpext\builder\mdeditor\common\Resource as MdeditorRes;

/**
 * Undocumented class
 * @title 扩展管理
 */
class Extension extends Controller
{
    protected $extensions = [];

    protected $remote = 0;

    /**
     * Undocumented variable
     *
     * @var ExtensionModel
     */
    protected $dataModel;

    /**
     * Undocumented variable
     *
     * @var ExtensionLogic
     */
    protected $extensionLogic;

    protected function initialize()
    {
        $this->extensionLogic = new ExtensionLogic;

        $this->extensionLogic->getExtendExtensions(true);

        ExtLoader::clearCache();

        $this->extensions = ExtLoader::getExtensions();

        $this->extensions[TpextCore::class] = TpextCore::getInstance();

        ksort($this->extensions);

        $this->dataModel = new ExtensionModel;
    }

    /**
     * @title 列表
     * @return mixed
     */
    public function index()
    {
        if (request()->isAjax()) {
            request()->withPost(request()->get()); //兼容以post方式获取参数
        }

        $builder = Builder::getInstance('扩展管理', __blang('bilder_page_index_text'));

        $tab = $builder->tab();

        $localTable = $tab->table('本地')->tableId('local');
        $remoteTable = $tab->table('远程')->tableId('remote');

        $this->buildTableByRemote(0, $localTable);
        $this->buildTableByRemote(1, $remoteTable);

        $fetchData = input('__fetch_data__') == 'y' || request()->isAjax();
        $tableId = input('__table__');
        if ($fetchData) {
            if ($tableId == 'local') {
                return $localTable->partial()->render();
            } else {
                return $remoteTable->partial()->render();
            }
        }

        return $builder->render();
    }

    /**
     * @title 数据库配置
     * @return mixed
     */
    public function dbconfig()
    {
        if (request()->isPost()) {

            $data = request()->post();

            $result = $this->validate($data, [
                'hostname|域名或ip' => 'require',
                'hostport|端口' => 'require|number',
                'method|方式' => 'require',
                'username|账户名' => 'require',
                'database|数据库名' => 'require',
                'charset|数据库编码' => 'require',
            ]);

            if (true !== $result) {

                $this->error($result);
            }

            Session::set('dbconfig', $data);

            if ($data['method'] == 1) {

                $result = $this->validate($data, [
                    'new_username|新账户名' => 'require',
                    'new_password|密码' => 'require'
                ]);

                if (true !== $result) {

                    $this->error($result);
                }

                $createDb = $data['database'];

                $data['type'] = 'mysql';
                $data['database'] = 'mysql';

                $config = array_merge(Config::pull('database'), $data);

                try {
                    db('', $config)->query('SELECT TABLE_NAME FROM information_schema.tables');
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error('连接数据库失败-' . $e->getMessage());
                }

                try {
                    db('', $config, false)->query("CREATE DATABASE IF NOT EXISTS {$createDb}");
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error('连创建据库失败-' . $e->getMessage());
                }

                try {
                    //创建用户授权到数据库
                    db('', $config, false)->query("GRANT ALL PRIVILEGES ON {$createDb}.* to '{$data['new_username']}'@'{$data['hostname']}' identified by '{$data['new_password']}';");

                    db('', $config, false)->query("FLUSH PRIVILEGES;");
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error('创建新用户授权失败-' . $e->getMessage());
                }
                //切换
                $data['database'] = $createDb;
                $data['username'] = $data['new_username'];
                $data['password'] = $data['new_password'];
            } else {
                $data['type'] = 'mysql';
                $config = array_merge(Config::pull('database'), $data);

                try {
                    db('', $config)->query('SELECT TABLE_NAME FROM information_schema.tables');
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error('连接数据库失败-' . $e->getMessage());
                }
            }
            //配置信息写入文件
            try {
                $databaseStr = file_get_contents(App::getConfigPath() . 'database.php');

                $replace = ['hostname', 'database', 'username', 'password', 'hostport', 'charset', 'prefix'];

                foreach ($replace as $rep) {
                    $val = $data[$rep];
                    $databaseStr = preg_replace('/([\'\"]' . $rep . '[\'\"]\s*=>\s*)[\'\"][^\'\"]*?[\'\"]/', "$1'{$val}'", $databaseStr);
                }

                file_put_contents(App::getConfigPath() . 'database.php', $databaseStr);
            } catch (\Throwable $e) {
                trace($e->__toString());
                $this->error('写入配置信息到文件失败-' . $e->getMessage());
            }
            $this->success('数据库配置成功', url('prepare'), 1);
        } else {
            builderRes::getInstance()->loaded();
            $builder = Builder::getInstance('扩展管理', '数据库配置');

            $builder->content(3)->display('');

            $form = $builder->form(6);

            $form->defaultDisplayerSize(12, 12);

            $form->fields('db_type', ' ')->showLabel(false)->with(
                $form->show('type', '数据库类型', 6)->value('MySQL/MairaDB'),
                $form->radio('charset', '数据库编码', 6)->texts(['utf8', 'utf8mb4'])->default('utf8')->required()
            );

            $form->fields('host_port', ' ')->showLabel(false)->with(
                $form->text('hostname', '域名或ip', 6)->default('127.0.0.1')->help('如:127.0.0.1、localhost')->required(),
                $form->text('hostport', '端口', 6)->default('3306')->required()
            );

            $form->radio('method', '方式')
                ->options([1 => '使用root创建新的账户和数据库', 2 => '使用已存在的账户和数据库'])
                ->required()
                ->default(1)
                ->when(1)->with(
                    $form->fields('root_user_pwd', ' ')->showLabel(false)->with(
                        $form->text('username', 'root账户', 6)->default('root')->help('超级账户名，root或其他有创建新用户和数据库高级权限的账户')->required(),
                        $form->password('password', 'root密码', 6)
                    ),
                    $form->fields('new_user_pwd', ' ')->showLabel(false)->with(
                        $form->text('new_username', '新账户名', 6)->help('由英文字母数、字或、下划线组成')->required(),
                        $form->password('new_password', '密码', 6)->required()
                    )
                )
                ->when(2)->with(
                    $form->fields('host_port', ' ')->showLabel(false)->with(
                        $form->text('username', '账户名', 6)->help('由英文字母数、字或、下划线组成。为了数据安全不建议直接使用root账号连接')->required(),
                        $form->password('password', '密码', 6)->required()
                    )
                );

            $form->fields('host_port', ' ')->showLabel(false)->with(
                $form->text('database', '数据库名', 6)->help('由英文字母数、字或、下划线组成，如果数据库已存在，则直接使用。')->required(),
                $form->text('prefix', '表前缀', 6)->default('tp_')
            );

            $url = url('prepare');
            $form->raw('tips', '提示')->value('<p>数据库配置信息将保存在<b>`config/database.php`</b>文件中，请确保程序对此文件有可写权限。'
                . '如果您不想通过此程序修改配置，请手动修改数据库配置文件，后<a href="' . $url . '">[点此]</a>进入下一步，如果仍然回到此页面，请检查配置。</p>');

            $data = Session::get('dbconfig');

            if ($data) {
                $form->fill($data);
            }

            return $builder->render();
        }
    }

    /**
     * @title 首次预安装
     * @return mixed
     */
    public function prepare()
    {
        $step = input('step', '0');
        if ($step == 0) {
            try {
                db('extension')->select();
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                LightyearRes::getInstance()->copyAssets();
                builderRes::getInstance()->copyAssets();

                $next = url('/admin/extension/dbconfig');

                return "<h4>提示</h4><p>{$msg}.数据库连接失败，请配置数据库。</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
            }
            Session::set('dbconfig', null);
            Module::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 1]);
            return "<h4>(1/4)</h4><p>安装[tpext.manager]，完成！</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 1) {
            LightyearRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 2]);
            return "<h4>(2/4)</h4><p>安装[lightyear.admin]，完成！</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 2) {
            builderRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 3]);
            return "<h4>(3/4)</h4><p>安装[tpext.builder]，完成！</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 3) {
            MdeditorRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 4]);
            return "<h4>(4/4)</h4><p>安装[builder.mdeditor]，完成！</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else {
            ExtLoader::trigger('tpext_extension_prepare_done'); //如果扩展要在首次安装时静默安装，可监听此事件
            $next = url('/admin/extension/index', ['first_install' => 1]);
            return "<h4>(预安装完成)</h4><p>即将跳转[扩展管理]页面，继续安装其他扩展！</p><script>setTimeout(function(){location.href='{$next}'},1500);</script>";
        }
    }

    /**
     * @param int $remote
     * @param integer $page
     * @param integer $pagesize
     * @param integer $total
     * @return array
     */
    protected function getDataList($remote = 0, $page = 1, $pagesize = 20, &$total = -1)
    {
        $data = [];
        $total = 0;

        if ($remote) {

            $data = $this->extensionLogic->getRemoteJson();

            $total = count($data);

            $data = array_slice($data, ($page - 1) * $pagesize, $pagesize);

            $installed = ExtLoader::getInstalled(true);

            foreach ($data as &$d) {

                $d['ext_type'] = $d['type'] == 'module' ? 1 : 2;
                $d['id'] = str_replace('.', '-', $d['name']);
                $d['download'] = 0;
                $d['install'] = 0;
                $d['now_version'] = '';

                foreach ($this->extensions as $key => $instance) {

                    if (!class_exists($key)) {
                        continue;
                    }

                    if ($instance->getName() == $d['name']) {
                        $d['download'] = 1;
                        $d['now_version'] = $instance->getVersion();
                        foreach ($installed as $ins) {
                            if ($ins['key'] == $key) {
                                $d['install'] = $ins['install'];
                                break;
                            }
                        }
                        break;
                    }
                }
                $extend_download = $d['extend_download'] && preg_match('/^https?:\/\/.+?$/i', $d['extend_download']);
                $d['__h_up__'] = $d['now_version'] == $d['version'] || !$extend_download || !$d['download'];
                $d['__h_dwn__'] = $d['now_version'] == $d['version'] || !$extend_download || $d['download'];
            }
        } else {

            $total = count($this->extensions);

            $extensions = array_slice($this->extensions, ($page - 1) * $pagesize, $pagesize);

            $installed = ExtLoader::getInstalled(true);

            if (empty($installed)) {
                $this->error('已安装扩展为空！请确保数据库连接正常，然后安装[tpext.manager]');
            } else {
                if (!ExtensionModel::where('key', Module::class)->find()) {

                    return $this->redirect(url('/admin/extension/prepare', ['step' => 0]));
                }
            }

            $is_install = 0;
            $is_enable = 0;
            $is_latest = 0;
            $now_version = '';
            $k = 0;
            foreach ($extensions as $key => $instance) {
                if (!class_exists($key)) {
                    continue;
                }

                $is_install = 0;
                $is_enable = 0;
                $has_config = !empty($instance->defaultConfig());

                foreach ($installed as $ins) {
                    if ($ins['key'] == $key) {
                        $is_install = $ins['install'];
                        $is_enable = $ins['enable'];
                        $is_latest = $ins['version'] == $instance->getVersion();
                        $now_version = $ins['version'];
                        break;
                    }
                }

                $instance->copyAssets();

                $data[$k] = [
                    'id' => str_replace('\\', '-', $key),
                    'key' => $key,
                    'install' => $is_install,
                    'enable' => $is_enable,
                    'name' => $instance->getName(),
                    'title' => $instance->getTitle(),
                    'description' => $instance->getDescription(),
                    'version' => $now_version,
                    'ext_type' => $instance instanceof BaseModule ? 1 : 2,
                    'tags' => $instance->getTags(),
                    '__h_up__' => !$is_install || $is_latest,
                    '__h_in__' => $is_install,
                    '__h_un__' => !$is_install,
                    '__h_st__' => !$is_install || !$has_config,
                    '__h_cp__' => empty($instance->getAssets()),
                ];

                if ($key == Module::class) {
                    $data[$k]['__h_un__'] = 1;
                }

                $k += 1;
            }
        }

        return $data;
    }

    /**
     * 构建表格
     * @param int $remote
     * @param Table $table
     * @return void
     */
    protected function buildTableByRemote($remote, $table)
    {
        $first_install = input('first_install', 0);
        $page = input('__page__/d', 1);

        $table->show('title', '标题');
        $table->show('name', '标识');
        $table->match('ext_type', '扩展类型')->options(
            [
                1 => '<label class="label label-info">模块</label>',
                2 => '<label class="label label-success">资源</label>',
            ]
        );
        $table->show('tags', '分类');
        $table->show('description', '介绍')->getWrapper()->addStyle('width:40%;');

        if ($remote) {
            $table->show('now_version', '已下载版本号');
            $table->show('version', '最新版本号');
            $table->match('download', '下载')->options([0 => '未下载', 1 => '已下载'])->mapClassGroup([[0, 'default'], [1, 'success']]);
            $table->match('install', '安装')->options([0 => '未安装', 1 => '已安装'])->mapClassGroup([[0, 'default'], [1, 'success']]);
            $table->show('composer', 'Composer');
            $table->show('platform', 'TP版本支持');
            $table->match('is_free', '免费')->options([1 => '是', 0 => '否']);
            $table->getActionbar()
                ->btnLink(
                    'update',
                    url('update', ['key' => '__data.id__', 'now_version' => '__data.now_version__']),
                    '',
                    'btn-warning',
                    'mdi-autorenew',
                    'title="更新"'
                )
                ->btnLink(
                    'download',
                    url('download', ['key' => '__data.id__']),
                    '',
                    'btn-info',
                    'mdi-cloud-download',
                    'title="下载"'
                )
                ->mapClass([
                    'update' => ['hidden' => '__h_up__'],
                    'download' => ['hidden' => '__h_dwn__']
                ])
                ->btnLink('view', '__data.website__', '', 'btn-primary', 'mdi-web', 'title="主页" target="_blank"')
                ->getCurrent()->useLayer(false);
        } else {
            $table->show('version', '已安装版本号');
            $table->match('install', '安装')->options([0 => '未安装', 1 => '已安装'])->mapClassGroup([[0, 'default'], [1, 'success']]);

            $table->switchBtn('enable', '启用')->autoPost(url('enable'))
                ->mapClass(0, 'hidden', 'install') //未安装，隐藏[启用/禁用]
                ->mapClass([Module::class, TpextCore::class], 'hidden', 'key'); //特殊扩展，隐藏[启用/禁用]

            $table->getActionbar()
                ->btnLink('upgrade', url('upgrade', ['key' => '__data.id__']), '', 'btn-success', 'mdi-arrow-up-bold-circle', 'title="升级"')
                ->btnLink('install', url('install', ['key' => '__data.id__']), '', 'btn-primary', 'mdi-plus', 'title="安装"')
                ->btnLink('uninstall', url('uninstall', ['key' => '__data.id__']), '', 'btn-danger', 'mdi-delete', 'title="卸载"')
                ->btnLink('setting', url('/admin/config/edit', ['key' => '__data.id__']), '', 'btn-info', 'mdi-settings', 'title="设置" data-layer-size="98%,98%"')
                ->btnPostRowid(
                    'copyAssets',
                    url('copyAssets'),
                    '',
                    'btn-purple',
                    'mdi-redo',
                    'title="刷新资源"',
                    '刷新资源将清空并还原`/assets/`下对应扩展目录中的文件，原则上您不应该修改此目录中的任何文件或上传新文件到其中。若您这么做了，请备份到其他地方，然后再刷新资源。确定要刷新吗？'
                )
                ->mapClass([
                    'upgrade' => ['hidden' => '__h_up__'],
                    'install' => ['hidden' => '__h_in__'],
                    'uninstall' => ['hidden' => '__h_un__'],
                    'setting' => ['hidden' => '__h_st__'],
                    'copyAssets' => ['hidden' => '__h_cp__'],
                ]);
        }

        if (!$remote) {
            $table->getToolbar()
                ->btnLink(url('import'), 'zip包上传', 'btn-pink', 'mdi-cloud-upload', 'data-layer-szie="400px,250px" title="zip包上传扩展"');
        }

        $table->getToolbar()
            ->btnRefresh()
            ->html('<label class="label label-default">注意：部分扩展同时支持`composer`和`extend`模式。同一个扩展不能同时安装两种模式的，或跨模式升级。</label>')->pullRight();

        $table->useCheckbox(false);
        $table->useExport(false);
        $table->useChooseColumns(false);

        if ($first_install == 1) {
            $table->addBottom()->content()->display('<div style="padding:10px"><h5>首次安装提示：</h5>安装完成点此<a href="' . url('/admin/index') . '">[进入后台]</a><br>此页面排版错乱？点此<a href="' . url('prepare') . '">[刷新]</a>样式资源</div>');
        }

        $pagesize = 14;
        //延迟加载数据
        $fetchData = input('__fetch_data__');
        if (!$remote || $fetchData == 'y') {
            $data = $this->getDataList($remote, $page, $pagesize, $total);
            $table->fill($data);
            $table->paginator($total, $pagesize);
        } else {
            $table->paginator(1000, $pagesize);
        }
    }

    public function install()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, '扩展不存在！');
        }

        $installed = ExtLoader::getInstalled();

        if (empty($installed) && $id != Module::class) {
            return Builder::getInstance()->layer()->close(0, '已安装扩展为空！请确保数据库连接正常，然后安装[tpext.manager]');
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance('扩展管理', '安装-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $res = $instance->install();
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, '安装成功');
                } else {
                    return $builder->layer()->closeRefresh(0, '安装成功，但可能有些错误');
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>执行出错：</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

                return $builder->render();
            }
        } else {
            $form = $builder->form();
            $this->detail($form, $instance, 1);
            return $builder->render();
        }
    }

    public function uninstall()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, '扩展不存在！');
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance('扩展管理', '卸载-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $sql = input('post.sql/a', []);
            $res = $instance->uninstall(!empty($sql));
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, '卸载成功');
                } else {
                    return $builder->layer()->closeRefresh(0, '卸载成功，但可能有些错误');
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>执行出错：</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

                return $builder->render();
            }
        } else {

            $form = $builder->form();
            $this->detail($form, $instance, 2);
            return $builder->render();
        }
    }

    public function upgrade()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, '扩展不存在！');
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance('扩展管理', '升级-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $res = $instance->upgrade();
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, '升级成功');
                } else {
                    return $builder->layer()->closeRefresh(0, '升级成功，但可能有些错误');
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>执行出错：</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

                return $builder->render();
            }
        } else {
            $form = $builder->form();
            $this->detail($form, $instance, 3);
            return $builder->render();
        }
    }

    /**
     * Undocumented function
     *
     * @title 更新远程扩展
     * @return mixed
     */
    public function update()
    {
        $key = input('key');
        $now_version = input('now_version');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
        }

        $name = str_replace('-', '.', $key);

        $list = $this->extensionLogic->getRemoteJson();

        $data = null;

        foreach ($list as $li) {
            if ($li['name'] == $name) {
                $data = $li;
                break;
            }
        }

        if (!$data) {
            return Builder::getInstance()->layer()->close(0, '扩展不存在-' . $name);
        }

        $data['now_version'] = $now_version;

        $builder = Builder::getInstance('扩展管理', '更新-' . $data['title']);

        if (request()->isPost()) {

            $this->checkToken();

            $updateRes = $this->extensionLogic->download($data['extend_download'], 2);

            if (!$updateRes) {

                $errors = $this->extensionLogic->getErrors();

                $builder->content()->display('<h5>下载解压时出错：</h5>{$errors|raw}', ['errors' => implode('<br>', $errors)]);
                return $builder->render();
            }

            $this->extensionLogic->getExtendExtensions(true);

            ExtLoader::clearCache(true);

            ExtLoader::bindExtensions();

            $this->extensions = ExtLoader::getExtensions();

            $findInstance = null;
            $findKey = '';
            $findInstall = false;

            foreach ($this->extensions as $key => $instance) {

                if (!class_exists($key)) {
                    continue;
                }

                if ($instance->getName() == $name) {
                    $findInstance = $instance;
                    $findKey = $key;
                    break;
                }
            }

            if (!$findInstance) {
                $builder->content()->display('<h5>执行出错：</h5>未匹配到扩展<script>parent.$(".search-refresh").trigger("click");</script>');
                return $builder->render();
            }

            $installed = ExtLoader::getInstalled(true);

            foreach ($installed as $ins) {
                if ($ins['key'] == $findKey) {
                    $findInstall = $ins['install'];
                    break;
                }
            }

            $findKey = str_replace('\\', '-', $findKey);

            if ($findInstall) {
                $upgradeUrl = url('upgrade', ['key' => $findKey]);

                $builder->content()->display('<h5>下载最新压缩包成功，您需要安装才能体验最新功能，<a class="btn btn-xs btn-success" href="{$url|raw}">点此去升级</a></h5><script>parent.$(".search-refresh").trigger("click");</script>', ['url' => $upgradeUrl]);
            } else {
                $installUrl = url('install', ['key' => $findKey]);

                $builder->content()->display('<h5>下载最新压缩包成功，您需要安装才能体验最新功能，<a class="btn btn-xs btn-success" href="{$url|raw}">点此去安装</a></h5><script>parent.$(".search-refresh").trigger("click");</script>', ['url' => $installUrl]);
            }

            return $builder->render();
        } else {

            $form = $builder->form();
            $form->show('title', '名称');
            $form->show('name', '标识');
            $form->match('is_free', '免费')->options([1 => '是', 0 => '否']);
            $form->show('platform', 'TP版本支持');
            $form->show('change', '版本')->to('{now_version} => {version}');
            $form->show('extend_download', '压缩包地址');

            $form->fill($data);
            $form->ajax(false);

            $form->btnSubmit('更&nbsp;&nbsp;新', '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose('返&nbsp;&nbsp;回', '6 col-lg-6 col-sm-6 col-xs-6');

            return $builder->render();
        }
    }

    /**
     * Undocumented function
     *
     * @title 新下载远程扩展
     * @return mixed
     */
    public function download()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
        }

        $name = str_replace('-', '.', $key);

        $list = $this->extensionLogic->getRemoteJson();

        $data = null;

        foreach ($list as $li) {
            if ($li['name'] == $name) {
                $data = $li;
                break;
            }
        }

        if (!$data) {
            return Builder::getInstance()->layer()->close(0, '扩展不存在-' . $name);
        }

        $builder = Builder::getInstance('扩展管理', '下载-' . $data['title']);

        if (request()->isPost()) {

            $this->checkToken();

            $updateRes = $this->extensionLogic->download($data['extend_download'], 1);

            if (!$updateRes) {

                $errors = $this->extensionLogic->getErrors();

                $builder->content()->display('<h5>下载解压时出错：</h5>{$errors|raw}', ['errors' => implode('<br>', $errors)]);
                return $builder->render();
            }

            $this->extensionLogic->getExtendExtensions(true);

            ExtLoader::clearCache(true);

            ExtLoader::bindExtensions();

            $this->extensions = ExtLoader::getExtensions();

            $findInstance = null;
            $findKey = '';

            foreach ($this->extensions as $key => $instance) {

                if (!class_exists($key)) {
                    continue;
                }

                if ($instance->getName() == $name) {
                    $findInstance = $instance;
                    $findKey = $key;
                    break;
                }
            }

            if (!$findInstance) {
                $builder->content()->display('<h5>执行出错：</h5>未匹配到扩展<script>parent.$(".search-refresh").trigger("click");</script>');
                return $builder->render();
            }

            $findKey = str_replace('\\', '-', $findKey);

            $installUrl = url('install', ['key' => $findKey]);

            $builder->content()->display('<h5>下载最新压缩包成功，您需要安装才能体验最新功能，<a class="btn btn-xs btn-success" href="{$url|raw}">点此去安装</a></h5><script>parent.$(".search-refresh").trigger("click");</script>', ['url' => $installUrl]);
            return $builder->render();
        } else {

            $form = $builder->form();
            $form->show('title', '名称');
            $form->show('name', '标识');
            $form->match('is_free', '免费')->options([1 => '是', 0 => '否']);
            $form->show('platform', 'TP版本支持');
            $form->show('version', '版本');
            $form->show('extend_download', '压缩包地址');

            $form->fill($data);
            $form->ajax(false);

            $form->btnSubmit('下&nbsp;&nbsp;载', '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose('返&nbsp;&nbsp;回', '6 col-lg-6 col-sm-6 col-xs-6');

            return $builder->render();
        }
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

    /**
     * Undocumented function
     *
     * @title zip包上传
     * @return mixed
     */
    public function import()
    {
        $builder = Builder::getInstance();

        $checkFile = App::getRootPath() . 'extend' . DIRECTORY_SEPARATOR . 'validate.txt';

        if (request()->isGet()) {

            if (!file_exists($checkFile)) {
                file_put_contents($checkFile, $this->randstr());
            }

            $form = $builder->form();
            $form->file('fileurl', 'zip上传')->jsOptions(['ext' => ['zip']])->required()->help('上传zip文件');
            $form->password('validate', '验证字符')->required()->help(!file_exists($checkFile) ? '请在网站目录新建[extend/validate.txt]文件，里面填入任意字符串，然后再此输入。' : '输入网站目录下[extend/validate.txt]文件中的字符串内容');

            return $builder->render();
        }

        $fileurl = input('fileurl');
        $validate = input('validate');

        if (!file_exists($checkFile)) {
            $this->error('[extend/validate.txt]文件不存在');
        }

        $try_validate = Session::get('admin_try_extend_validate');
        $errors = 0;

        if (Session::has('admin_try_extend_validate_errors')) {
            $errors = Session::get('admin_try_extend_validate_errors') > 300 ? 300
                : Session::get('admin_try_extend_validate_errors');
        }

        if ($errors > 0 && $try_validate) {

            $time_gone = time() - $try_validate;

            if ($time_gone < $errors) {
                $this->error('错误次数过多，请' . ($errors - $time_gone) . '秒后再试');
            }
        }

        if (trim($validate) !== trim(file_get_contents($checkFile))) {
            $errors += 1;
            Session::set('admin_try_extend_validate', time());
            Session::set('admin_try_extend_validate_errors', $errors);
            $this->error('文件验证失败：验证字符串不匹配');
        }

        $installRes = $this->extensionLogic->installExtend('.' . $fileurl, 1);

        if (!$installRes) {

            $errors = $this->extensionLogic->getErrors();

            $this->error('解压安装包时出错：' . implode('<br>', $errors));
        }

        $this->extensionLogic->getExtendExtensions(true);

        ExtLoader::clearCache(true);

        ExtLoader::bindExtensions();

        return $builder->layer()->closeRefresh(2, '上传成功');
    }

    /**
     * Undocumented function
     *
     * @param \tpext\builder\common\Form $form
     * @param \tpext\common\Module $instance
     * @param integer $type
     * @return void
     */
    private function detail($form, $instance, $type = 0)
    {
        $isModule = $instance instanceof BaseModule;
        $modules = $isModule ? $instance->getModules() : [];
        $menus = $isModule ? $instance->getMenus() : [];

        $bindModules = [];
        foreach ($modules as $module => $controllers) {
            foreach ($controllers as $controller) {
                $bindModules[] = '/' . $module . '/' . $controller . '/*';
            }
        }

        $form->tab('基本信息');
        $form->raw('name', '标识')->value($instance->getName());
        $form->raw('title', '标题')->value($instance->getTitle());
        $form->raw('tags', '类型')->value($instance->getTags());
        $form->raw('desc', '介绍')->value($instance->getDescription());
        $form->raw('version', '版本号')->value($instance->getVersion());
        if ($type == 1) {
            if (is_file($instance->getRoot() . 'data' . DIRECTORY_SEPARATOR . 'install.sql')) {
                $form->show('sql', '安装脚本')->value('安装将运行SQL脚本');
            } else {
                $form->show('sql', '安装脚本')->value('无');
            }
        } else if ($type == 2) {
            if (is_file($instance->getRoot() . 'data' . DIRECTORY_SEPARATOR . 'uninstall.sql')) {

                $app_debug = config('app_debug');

                $form->checkbox('sql', '卸载脚本')->options([1 => '卸载将运行SQL脚本'])->value($app_debug ? 1 : 0)->help($app_debug ? '<label class="label label-default">当前为调试模式</label>' : '<label class="label label-danger">当前为非调试模式，谨慎操作</label>');
            } else {
                $form->show('uninstall', '卸载脚本')->value('无');
            }
        }

        if ($isModule) {
            $form->tab('模块&菜单');
            $form->raw('modules', '提供模块')->value(!empty($bindModules) ? '<pre>' . implode("\n", $bindModules) . '</pre>' : '无');
            $form->raw('menus', '提供菜单')->value(!empty($menus) ? '<pre>' . implode("\n", $this->menusTree($menus)) . '</pre>' : '无');
        }

        $form->tab('README.md');
        $README = '暂无';

        if (is_file($instance->getRoot() . 'README.md')) {
            $README = file_get_contents($instance->getRoot() . 'README.md');
        }
        $form->mdreader('README')->jsOptions(['readOnly' => true, 'width' => 1200])->size(0, 12)->showLabel(false)->value($README);

        $form->tab('CHANGELOG.md', $type == 3);
        $README = '暂无';
        if (is_file($instance->getRoot() . 'CHANGELOG.md')) {
            $README = file_get_contents($instance->getRoot() . 'CHANGELOG.md');
        }
        $form->mdreader('CHANGELOG')->jsOptions(['readOnly' => true, 'width' => 1200])->size(0, 12)->showLabel(false)->value($README);

        $form->tab('LICENSE.txt');
        $LICENSE = '暂无';
        if (is_file($instance->getRoot() . 'LICENSE.txt')) {
            $LICENSE = '<pre>' . htmlspecialchars(file_get_contents($instance->getRoot() . 'LICENSE.txt')) . '</pre>';
        } else if (is_file($instance->getRoot() . 'LICENSE')) {
            $LICENSE = '<pre>' . htmlspecialchars(file_get_contents($instance->getRoot() . 'LICENSE')) . '</pre>';
        }

        $form->raw('LICENSE')->size(0, 12)->showLabel(false)->value($LICENSE);

        $form->ajax(false);

        if ($type == 1) {
            $form->btnSubmit('安&nbsp;&nbsp;装', '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose('返&nbsp;&nbsp;回', '6 col-lg-6 col-sm-6 col-xs-6');
        } else if ($type == 2) {
            $form->btnSubmit('卸&nbsp;&nbsp;载', '6 col-lg-6 col-sm-6 col-xs-6', 'btn-danger');
            $form->btnLayerClose('返&nbsp;&nbsp;回', '6 col-lg-6 col-sm-6 col-xs-6');
        } else if ($type == 3) {
            $form->btnSubmit('升&nbsp;&nbsp;级', '6 col-lg-6 col-sm-6 col-xs-6', 'btn-warning');
            $form->btnLayerClose('返&nbsp;&nbsp;回', '6 col-lg-6 col-sm-6 col-xs-6');
        }
    }

    /**
     * Undocumented function
     *
     * @param array $meuns
     * @return array
     */
    private function menusTree($meuns, $deep = 0)
    {
        $data = [];

        $deep += 1;

        foreach ($meuns as $menu) {

            if ($deep == 1) {
                $data[] = $menu['title'] . ' - ' . $menu['url'];
            } else {
                $data[] = str_repeat(' ', ($deep - 1) * 3) . '├─' . $menu['title'] . ' - ' . $menu['url'];
            }

            if (isset($menu['children']) && !empty($menu['children'])) {
                $data = array_merge($data, $this->menusTree($menu['children'], $deep));
            }
        }

        return $data;
    }

    /**
     * Undocumented function
     *
     * @title 刷新资源
     * @return mixed
     */
    public function copyAssets()
    {
        $ids = input('ids', '');
        $ids = str_replace('-', '\\', $ids);

        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error('参数有误');
        }

        $instance = $this->extensions[$ids[0]];

        $instance->copyAssets(true);

        $this->success('刷新成功');
    }

    public function enable()
    {
        $id = input('post.id', '');
        $value = input('post.value', '0');

        if (empty($id)) {
            $this->error('参数有误');
        }

        $id = str_replace('-', '\\', $id);

        if (!isset($this->extensions[$id])) {
            $this->error('扩展不存在！');
        }

        $instance = $this->extensions[$id];
        $res = $instance->enabled($value);

        if ($res) {
            $this->dataModel->update(['enable' => $value], ['key' => $id]);
            $this->success(($value == 1 ? '启用' : '禁用') . '成功');
        } else {
            $this->error('操作失败');
        }
    }

    protected function checkToken()
    {
        $token = Session::get('_csrf_token_');

        if (empty($token) || $token != input('__token__')) {
            $this->error('token错误');
        }
    }
}

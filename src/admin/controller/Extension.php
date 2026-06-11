<?php

namespace tpext\manager\admin\controller;

use think\facade\Db;
use tpext\think\App;
use think\Controller;
use think\facade\Config;
use think\facade\Session;
use tpext\common\ExtLoader;
use tpext\common\TpextCore;
use tpext\common\RouteLoader;
use Webman\Config as WConfig;
use tpext\manager\common\Module;
use tpext\builder\common\Table;
use tpext\builder\common\Builder;
use tpext\common\Module as BaseModule;
use tpext\builder\common\Module as BuilderRes;
use tpext\myadmin\common\Module as AdminRes;
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
        Module::getInstance()->loadLang('extension');

        $this->extensionLogic = new ExtensionLogic;

        $this->extensionLogic->getExtendExtensions(true);

        if (ExtLoader::isWebman()) {
            //常驻内存，每次都重新获取
            ExtLoader::clearCache(true);
            ExtLoader::bindExtensions();
        } else {
            ExtLoader::clearCache();
        }

        $this->extensions = ExtLoader::getExtensions();

        $this->extensions[TpextCore::class] = TpextCore::getInstance();

        ksort($this->extensions);

        $this->dataModel = new ExtensionModel;
    }

    /**
     * @title 扩展列表
     * @return mixed
     */
    public function index()
    {
        if (request()->isAjax()) {
            request()->withPost(request()->get()); //兼容以post方式获取参数
        }

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __blang('builder_page_index_text'));

        $tab = $builder->tab();

        $localTable = $tab->table(__admin_lang('label_local'))->tableId('local');
        $remoteTable = $tab->table(__admin_lang('label_remote'))->tableId('remote');

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
                'hostname|' . __admin_lang('hostname') => 'require',
                'hostport|' . __admin_lang('hostport') => 'require|number',
                'method|' . __admin_lang('method') => 'require',
                'username|' . __admin_lang('username') => 'require',
                'database|' . __admin_lang('database') => 'require',
                'charset|' . __admin_lang('charset') => 'require',
            ]);

            if (true !== $result) {

                $this->error($result);
            }

            Session::set('dbconfig', $data);

            if ($data['method'] == 1) {

                $result = $this->validate($data, [
                    'new_username|' . __admin_lang('new_username') => 'require',
                    'new_password|' . __admin_lang('new_password') => 'require'
                ]);

                if (true !== $result) {

                    $this->error($result);
                }

                $createDb = $data['database'];

                $data['type'] = 'mysql';
                $data['database'] = 'mysql';

                $config = [];

                if (ExtLoader::isWebman()) {
                    $config = array_merge(WConfig::get('thinkorm.connections.mysql', WConfig::get('think-orm.connections.mysql', [])), $data);
                } else {
                    $config = array_merge(Config::get('database.connections.mysql', []), $data);
                }

                try {
                    Db::connect('mysql')->connect($config);
                    Db::query('SELECT TABLE_NAME FROM information_schema.tables');
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error(sprintf(__admin_lang('msg_db_connect_failed'), $e->getMessage()));
                }

                try {
                    Db::query("CREATE DATABASE IF NOT EXISTS {$createDb}");
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error(sprintf(__admin_lang('msg_db_create_failed'), $e->getMessage()));
                }

                try {
                    //创建用户授权到数据库
                    Db::query("GRANT ALL PRIVILEGES ON {$createDb}.* to '{$data['new_username']}'@'{$data['hostname']}' identified by '{$data['new_password']}';");

                    Db::query("FLUSH PRIVILEGES;");
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error(sprintf(__admin_lang('msg_db_grant_failed'), $e->getMessage()));
                }
                //切换
                $data['database'] = $createDb;
                $data['username'] = $data['new_username'];
                $data['password'] = $data['new_password'];
            } else {
                $data['type'] = 'mysql';

                $config = [];

                if (ExtLoader::isWebman()) {
                    $config = array_merge(WConfig::get('thinkorm.connections.mysql', WConfig::get('think-orm.connections.mysql', [])), $data);
                } else {
                    $config = array_merge(Config::get('database.connections.mysql', []), $data);
                }

                try {
                    Db::connect('mysql')->connect($config);
                    Db::query('SELECT TABLE_NAME FROM information_schema.tables');
                } catch (\Throwable $e) {
                    trace($e->__toString());
                    $this->error(sprintf(__admin_lang('msg_db_connect_failed'), $e->getMessage()));
                }
            }

            //配置信息写入文件
            try {

                if (ExtLoader::isWebman()) {
                    $configFile = 'think-orm.php'; //v2
                    if (!is_file(App::getConfigPath() . $configFile)) {
                        $configFile = 'thinkorm.php'; // v1
                    }

                    $databaseStr = file_get_contents(App::getConfigPath() . $configFile);

                    $replace = ['hostname', 'database', 'username', 'password', 'hostport', 'charset', 'prefix'];

                    foreach ($replace as $rep) {
                        $val = $data[$rep];
                        $databaseStr = preg_replace('/([\'\"]' . $rep . '[\'\"]\s*=>\s*)[\'\"][^\'\"]*?[\'\"]/', "$1'{$val}'", $databaseStr);
                    }

                    file_put_contents(App::getConfigPath() . $configFile, $databaseStr);
                } else {
                    $envStr = '';

                    if (is_file(App::getRootPath() . '.env')) {
                        $envStr = file_get_contents(App::getRootPath() . '.env');
                    } else if (is_file(App::getRootPath() . '.example.env')) {
                        $envStr = file_get_contents(App::getRootPath() . '.example.env');
                    }

                    $tplStr = '';

                    if (ExtLoader::isTP80()) {

                        $tplStr = "# DATABASE" . PHP_EOL
                            . "DB_TYPE = mysql" . PHP_EOL
                            . "DB_HOST = @hostname" . PHP_EOL
                            . "DB_NAME = @database" . PHP_EOL
                            . "DB_USER = @username" . PHP_EOL
                            . "DB_PASS = @password" . PHP_EOL
                            . "DB_PORT = @hostport" . PHP_EOL
                            . "DB_CHARSET = @charset" . PHP_EOL
                            . "DB_PREFIX = @prefix" . PHP_EOL
                            . "# DATABASE END" . PHP_EOL;

                        $replace = ['hostname', 'database', 'username', 'password', 'hostport', 'charset', 'prefix'];

                        foreach ($replace as $rep) {
                            $val = $data[$rep];
                            $tplStr = str_replace('@' . $rep, $val, $tplStr);
                        }

                        $envStr = str_replace(['# DATABASE', '# DATABASE END'], '', $envStr);
                        $envStr = preg_replace('/DB_\w+?\s*=.*?\n/is', '', $envStr);
                        $envStr .= $tplStr;
                    } else {

                        if (!strstr($envStr, '[DATABASE]')) {
                            $envStr .= PHP_EOL . "[DATABASE]" . PHP_EOL;
                        }

                        $tplStr = "[DATABASE]" . PHP_EOL
                            . "TYPE = mysql" . PHP_EOL
                            . "HOSTNAME = @hostname" . PHP_EOL
                            . "DATABASE = @database" . PHP_EOL
                            . "USERNAME = @username" . PHP_EOL
                            . "PASSWORD = @password" . PHP_EOL
                            . "HOSTPORT = @hostport" . PHP_EOL
                            . "CHARSET = @charset" . PHP_EOL
                            . "PREFIX = @prefix" . PHP_EOL;

                        $replace = ['hostname', 'database', 'username', 'password', 'hostport', 'charset', 'prefix'];

                        foreach ($replace as $rep) {
                            $val = $data[$rep];
                            $tplStr = str_replace('@' . $rep, $val, $tplStr);
                        }

                        $envStr = preg_replace('/\[DATABASE\][^\[\]]*/is', $tplStr, $envStr) . PHP_EOL;
                    }

                    file_put_contents(App::getRootPath() . '.env', $envStr);
                }
            } catch (\Throwable $e) {
                trace($e->__toString());
                $this->error(sprintf(__admin_lang('msg_config_write_failed'), $e->getMessage()));
            }
            $this->success(__admin_lang('msg_db_config_success'), url('prepare'), '', 1);
        } else {
            BuilderRes::getInstance()->loaded();
            $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_db_config'));

            $builder->content(3)->display('');

            $form = $builder->form(6);
            $form->defaultDisplayerSize(12, 12);

            $form->fields('db_type', ' ')->showLabel(false)->with(
                $form->show('type', '', 6)->value('MySQL/MairaDB'),
                $form->radio('charset', '', 6)->texts(['utf8', 'utf8mb4'])->default('utf8')->required()
            );

            $form->fields('host_port', ' ')->showLabel(false)->with(
                $form->text('hostname', '', 6)->default('127.0.0.1')->help(__admin_lang('help_db_hostname'))->required(),
                $form->text('hostport', '', 6)->default('3306')->required()
            );

            $form->radio('method')
                ->options([1 => __admin_lang('opt_method_root'), 2 => __admin_lang('opt_method_existing')])
                ->required()
                ->default(1)
                ->when(1)->with(
                    $form->fields('root_user_pwd', ' ')->showLabel(false)->with(
                        $form->text('username', '', 6)->default('root')->help(__admin_lang('help_db_root'))->required(),
                        $form->password('password', '', 6)
                    ),
                    $form->fields('new_user_pwd', ' ')->showLabel(false)->with(
                        $form->text('new_username', '', 6)->help(__admin_lang('help_db_new_username'))->required(),
                        $form->password('new_password', '', 6)->required()
                    )
                )
                ->when(2)->with(
                    $form->fields('host_port', ' ')->showLabel(false)->with(
                        $form->text('username', '', 6)->help(__admin_lang('help_db_username'))->required(),
                        $form->password('password', '', 6)->required()
                    )
                );

            $form->fields('host_port', ' ')->showLabel(false)->with(
                $form->text('database', '', 6)->help(__admin_lang('help_db_database'))->required(),
                $form->text('prefix', '', 6)->default('tp_')
            );

            $url = url('prepare');

            $configFile = ExtLoader::isWebman() ? 'config/thinkorm.php' : 'config/database.php';

            $form->raw('tips')->value('<p>' . sprintf(__admin_lang('help_db_config_file_tip'), '<b>[' . $configFile . ']</b>') . '<a href="' . $url . '">[' . __admin_lang('btn_click_here') . ']</a>' . __admin_lang('help_db_config_file_tip_link') . '</p>');

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
                Db::name('extension')->select();
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                LightyearRes::getInstance()->copyAssets();
                BuilderRes::getInstance()->copyAssets();

                $next = url('/admin/extension/dbconfig');

                return "<h4>" . __admin_lang('tips') . "</h4><p>{$msg}." . __admin_lang('msg_db_connection_failed_config') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
            }
            Session::set('dbconfig', null);
            Module::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 1]);
            return "<h4>" . sprintf(__admin_lang('msg_prepare_step'), 1) . "</h4><p>" . __admin_lang('msg_prepare_install_tpext_manager') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 1) {
            LightyearRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 2]);
            return "<h4>" . sprintf(__admin_lang('msg_prepare_step'), 2) . "</h4><p>" . __admin_lang('msg_prepare_install_lightyear') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 2) {
            BuilderRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 3]);
            return "<h4>" . sprintf(__admin_lang('msg_prepare_step'), 3) . "</h4><p>" . __admin_lang('msg_prepare_install_builder') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 3) {
            MdeditorRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 4]);
            return "<h4>" . sprintf(__admin_lang('msg_prepare_step'), 4) . "</h4><p>" . __admin_lang('msg_prepare_install_mdeditor') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else if ($step == 4) {
            AdminRes::getInstance()->install();
            $next = url('/admin/extension/prepare', ['step' => 5]);
            return "<h4>" . sprintf(__admin_lang('msg_prepare_step'), 5) . "</h4><p>" . __admin_lang('msg_prepare_install_admin') . "</p><script>setTimeout(function(){location.href='{$next}'},1000);</script>";
        } else {
            ExtLoader::trigger('tpext_extension_prepare_done'); //如果扩展要在首次安装时静默安装，可监听此事件
            $next = url('/admin/extension/index', ['first_install' => 1]);
            return "<h4>" . __admin_lang('msg_prepare_done') . "</h4><p>" . __admin_lang('msg_prepare_redirect') . "</p><script>setTimeout(function(){location.href='{$next}'},1500);</script>";
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
                $d['install_type'] = '';
                $d['now_version'] = '';

                foreach ($this->extensions as $key => $instance) {

                    if (!class_exists($key)) {
                        continue;
                    }

                    if ($instance->getName() == $d['name']) {
                        $d['download'] = 1;
                        $d['now_version'] = $instance->getVersion();
                        $d['package_type'] = $instance->getPackgeType();
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
                $d['__h_up__'] = $d['now_version'] == $d['version'] || !$extend_download || !$d['download'] || $d['package_type'] == 'composer';
                $d['__h_dwn__'] = $d['now_version'] == $d['version'] || !$extend_download || $d['download'];
            }
        } else {

            $total = count($this->extensions);

            $extensions = array_slice($this->extensions, ($page - 1) * $pagesize, $pagesize);

            $installed = ExtLoader::getInstalled(true);

            if (empty($installed)) {
                $this->error(__admin_lang('msg_installed_empty'));
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

        $table->show('title');
        $table->show('name');
        $table->match('ext_type')->options(
            [
                1 => '<label class="label label-info">' . __admin_lang('label_module') . '</label>',
                2 => '<label class="label label-success">' . __admin_lang('label_resource') . '</label>',
            ]
        );
        $table->show('tags');
        $table->show('description')->getWrapper()->addStyle('width:40%;');

        if ($remote) {
            $table->show('now_version');
            $table->show('version');
            $table->match('download')->options([0 => __admin_lang('label_not_downloaded'), 1 => __admin_lang('label_downloaded')])->mapClassGroup([[0, 'default'], [1, 'success']]);
            $table->match('install')->options([0 => __admin_lang('label_not_installed'), 1 => __admin_lang('label_installed')])->mapClassGroup([[0, 'default'], [1, 'success']]);
            $table->show('composer');
            $table->show('platform');
            $table->match('is_free')->options([1 => __admin_lang('label_yes'), 0 => __admin_lang('label_no')]);
            $table->getActionbar()
                ->btnLink(
                    'update',
                    url('update', ['key' => '__data.id__', 'now_version' => '__data.now_version__']),
                    '',
                    'btn-warning',
                    'mdi-autorenew',
                    'title="' . __admin_lang('btn_update') . '"'
                )
                ->btnLink(
                    'download',
                    url('download', ['key' => '__data.id__']),
                    '',
                    'btn-info',
                    'mdi-cloud-download',
                    'title="' . __admin_lang('btn_download') . '"'
                )
                ->mapClass([
                    'update' => ['hidden' => '__h_up__'],
                    'download' => ['hidden' => '__h_dwn__']
                ])
                ->btnLink('view', '__data.website__', '', 'btn-primary', 'mdi-web', 'title="' . __admin_lang('btn_homepage') . '" target="_blank"')
                ->getCurrent()->useLayer(false);
        } else {
            $table->show('version');
            $table->match('install')->options([0 => __admin_lang('label_not_installed'), 1 => __admin_lang('label_installed')])->mapClassGroup([[0, 'default'], [1, 'success']]);

            $table->switchBtn('enable')->autoPost(url('enable'))
                ->mapClass(0, 'hidden', 'install') //未安装，隐藏[启用/禁用]
                ->mapClass([Module::class, TpextCore::class], 'hidden', 'key'); //特殊扩展，隐藏[启用/禁用]

            $table->getActionbar()
                ->btnLink('upgrade', url('upgrade', ['key' => '__data.id__']), '', 'btn-success', 'mdi-arrow-up-bold-circle', 'title="' . __admin_lang('btn_upgrade') . '"')
                ->btnLink('install', url('install', ['key' => '__data.id__']), '', 'btn-primary', 'mdi-plus', 'title="' . __admin_lang('btn_install') . '"')
                ->btnLink('uninstall', url('uninstall', ['key' => '__data.id__']), '', 'btn-danger', 'mdi-delete', 'title="' . __admin_lang('btn_uninstall') . '"')
                ->btnLink('setting', url('/admin/config/edit', ['key' => '__data.id__']), '', 'btn-info', 'mdi-settings', 'title="' . __admin_lang('btn_setting') . '" data-layer-size="98%,98%"')
                ->btnPostRowid(
                    'copyAssets',
                    url('copyAssets'),
                    '',
                    'btn-purple',
                    'mdi-redo',
                    'title="' . __admin_lang('btn_refresh_assets') . '"',
                    __admin_lang('help_refresh_assets_warning')
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
                ->btnLink(url('import'), __admin_lang('btn_zip_upload'), 'btn-pink', 'mdi-cloud-upload', 'data-layer-szie="400px,250px" title="' . __admin_lang('page_zip_upload') . '"');

            if (ExtLoader::isWebman()) {
                $table->getToolbar()
                    ->btnLink(url('makeRoute'), __admin_lang('btn_make_route'), 'btn-danger', 'mdi-format-strikethrough', 'data-layer-szie="400px,250px" title="' . __admin_lang('page_make_route') . '"');
            }
        }

        $table->getToolbar()
            ->btnRefresh()
            ->html('<label class="label label-default">' . __admin_lang('label_usage_note') . '</label>')->pullRight();

        $table->useCheckbox(false);
        $table->useExport(false);
        $table->useChooseColumns(false);

        if ($first_install == 1) {
            $table->addBottom()->content()->display('<div style="padding:10px"><h5>' . __admin_lang('msg_first_install_tip_title') . '</h5>' . __admin_lang('msg_first_install_tip_line1') . '<a href="' . url('/admin/index') . '">[' . __admin_lang('btn_enter_admin') . ']</a><br>' . __admin_lang('msg_first_install_tip_line2') . '<a href="' . url('prepare') . '">[' . __admin_lang('btn_refresh_styles') . ']</a>' . __admin_lang('msg_first_install_tip_line3') . '</div>');
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
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_ext_not_exists'));
        }

        $installed = ExtLoader::getInstalled();

        if (empty($installed) && $id != Module::class) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_installed_empty'));
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_install') . '-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $res = $instance->install();
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, __admin_lang('msg_install_success'));
                } else {
                    return $builder->layer()->closeRefresh(0, __admin_lang('msg_install_success_with_errors'));
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>' . __admin_lang('msg_execute_error') . '</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

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
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_ext_not_exists'));
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_uninstall') . '-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $sql = input('post.sql/a', []);
            $res = $instance->uninstall(!empty($sql));
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, __admin_lang('msg_uninstall_success'));
                } else {
                    return $builder->layer()->closeRefresh(0, __admin_lang('msg_uninstall_success_with_errors'));
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>' . __admin_lang('msg_execute_error') . '</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

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
        if (input('from_update') == 1 && ExtLoader::isWebman()) { //webman,下载更新以后跳转升级页面，需要重启一下
            ExtLoader::reloadWebman(__admin_lang('msg_reload_webman'));
        }

        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
        }

        $id = str_replace('-', '\\', $key);

        if (!isset($this->extensions[$id])) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_ext_not_exists'));
        }

        $instance = $this->extensions[$id];

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_upgrade') . '-' . $instance->getTitle());

        if (request()->isPost()) {

            $this->checkToken();

            $res = $instance->upgrade();
            $errors = $instance->getErrors();

            if ($res) {
                if (empty($errors)) {
                    return $builder->layer()->closeRefresh(1, __admin_lang('msg_upgrade_success'));
                } else {
                    return $builder->layer()->closeRefresh(0, __admin_lang('msg_upgrade_success_with_errors'));
                }
            } else {

                $text = [];
                foreach ($errors as $err) {
                    $text[] = $err->getMessage();
                }

                $builder->content()->display('<h5>' . __admin_lang('msg_execute_error') . '</h5>{$errors|raw}', ['errors' => implode('<br>', $text)]);

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
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
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
            return Builder::getInstance()->layer()->close(0, sprintf(__admin_lang('msg_ext_not_exists_name'), $name));
        }

        $data['now_version'] = $now_version;

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_update') . '-' . $data['title']);

        if (request()->isPost()) {

            $this->checkToken();

            $updateRes = $this->extensionLogic->download($data['extend_download'], 2);

            if (!$updateRes) {

                $errors = $this->extensionLogic->getErrors();

                $builder->content()->display('<h5>' . __admin_lang('msg_download_unzip_error') . '</h5>{$errors|raw}', ['errors' => implode('<br>', $errors)]);
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
                $builder->content()->display('<h5>' . __admin_lang('msg_execute_error') . '</h5>' . __admin_lang('msg_no_match_extension') . '<script>parent.$(".search-refresh").trigger("click");</script>');
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
                $upgradeUrl = (string) url('upgrade', ['key' => $findKey, 'from_update' => 1]);

                $builder->content()->display('<h5>' . __admin_lang('msg_download_to_upgrade') . '<a class="btn btn-xs btn-success" href="' . $upgradeUrl . '">' . __admin_lang('btn_go_to_upgrade') . '</a></h5><script>parent.$(".search-refresh").trigger("click");</script>');
            } else {
                $installUrl = (string) url('install', ['key' => $findKey]);

                $builder->content()->display('<h5>' . __admin_lang('msg_download_to_install') . '<a class="btn btn-xs btn-success" href="' . $installUrl . '">' . __admin_lang('btn_go_to_install') . '</a></h5><script>parent.$(".search-refresh").trigger("click");</script>');
            }

            return $builder->render();
        } else {

            $form = $builder->form();
            $form->show('title');
            $form->show('name');
            $form->match('is_free')->options([1 => __admin_lang('label_yes'), 0 => __admin_lang('label_no')]);
            $form->show('platform');
            $form->show('change')->to('{now_version} => {version}');
            $form->show('extend_download');

            $form->fill($data);
            $form->ajax(false);

            $form->btnSubmit(__admin_lang('btn_submit_update'), '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose(__admin_lang('btn_back'), '6 col-lg-6 col-sm-6 col-xs-6');

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
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
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
            return Builder::getInstance()->layer()->close(0, sprintf(__admin_lang('msg_ext_not_exists_name'), $name));
        }

        $builder = Builder::getInstance(__admin_lang('page_extension_manage'), __admin_lang('page_download') . '-' . $data['title']);

        if (request()->isPost()) {

            $this->checkToken();

            $updateRes = $this->extensionLogic->download($data['extend_download'], 1);

            if (!$updateRes) {

                $errors = $this->extensionLogic->getErrors();

                $builder->content()->display('<h5>' . __admin_lang('msg_download_unzip_error') . '</h5>{$errors|raw}', ['errors' => implode('<br>', $errors)]);
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
                $builder->content()->display('<h5>' . __admin_lang('msg_execute_error') . '</h5>' . __admin_lang('msg_no_match_extension') . '<script>parent.$(".search-refresh").trigger("click");</script>');
                return $builder->render();
            }

            $findKey = str_replace('\\', '-', $findKey);

            $installUrl = (string) url('install', ['key' => $findKey]);

            $builder->content()->display('<h5>' . __admin_lang('msg_download_to_install') . '<a class="btn btn-xs btn-success" href="' . $installUrl . '">' . __admin_lang('btn_go_to_install') . '</a></h5><script>parent.$(".search-refresh").trigger("click");</script>');
            return $builder->render();
        } else {

            $form = $builder->form();
            $form->show('title');
            $form->show('name');
            $form->match('is_free')->options([1 => __admin_lang('label_yes'), 0 => __admin_lang('label_no')]);
            $form->show('platform');
            $form->show('version');
            $form->show('extend_download');

            $form->fill($data);
            $form->ajax(false);

            $form->btnSubmit(__admin_lang('btn_submit_download'), '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose(__admin_lang('btn_back'), '6 col-lg-6 col-sm-6 col-xs-6');

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
            $form->file('fileurl')->jsOptions(['ext' => ['zip']])->required()->help(__admin_lang('help_zip_upload'));
            $form->password('validate')->required()->help(!file_exists($checkFile) ? __admin_lang('help_validate_create') : __admin_lang('help_validate'));

            return $builder->render();
        }

        $fileurl = input('fileurl');
        $validate = input('validate');

        if (!file_exists($checkFile)) {
            $this->error(__admin_lang('msg_file_not_exists'));
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
                $this->error(sprintf(__admin_lang('msg_too_many_errors'), $errors - $time_gone));
            }
        }

        if (trim($validate) !== trim(file_get_contents($checkFile))) {
            $errors += 1;
            Session::set('admin_try_extend_validate', time());
            Session::set('admin_try_extend_validate_errors', $errors);
            $this->error(__admin_lang('msg_validate_failed'));
        }

        $installRes = $this->extensionLogic->installExtend('.' . $fileurl, 1);

        if (!$installRes) {

            $errors = $this->extensionLogic->getErrors();

            $this->error(__admin_lang('msg_unzip_error') . implode('<br>', $errors));
        }

        $this->extensionLogic->getExtendExtensions(true);

        ExtLoader::clearCache(true);

        ExtLoader::bindExtensions();

        return $builder->layer()->closeRefresh(1, __admin_lang('msg_upload_success'));
    }

    /**
     * @title 重新生成路由[webman]
     */
    public function makeRoute()
    {
        RouteLoader::load(true);

        $builder = Builder::getInstance();
        return $builder->layer()->closeRefresh(1, __admin_lang('msg_route_regenerated'));
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

        $form->tab(__admin_lang('help_basic_info'));
        $form->raw('name')->value($instance->getName());
        $form->raw('title')->value($instance->getTitle());
        $form->raw('tags')->value($instance->getTags());
        $form->raw('desc')->value($instance->getDescription());
        $form->raw('version')->value($instance->getVersion());
        if ($type == 1) {
            if (is_file($instance->getRoot() . 'data' . DIRECTORY_SEPARATOR . 'install.sql')) {
                $form->show('sql')->value(__admin_lang('label_install_will_run_sql'));
            } else {
                $form->show('sql')->value(__admin_lang('label_none'));
            }
        } else if ($type == 2) {
            if (is_file($instance->getRoot() . 'data' . DIRECTORY_SEPARATOR . 'uninstall.sql')) {

                $app_debug = config('app_debug');

                $form->checkbox('sql')->options([1 => __admin_lang('label_uninstall_will_run_sql')])->value($app_debug ? 1 : 0)->help($app_debug ? '<label class="label label-default">' . __admin_lang('label_debug_mode') . '</label>' : '<label class="label label-danger">' . __admin_lang('label_non_debug_mode') . '</label>');
            } else {
                $form->show('uninstall')->value(__admin_lang('label_none'));
            }
        }

        if ($isModule) {
            $form->tab(__admin_lang('help_modules_menus'));
            $form->raw('modules')->value(!empty($bindModules) ? '<pre>' . implode("\n", $bindModules) . '</pre>' : __admin_lang('label_none'));
            $form->raw('menus')->value(!empty($menus) ? '<pre>' . implode("\n", $this->menusTree($menus)) . '</pre>' : __admin_lang('label_none'));
        }

        $form->tab(__admin_lang('help_readme'));
        $README = __admin_lang('label_no_data');

        if (is_file($instance->getRoot() . 'README.md')) {
            $README = file_get_contents($instance->getRoot() . 'README.md');
        }
        $form->mdreader('README')->jsOptions(['readOnly' => true, 'width' => 1200])->size(0, 12)->showLabel(false)->value($README);

        $form->tab(__admin_lang('help_changelog'), $type == 3);
        $README = __admin_lang('label_no_data');
        if (is_file($instance->getRoot() . 'CHANGELOG.md')) {
            $README = file_get_contents($instance->getRoot() . 'CHANGELOG.md');
        }
        $form->mdreader('CHANGELOG')->jsOptions(['readOnly' => true, 'width' => 1200])->size(0, 12)->showLabel(false)->value($README);

        $form->tab(__admin_lang('help_license'));
        $LICENSE = __admin_lang('label_no_data');
        if (is_file($instance->getRoot() . 'LICENSE.txt')) {
            $LICENSE = '<pre>' . htmlspecialchars(file_get_contents($instance->getRoot() . 'LICENSE.txt')) . '</pre>';
        } else if (is_file($instance->getRoot() . 'LICENSE')) {
            $LICENSE = '<pre>' . htmlspecialchars(file_get_contents($instance->getRoot() . 'LICENSE')) . '</pre>';
        }

        $form->raw('LICENSE')->size(0, 12)->showLabel(false)->value($LICENSE);

        $form->ajax(false);

        if ($type == 1) {
            $form->btnSubmit(__admin_lang('btn_submit'), '6 col-lg-6 col-sm-6 col-xs-6', 'btn-success');
            $form->btnLayerClose(__admin_lang('btn_back'), '6 col-lg-6 col-sm-6 col-xs-6');
        } else if ($type == 2) {
            $form->btnSubmit(__admin_lang('btn_submit_uninstall'), '6 col-lg-6 col-sm-6 col-xs-6', 'btn-danger');
            $form->btnLayerClose(__admin_lang('btn_back'), '6 col-lg-6 col-sm-6 col-xs-6');
        } else if ($type == 3) {
            $form->btnSubmit(__admin_lang('btn_submit_upgrade'), '6 col-lg-6 col-sm-6 col-xs-6', 'btn-warning');
            $form->btnLayerClose(__admin_lang('btn_back'), '6 col-lg-6 col-sm-6 col-xs-6');
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
            $this->error(__admin_lang('msg_param_error'));
        }

        $instance = $this->extensions[$ids[0]];

        $instance->copyAssets(true);

        $this->success(__admin_lang('msg_refresh_success'));
    }

    public function enable()
    {
        $id = input('post.id', '');
        $value = input('post.value', '0');

        if (empty($id)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $id = str_replace('-', '\\', $id);

        if (!isset($this->extensions[$id])) {
            $this->error(__admin_lang('msg_ext_not_exists'));
        }

        $instance = $this->extensions[$id];
        $res = $instance->enabled($value);

        if ($res) {
            $this->dataModel->update(['enable' => $value], ['key' => $id]);
            $this->success(($value == 1 ? __admin_lang('msg_enable_success') : __admin_lang('msg_disable_success')));
        } else {
            $this->error(__admin_lang('msg_operation_failed'));
        }
    }

    protected function checkToken()
    {
        $token = Session::get('_csrf_token_');

        if (empty($token) || $token != input('__token__')) {
            $this->error(__admin_lang('msg_token_error'));
        }
    }
}

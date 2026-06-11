<?php

namespace tpext\manager\admin\controller;

use tpext\think\App;
use think\Controller;
use tpext\common\ExtLoader;
use tpext\common\TpextCore;
use tpext\manager\common\Module;
use tpext\builder\Common\Form;
use tpext\builder\common\Table;
use tpext\builder\common\Builder;
use tpext\common\model\WebConfig;

/**
 * Undocumented class
 * @title 平台设置
 */
class Config extends Controller
{
    protected $extensions = [];

    /**
     * Undocumented variable
     *
     * @var WebConfig
     */
    protected $dataModel;

    protected function initialize()
    {
        Module::getInstance()->loadLang('config');

        $this->extensions = ExtLoader::getExtensions();

        $this->extensions[TpextCore::class] = TpextCore::getInstance();

        ksort($this->extensions);

        $this->dataModel = new WebConfig;
    }

    public function index()
    {
        $confkey = input('confkey');

        $builder = Builder::getInstance(__admin_lang('page_config_manage'), __admin_lang('page_config_edit'));

        $installed = ExtLoader::getInstalled();

        $rootPath = App::getRootPath();

        if (request()->isPut()) {
            $data = request()->post();

            if (!isset($data['config_key'])) {
                $this->success(__admin_lang('msg_reload_config'), url('index'));
            }

            $config_key = $data['config_key'];

            $theConfig = $this->dataModel->where('key', $config_key)->find();
            if (!$theConfig) {
                $this->success(sprintf(__admin_lang('msg_key_not_exists'), $config_key), url('index'));
            }

            $filePath = $rootPath . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $theConfig['file']);

            if (!is_file($filePath)) {
                $this->error(sprintf(__admin_lang('msg_original_config_not_found'), $theConfig['file']));
            }

            $default = include $filePath;

            unset($data['__config__']);
            unset($data['config_key']);

            $res = $this->seveConfig($default, $data, $config_key, $filePath);

            if ($res) {
                $this->success(__admin_lang('msg_edit_success_refreshing'), url('index', ['confkey' => $config_key]));
            } else {
                $this->error(__admin_lang('msg_edit_failed_or_no_change'));
            }
        } else {
            $tab = $builder->tab()->vertical();
            $extensionsKeys = [];
            $theConfig = null;

            foreach ($this->extensions as $key => $instance) {
                $is_install = 0;

                $default = $instance->defaultConfig(true);

                $has_config = !empty($default);

                foreach ($installed as $ins) {
                    if ($ins['key'] == $key) {
                        $is_install = $ins['install'];
                        break;
                    }
                }

                $config_key = $instance->getId();
                $extensionsKeys[] = $config_key;

                if (!$is_install || !$has_config) {
                    continue;
                }

                $theConfig = $this->dataModel->where(['key' => $config_key])->find();

                if (!$theConfig) {
                    unset($default['__config__'], $default['__saving__']);

                    $this->dataModel->create(
                        [
                            'key' => $config_key,
                            'title' => $instance->getTitle(),
                            'file' => str_replace($rootPath, '', $instance->configPath()),
                            'config' => json_encode($default, JSON_UNESCAPED_UNICODE),
                        ]
                    );
                }

                $saved = $theConfig ? json_decode($theConfig['config'], 1) : [];
                $form = $tab->form($instance->getTitle(), $confkey == $config_key);
                $form->formId('the-from' . $config_key);
                $form->hidden('config_key')->value($config_key);
                $form->method('put');
                $this->buildConfig($form, $default, $saved);
            }

            $others = $this->dataModel->where('key', 'not in', $extensionsKeys)->select();

            foreach ($others as $oth) {
                $filePath = $rootPath . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $oth['file']);
                if (!is_file($filePath)) {
                    continue;
                }

                $default = include $filePath;

                $saved = json_decode($oth['config'], 1);
                $form = $tab->form($oth['title'], $confkey == $oth['key']);
                $form->formId('the-from' . $oth['key']);
                $form->hidden('config_key')->value($oth['key']);
                $form->method('put');
                $this->buildConfig($form, $default, $saved);
                $form->html('', __admin_lang('config_key'))->value("<pre>" . $oth['key'] . "</pre>")->size(2, 8);
            }

            $table = $tab->table(__admin_lang('page_more_settings'), $confkey == '__config_list__');
            $this->buildList($table);

            return $builder->render();
        }
    }

    public function add()
    {
        if (request()->isAjax()) {

            $data = request()->only([
                'title',
                'key',
                'file',
            ], 'post');

            $result = $this->validate($data, [
                'title|' . __admin_lang('title') => 'require',
                'file|' . __admin_lang('file') => 'require',
            ]);

            if (true !== $result) {
                $this->error($result);
            }

            $filePath = App::getRootPath() . $data['file'];

            if (!is_file($filePath)) {
                $this->error(__admin_lang('msg_file_not_found'));
            }

            if (!preg_match('/.+?(\w+)\.php$/', $data['file'], $matches)) {
                $this->error(__admin_lang('msg_not_php_file'));
            }

            if (preg_match('/config\/(app|database)\.php$/i', $data['file'])) {
                $this->error(__admin_lang('msg_forbidden_for_security'));
            }

            if (empty($data['key'])) {
                $data['key'] = $matches[1];
            }

            if ($this->dataModel->where(['key' => $data['key']])->find()) {
                $this->error(sprintf(__admin_lang('msg_key_already_exists'), $data['key']));
            }

            $config = include $filePath;

            unset($config['__config__'], $config['__saving__']); //

            $res = $this->dataModel->create(
                [
                    'key' => $data['key'],
                    'title' => $data['title'],
                    'file' => $data['file'],
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                ]
            );

            if ($res) {
                return Builder::getInstance()->layer()->closeGo(1, __admin_lang('msg_create_success'), url('index', ['confkey' => $data['key']]));
            } else {
                $this->error(__admin_lang('msg_create_failed'));
            }
        } else {

            $template = <<<EOT
            <pre>
            &lt?php
            return [
                'allowSuffix' => 'jpg,jpeg,gif,wbmp,webpg,png,bmp',
                'maxSize' => 20,
                'isRandName' => 1,
                //Config description, defaults to text if not set
                '__config__' => [
                    'allowSuffix' => ['type' => 'textarea', 'label' => 'Allowed file extensions', 'size' => [2, 10], 'help' => 'Separated by commas'],
                    'maxSize' => ['type' => 'number', 'label' => 'Upload size limit (MB)', 'col_size' => 6, 'size' => [3, 8], 'required' => 1],
                    'isRandName' => ['type' => 'radio', 'label' => 'Random filename', 'options' => [0 => 'No', 1 => 'Yes'], 'col_size' => 6, 'size' => [3, 8]],
                ], //Supports [tpext-builder] form elements, most needs can be met. Config values should be regular types; arrays will be converted to JSON.

                // '__config__' => function(\\tpext\\builder\\common\\Form \$form, &\$data){
                //     \$form->textarea('allowSuffix', 'Allowed file extensions')->size(2, 10)->help('Separated by commas');
                //     \$form->number('maxSize', 'Upload size limit (MB)', 6)->size(3, 8)->required();
                //     \$form->radio('isRandName', 'Random filename', 6)->size(3, 8)->options([0 => 'No', 1 => 'Yes']);
                // },
                // Define save callback, unless special circumstances
                // '__saving__' => function(\$data, \$values){
                //     // \$data is form submitted data, \$values is the processed data
                //     return \$values;
                // },
            ];
            //Usage: \\tpext\\common\\model\\WebConfig::config('myconfig');//Does not support config('myconfig');
            </pre>
EOT;
            $builder = Builder::getInstance(__admin_lang('page_config_manage'), __admin_lang('page_add'));
            $form = $builder->form();
            $form->text('title')->required()->help(__admin_lang('help_config_name'));
            $form->text('key')->help(__admin_lang('help_config_key_empty'));
            $form->text('file')->required()->beforSymbol('<code>RootPath .</code>')
                ->help(__admin_lang('help_file_path'));
            $form->raw('template')->value($template)->size(2, 10);

            return $builder->render();
        }
    }

    public function edit()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, __admin_lang('msg_param_error'));
        }

        $key = strtolower(str_replace('-', '_', $key));

        $instance = null;

        if (isset($this->extensions[$key])) {
            $instance = $this->extensions[$key];
        }

        $theConfig = $this->dataModel->where(['key' => $key])->find();

        $rootPath = App::getRootPath();

        $default = [];

        $title = '';

        $filePath = '';

        if ($theConfig) {
            $title = $theConfig['title'];

            $filePath = $rootPath . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $theConfig['file']);

            $default = include $filePath;
        } else if ($instance) {
            $title = $instance->getTitle();
        }

        $builder = Builder::getInstance(__admin_lang('page_config_manage'), __admin_lang('page_config_edit') . '-' . $title);

        if (request()->isAjax()) {

            if (!is_file($filePath)) {
                $this->error(sprintf(__admin_lang('msg_original_config_not_found'), $theConfig['file']));
            }

            $data = request()->post();

            $res = $this->seveConfig($default, $data, $key, $filePath);

            if ($res) {
                return $builder->layer()->closeRefresh(1, __admin_lang('msg_edit_success_refreshing'));
            } else {
                return $builder->layer()->closeRefresh(0, __admin_lang('msg_edit_failed_or_no_change'));
            }
        } else {

            $form = $builder->form();
            if (!$theConfig) {
                if ($instance) {
                    unset($default['__config__'], $default['__saving__']);
                    
                    $this->dataModel->create(
                        [
                            'key' => $instance->getId(),
                            'title' => $instance->getTitle(),
                            'file' => str_replace($rootPath, '', $instance->configPath()),
                            'config' => json_encode($default, JSON_UNESCAPED_UNICODE),
                        ]
                    );
                } else {
                    return Builder::getInstance()->layer()->close(0, __admin_lang('msg_config_not_exists'));
                }
            }

            $saved = $theConfig ? json_decode($theConfig['config'], 1) : [];

            $this->buildConfig($form, $default, $saved);

            return $builder->render();
        }
    }

    public function autopost()
    {
        $id = input('id/d', '');
        $name = input('name', '');
        $value = input('value', '');

        if (empty($id) || empty($name)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $allow = ['title'];

        if (!in_array($name, $allow)) {
            $this->error(__admin_lang('msg_operation_not_allowed'));
        }

        $res = $this->dataModel->update([$name => $value], ['id' => $id]);

        if ($res) {
            $this->success(__admin_lang('msg_edit_success'));
        } else {
            $this->error(__admin_lang('msg_edit_failed'));
        }
    }

    public function delete()
    {
        $ids = input('ids');

        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error(__admin_lang('msg_param_error'));
        }

        $res = 0;

        foreach ($ids as $id) {
            if ($id == 1) {
                continue;
            }
            if ($this->dataModel->destroy($id)) {
                $res += 1;
            }
        }

        if ($res) {
            $this->success(sprintf(__admin_lang('msg_delete_success'), $res), '', ['script' => "<script>location.reload();</script>"]);
        } else {
            $this->error(__admin_lang('msg_delete_failed'));
        }
    }

    private function buildList(Table &$table)
    {
        $table->show('id');
        $table->show('key');
        $table->text('title')->autoPost()->getWrapper()->addStyle('max-width:80px');
        $table->show('file');
        $table->show('create_time')->getWrapper()->addStyle('width:180px');
        $table->show('update_time')->getWrapper()->addStyle('width:180px');

        $table->getToolbar()
            ->btnAdd()
            ->btnDelete();

        $table->getActionbar()
            ->btnView()
            ->btnDelete();

        $table->sortable([]);

        $table->useExport(false);
        $table->useChooseColumns(false);

        $data = $this->dataModel->order('key')->select();

        $table->data($data);
    }

    /**
     * Undocumented function
     * @title 查看设置
     * 
     * @return mixed
     */
    public function view()
    {
        $id = input('id');

        if (request()->isGet()) {

            $builder = Builder::getInstance(__admin_lang('page_config_manage'), __admin_lang('page_config_view'));

            $data = $this->dataModel->find($id);
            if (!$data) {
                return $builder->layer()->close(0, __admin_lang('msg_data_not_exists'));
            }

            $form = $builder->form();
            $form->show('id');
            $form->show('key');
            $form->show('title');
            $form->show('file');
            $form->show('create_time');
            $form->show('update_time');
            $form->fill($data);

            $form->html('config')->display(
                '<pre style="white-space:pre-wrap;word-break:break-all;">{$data}</pre>',
                ['data' => json_encode(json_decode($data['config']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]
            )->size(2, 10);

            $form->readonly();
            return $builder->render();
        }
    }

    private function buildConfig(Form &$form, $default, $saved = [])
    {
        $savedKeys = array_keys($saved);

        $fieldTypes = [];

        $type = '';
        $fieldType = '';

        if (isset($default['__config__'])) {
            $fieldTypes = $default['__config__'] ?? [];
        }

        if ($fieldTypes instanceof \Closure) {

            $data = array_merge($default, $saved);
            $fieldTypes($form, $data);
            $form->fill($data);

            return;
        }

        foreach ($default as $key => $val) {

            if ($key == '__config__' || $key == '__saving__') {
                continue;
            }

            if (isset($fieldTypes[$key])) {
                $type = $fieldTypes[$key];

                $fieldType = $type['type'];

                $label = isset($type['label']) ? $type['label'] : '';
                $help = isset($type['help']) ? $type['help'] : '';
                $required = isset($type['required']) ? $type['required'] : false;
                $colSize = isset($type['col_size']) && is_numeric($type['col_size']) ? $type['col_size'] : 12;
                $size = isset($type['size']) && is_array($type['size']) && count($type['size']) == 2 ? $type['size'] : [2, 8];
                $befor = isset($type['befor']) ? $type['befor'] : '';
                $after = isset($type['after']) ? $type['after'] : '';
                $beforSymbol = isset($type['befor_symbol']) ? $type['befor_symbol'] : '';
                $afterSymbol = isset($type['after_symbol']) ? $type['after_symbol'] : '';

                $field = $form->$fieldType($key, $label, $colSize)->required($required)->help($help)->size($size[0], $size[1]);

                if (in_array($fieldType, ['divider', 'show', 'raw', 'html', 'items', 'fields', 'button', 'match', 'matches'])) {
                    $field->value($default[$key]);
                    continue;
                }
                if ($befor) {
                    $field->befor($befor);
                }
                if ($after) {
                    $field->after($after);
                }
                if ($beforSymbol) {
                    $field->beforSymbol($beforSymbol);
                }
                if ($afterSymbol) {
                    $field->afterSymbol($afterSymbol);
                }
                if (in_array($fieldType, ['radio', 'select', 'checkbox', 'multipleSelect', 'dualListbox', 'transfer'])) {

                    $field->options(isset($type['options']) ? $type['options'] : [0 => __admin_lang('msg_why_no_options'), 1 => __admin_lang('msg_why_no_options_reverse')]);
                }
            } else if (strpos($key, '__br__') !== false) {

                $field = $form->html($val);
                $field->getWrapper()->style($val ? '' : 'visibility:hidden;height:1px;padding:0;margin:0;');
                continue;
            } else if (strpos($key, '__hr__') !== false) {

                $field = $form->divider($val);
                continue;
            } else if (strpos($key, 'fieldsEnd') !== false) {

                $form->fieldsEnd();
                continue;
            } else if (strpos($key, 'itemsEnd') !== false) {

                $form->itemsEnd();
                continue;
            } else {

                $field = $form->text($key);
            }

            if (!in_array($fieldType, ['checkbox', 'multipleSelect', 'matches', 'dualListbox', 'transfer']) && is_array($val)) {
                $saved[$key] = json_encode($saved[$key], JSON_UNESCAPED_UNICODE);
            }

            if (in_array($key, $savedKeys)) {
                $field->value($saved[$key]);
            }
        }
    }

    private function seveConfig($default, $data, $configKey, $filePath)
    {
        $values = [];

        $fieldTypes = [];

        $type = '';
        $fieldType = '';

        unset($data['__token__']);

        if (isset($default['__config__'])) {
            $fieldTypes = $default['__config__'] ?: [];
        }

        if (is_array($fieldTypes)) { // __config__ 是数组的情况

            foreach ($default as $key => $val) {

                if (isset($fieldTypes[$key])) {
                    $type = $fieldTypes[$key];
                    $fieldType = strtolower($type['type']);
                }

                if ($key == '__config__' || $key == '__saving__') {
                    continue;
                }

                if (!isset($data[$key])) {
                    if (in_array($fieldType, ['checkbox', 'multipleselect', 'dualListbox', 'transfer'])) {
                        $data[$key] = [];
                    } else {
                        $data[$key] = '';
                    }
                } else {
                    if (!in_array($fieldType, ['checkbox', 'multipleselect', 'dualListbox', 'transfer']) && is_array($val)) {
                        $values[$key] = json_decode($data[$key], 1);
                    } else {
                        $values[$key] = $data[$key];
                    }
                }
            }
        } else if ($fieldTypes instanceof \Closure) { // __config__ 是匿名方法的情况

            $values = $data;
        }

        if (isset($default['__saving__']) && $default['__saving__'] instanceof \Closure) {
            $__saving__ = $default['__saving__'];
            $values = $__saving__($data, $values); //匿名方法，在数据保存前再处理一下。
        }

        $this->dataModel::clearCache($configKey);

        if ($exist = $this->dataModel->where(['key' => $configKey])->find()) {
            return $exist->force()->save(['config' => json_encode($values, JSON_UNESCAPED_UNICODE)]);
        }

        $filePath = str_replace(App::getRootPath(), '', $filePath);

        return $this->dataModel->exists(false)->save(['key' => $configKey, 'file' => $filePath, 'config' => json_encode($values, JSON_UNESCAPED_UNICODE)]);
    }
}

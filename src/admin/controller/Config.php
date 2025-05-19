<?php

namespace tpext\manager\admin\controller;

use tpext\think\App;
use think\Controller;
use tpext\common\ExtLoader;
use tpext\common\TpextCore;
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
        $this->extensions = ExtLoader::getExtensions();

        $this->extensions[TpextCore::class] = TpextCore::getInstance();

        ksort($this->extensions);

        $this->dataModel = new WebConfig;
    }

    public function index()
    {
        $confkey = input('confkey');

        $builder = Builder::getInstance('配置管理', '配置修改');

        $installed = ExtLoader::getInstalled();

        $rootPath = App::getRootPath();

        if (request()->isPut()) {
            $data = request()->post();

            if (!isset($data['config_key'])) {
                $this->success('重新加载配置...', url('index'));
            }

            $config_key = $data['config_key'];

            $theConfig = $this->dataModel->where('key', $config_key)->find();
            if (!$theConfig) {
                $this->success('key：' . $config_key . '不存在，重新加载配置...', url('index'));
            }

            $filePath = $rootPath . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $theConfig['file']);

            if (!is_file($filePath)) {
                $this->error('原始配置文件不存在：' . $theConfig['file']);
            }

            $default = include $filePath;

            unset($data['__config__']);
            unset($data['config_key']);

            $res = $this->seveConfig($default, $data, $config_key, $filePath);

            if ($res) {
                $this->success('修改成功，页面即将刷新~', url('index', ['confkey' => $config_key]));
            } else {
                $this->error('修改失败，或无变化');
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
                $form->html('', '配置键')->value("<pre>" . $oth['key'] . "</pre>")->size(2, 8);
            }

            $table = $tab->table('更多设置', $confkey == '__config_list__');
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
                'title|名称' => 'require',
                'file|文件路径' => 'require',
            ]);

            if (true !== $result) {
                $this->error($result);
            }

            $filePath = App::getRootPath() . $data['file'];

            if (!is_file($filePath)) {
                $this->error('文件不存在，请核查');
            }

            if (!preg_match('/.+?(\w+)\.php$/', $data['file'], $matches)) {
                $this->error('不是php文件，请核查');
            }

            if (preg_match('/config\/(app|database)\.php$/i', $data['file'])) {
                $this->error('安全原因禁止创建！');
            }

            if (empty($data['key'])) {
                $data['key'] = $matches[1];
            }

            if ($this->dataModel->where(['key' => $data['key']])->find()) {
                $this->error('key已存在，请核查：' . $data['key']);
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
                return Builder::getInstance()->layer()->closeGo(1, '创建成功', url('index', ['confkey' => $data['key']]));
            } else {
                $this->error('创建失败');
            }
        } else {

            $template = <<<EOT
            <pre>
            &lt?php
            return [
                'allowSuffix' => 'jpg,jpeg,gif,wbmp,webpg,png,bmp',
                'maxSize' => 20,
                'isRandName' => 1,
                //配置描述 ,若无则默认为text
                '__config__' => [
                    'allowSuffix' => ['type' => 'textarea', 'label' => '允许上传的文件后缀', 'size' => [2, 10], 'help' => '以英文,号分割'],
                    'maxSize' => ['type' => 'number', 'label' => '上传文件大小限制(MB)', 'col_size' => 6, 'size' => [3, 8], 'required' => 1],
                    'isRandName' => ['type' => 'radio', 'label' => '随机文件名', 'options' => [0 => '否', 1 => '是'], 'col_size' => 6, 'size' => [3, 8]],
                ], //支持【tpext-builder】表单元素 ，不是太复杂的大多能满足。配置的值尽量为常规类型，如果是数组则会转换成json。
                
                // '__config__' => function(\\tpext\\builder\\common\\Form \$form, &\$data){
                //     \$form->textarea('allowSuffix', '允许上传的文件后缀')->size(2, 10)->help('以英文,号分割');
                //     \$form->number('maxSize', '上传文件大小限制(MB)', 6)->size(3, 8)->required();
                //     \$form->radio('isRandName', '随机文件名', 6)->size(3, 8)->options([0 => '否', 1 => '是']);
                // },
                // 定义保存时的回调，除非特殊情况
                // '__saving__' => function(\$data, \$values){
                //     // \$data 为表单提交数据,\$values为经过处理的数据
                //     return \$values;
                // },
            ];
            //使用 \\tpext\\common\\model\\WebConfig::config('myconfig');//不支持config('myconfig');
            </pre>
EOT;
            $builder = Builder::getInstance('配置管理', '添加');
            $form = $builder->form();
            $form->text('title', '名称')->required()->help('给配置取个名字，如: 商城');
            $form->text('key', '配置键')->help('不填则以文件名为键');
            $form->text('file', '文件路径')->required()->beforSymbol('<code>RootPath .</code>')
                ->help('文件路径，从网站根目录开始，如 <code>conf/myconfig.php</code>(不建议使用`config`/里面的文件做配置)');
            $form->raw('template', '示例')->value($template)->size(2, 10);

            return $builder->render();
        }
    }

    public function edit()
    {
        $key = input('key');

        if (empty($key)) {
            return Builder::getInstance()->layer()->close(0, '参数有误！');
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

        $builder = Builder::getInstance('配置管理', '配置-' . $title);

        if (request()->isAjax()) {

            if (!is_file($filePath)) {
                $this->error('原始配置文件不存在：' . $theConfig['file']);
            }

            $data = request()->post();

            $res = $this->seveConfig($default, $data, $key, $filePath);

            if ($res) {
                return $builder->layer()->closeRefresh(1, '修改成功，页面即将刷新~');
            } else {
                return $builder->layer()->closeRefresh(0, '修改失败，或无变化');
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
                    return Builder::getInstance()->layer()->close(0, '配置不存在！');
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
            $this->error('参数有误');
        }

        $allow = ['title'];

        if (!in_array($name, $allow)) {
            $this->error('不允许的操作');
        }

        $res = $this->dataModel->update([$name => $value], ['id' => $id]);

        if ($res) {
            $this->success('修改成功');
        } else {
            $this->error('修改失败');
        }
    }

    public function delete()
    {
        $ids = input('ids');

        $ids = array_filter(explode(',', $ids), 'strlen');

        if (empty($ids)) {
            $this->error('参数有误');
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
            $this->success('成功删除' . $res . '条数据', '', ['script' => "<script>location.reload();</script>"]);
        } else {
            $this->error('删除失败');
        }
    }

    private function buildList(Table &$table)
    {
        $table->show('id', 'ID');
        $table->show('key', '配置键');
        $table->text('title', '名称')->autoPost()->getWrapper()->addStyle('max-width:80px');
        $table->show('file', '路径');
        $table->show('create_time', '添加时间')->getWrapper()->addStyle('width:180px');
        $table->show('update_time', '修改时间')->getWrapper()->addStyle('width:180px');

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

            $builder = Builder::getInstance('配置管理', '配置查看');

            $data = $this->dataModel->get($id);
            if (!$data) {
                return $builder->layer()->close(0, '数据不存在');
            }

            $form = $builder->form();
            $form->show('id', 'ID');
            $form->show('key', '配置键');
            $form->show('title', '名称');
            $form->show('file', '路径');
            $form->show('create_time', '添加时间');
            $form->show('update_time', '修改时间');
            $form->fill($data);

            $form->html('config', '配置内容')->display(
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

                    $field->options(isset($type['options']) ? $type['options'] : [0 => '为什么没有选项？', 1 => '？项选有没么什为']);
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

        if ($this->dataModel->where(['key' => $configKey])->find()) {
            return $this->dataModel->save(['config' => json_encode($values, JSON_UNESCAPED_UNICODE)], ['key' => $configKey]);
        }

        $filePath = str_replace(App::getRootPath(), '', $filePath);

        return $this->dataModel->save(['key' => $configKey, 'file' => $filePath, 'config' => json_encode($values, JSON_UNESCAPED_UNICODE)]);
    }
}

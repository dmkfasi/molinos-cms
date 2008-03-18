<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2 fenc=utf8 enc=utf8:

class WidgetNode extends Node implements iContentType
{
  // Проверяем на уникальность.
  public function save($clear = true)
  {
    if ($isnew = (null === $this->id))
      $this->data['published'] = true;

    if (empty($this->description)) {
      $info = Widget::getInfo($this->classname);
      $this->description = $info['description'];
    }

    if ($this->parent_id !== null)
      throw new InvalidArgumentException("Виджет не может быть дочерним объектом.");

    $this->name = trim($this->name);

    if (!empty($_SERVER['HTTP_HOST']) and (empty($this->name) or strspn(mb_strtolower($this->name), "abcdefghijklmnopqrstuvwxyz0123456789_") != strlen($this->name)))
      throw new UserErrorException("Неверное имя", 400, "Неверное имя", "Имя виджета может содержать только буквы латинского алфавита и цифры.&nbsp; Пожалуйста, переименуйте виджет.");

    if ($this->id === null and Node::count(array('class' => 'widget', 'name' => $this->name)))
      throw new InvalidArgumentException("Виджет с таким именем уже существует.");

    return parent::save(false);
  }

  // Проверка прав на объект.  Девелоперы всегда всё могут.
  public function checkPermission($perm)
  {
    if (mcms::user()->hasGroup('Developers'))
      return true;
    return NodeBase::checkPermission($perm);
  }

  public function duplicate()
  {
    $this->name = preg_replace('/_[0-9]+$/', '', $this->name) .'_'. rand();
    parent::duplicate();
  }

  public function formGet($simple = true)
  {
    if (null === $this->id) {
      $classes = array();

      foreach (bebop_get_interface_map('iWidget') as $classname) {
        if ($classname != 'Widget' and substr($classname, -11) != 'AdminWidget') {
          $info = Widget::getInfo($classname);
          if (empty($info['hidden']) and !empty($info['name']))
            $classes[$classname] = $info['name'];
        }
      }

      asort($classes);

      $form = new Form(array(
        'title' => t('Добавление виджета'),
        ));
      $form->addControl(new HiddenControl(array(
        'value' => 'node_content_class',
        )));
      $form->addControl(new TextLineControl(array(
        'value' => 'node_content_name',
        'label' => t('Внутреннее имя'),
        'description' => t('Может содержать только цифры, латинские буквы и символ подчёркивания.'),
        'required' => true,
        'class' => 'form-title',
        )));
      $form->addControl(new TextLineControl(array(
        'value' => 'node_content_title',
        'label' => t('Видимое название'),
        'description' => t('Может содержать произвольный текст.&nbsp; Иногда используется не только в админке, но и на сайте.'),
        'required' => true,
        )));
      $form->addControl(new EnumControl(array(
        'value' => 'node_content_classname',
        'label' => t('Тип'),
        'required' => true,
        'options' => $classes,
        )));
      $form->addControl(new SubmitControl(array(
        'text' => t('Создать'),
        )));
    } else {
      $form = parent::formGet($simple);
      $form->title = t('Редактирование виджета "%name"', array('%name' => $this->name));

      if (class_exists($this->classname))
        if (null !== ($tab = call_user_func(array($this->classname, 'formGetConfig'), $this))) {
          $tab->intro = t('Подробную информацию о настройке этого виджета можно <a href=\'@link\'>найти в документации</a>.', array(
            '@link' => 'http://code.google.com/p/molinos-cms/wiki/'. $this->classname,
            ));

          $form->addControl($tab);
        }

      $tab = new FieldSetControl(array(
        'names' => 'pages',
        'label' => t('Страницы'),
        ));

      $form->addClass($this->classname .'-config');

      $tab = new FieldSetControl(array(
        'name' => 'pages',
        'label' => t('Страницы'),
        ));
      $tab->addControl(new SetControl(array(
        'value' => 'widget_pages',
        'label' => t('Виджет работает на страницах'),
        'options' => DomainNode::getFlatSiteMap('select'),
        )));
      $form->addControl($tab);
    }

    return $form;
  }

  public function formGetData()
  {
    $data = parent::formGetData();

    if (array_key_exists('node_content_config', $data))
      unset($data['node_content_config']);

    if (!empty($this->config) and is_array($this->config))
      foreach ($this->config as $k => $v)
        $data['config_'. $k] = $v;

    $data['config_types'] = $this->linkListParents('type', true);

    if (class_exists($this->classname)) {
      $w = new $this->classname($this);
      $w->formHookConfigData($data);
    }

    $data['widget_pages'] = $this->linkListParents('domain', true);

    return $data;
  }

  public function formProcess(array $data)
  {
    $isnew = (null === $this->id);

    $config = array();

    foreach ($data as $k => $v)
      if (substr($k, 0, 7) == 'config_' and $k != 'config_types')
        $config[substr($k, 7)] = $v;

    $this->config = $config;

    if (class_exists($this->classname)) {
      $w = new $this->classname($this);
      $w->formHookConfigSaved();
    }

    if ($isnew) {
      $data['node_access'] = array(
        'Visitors' => array('r'),
        'Developers' => array('r', 'u', 'd'),
        );
    } else {
      $list = (empty($data['config_types']) or !is_array($data['config_types'])) ? array() : $data['config_types'];
      $this->linkSetParents($list, 'type');
    }

    $next = parent::formProcess($data);

    if (empty($data['widget_pages']))
      $data['widget_pages'] = array();

    $this->linkSetParents($data['widget_pages'], 'domain', array_keys(DomainNode::getFlatSiteMap('select')));

    if ($isnew)
      $next = "/admin/node/{$this->id}/edit/?destination=". urlencode($_GET['destination']) ."#config";

    return $next;
  }
};

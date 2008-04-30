<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class TinyMceModule implements iModuleConfig, iPageHook
{
  public static function formGetModuleConfig()
  {
    $form = new Form(array());
    $form->addClass('tabbed');

    $tab = new FieldSetControl(array(
      'name' => 'main',
      'label' => t('Основные настройки'),
      ));
    $tab->addControl(new EnumControl(array(
      'value' => 'config_theme',
      'label' => t('Режим работы'),
      'options' => array(
        'simple' => t('Простой (B/I/U)'),
        'medium' => t('Простой с картинками'),
        'advanced' => t('Всё, что можно'),
        'overkill' => t('На стероидах'),
        ),
      'required' => true,
      )));
    $tab->addControl(new BoolControl(array(
      'value' => 'config_gzip',
      'label' => t('Использовать компрессию'),
      )));
    $tab->addControl(new EnumControl(array(
      'value' => 'config_toolbar',
      'label' => t('Панель инструментов'),
      'required' => true,
      'options' => array(
        'topleft' => t('Сверху слева'),
        'topcenter' => t('Сверху по центру'),
        'bottomcenter' => t('Снизу по центру'),
        ),
      )));
    $tab->addControl(new EnumControl(array(
      'value' => 'config_path',
      'label' => t('Текущий элемент'),
      'required' => true,
      'options' => array(
        '' => t('Не показывать'),
        'bottom' => t('Снизу'),
        ),
      'description' => t('При отключении пропадает также возможность изменять размер редактора мышью.'),
      )));
    $form->addControl($tab);

    $tab = new FieldSetControl(array(
      'name' => 'pages',
      'label' => t('Страницы'),
      ));
    $tab->addControl(new InfoControl(array(
      'text' => t('Этот модуль всегда используется в административном интерфейсе, отключить его нельзя.'),
      )));
    $tab->addControl(new SetControl(array(
      'value' => 'config_pages',
      'label' => t('Использовать редактор на страницах'),
      'options' => DomainNode::getFlatSiteMap('select', true),
      )));
    $form->addControl($tab);

    return $form;
  }

  private static function listDirs($path)
  {
    $list = array();

    foreach (glob(dirname(__FILE__) .'/editor/'. $path .'/'.'*', GLOB_ONLYDIR) as $d)
      if (is_dir($d)) {
        $k = basename($d);
        $list[$k] = $k;
      }

    asort($list);

    return $list;
  }

  public static function hookPostInstall()
  {
  }

  public static function hookPage(&$output, Node $page)
  {
    if ('text/html' != $page->content_type)
      return;

    $config = mcms::modconf('tinymce');

    if (substr($_SERVER['REQUEST_URI'], 0, 7) != '/admin/')
      if (empty($config['pages']) or !in_array($page->id, $config['pages']))
        return;

    if (false === strstr($output, 'visualEditor'))
      return;

    if (empty($config['gzip'])) {
      $html = '<script type=\'text/javascript\' src=\'/lib/modules/tinymce/editor/tiny_mce.js\'></script>';
    } else {
      $html = '<script type=\'text/javascript\' src=\'/lib/modules/tinymce/editor/tiny_mce_gzip.js\'></script>';
    }

    if (!strlen($tmp = self::getInit($config)))
      return;

    $html .= $tmp;

    $html .= '<script type=\'text/javascript\' src=\'/lib/modules/tinymce/file_picker.js\'></script>';

    if (!empty($html))
      $output = str_replace('</head>', $html .'</head>', $output);
  }

  private static function getInit(array $config, $gzip = false)
  {
    $files = array();
    $path = dirname(__FILE__) .'/editor';

    switch ($config['theme']) {
    case 'simple':
    case 'medium':
    case 'advanced':
    case 'overkill':
      if (!empty($config['gzip']))
        $files[] = $path .'/template_'. $config['theme'] .'_gzip.js';
      $files[] = $path .'/template_'. $config['theme'] .'.js';
      break;
    }

    $output = '';

    foreach ($files as $f) {
      if (file_exists($f) and is_readable($f)) {
        $tmp = trim(file_get_contents($f));
        $output .= '<script type=\'text/javascript\'>'. $tmp .'</script>';
      }
    }

    return $output;
  }
}

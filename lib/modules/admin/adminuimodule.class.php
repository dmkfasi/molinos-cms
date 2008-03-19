<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class AdminUIModule implements iAdminUI
{
  public static function onGet(RequestContext $ctx)
  {
    $result = array();

    if (null === ($module = $ctx->get('module')))
      $result['content'] = self::onGetInternal($ctx);

    elseif (!count($classes = mcms::getImplementors('iAdminUI', $module)))
      throw new PageNotFoundException();

    else
      $result['content'] = call_user_func_array(array($classes[0], 'onGet'), array($ctx));

    $result['dashboard'] = self::getDashboardIcons();

    $output = bebop_render_object('page', 'admin', 'admin', $result);

    header('Content-Type: text/html; charset=utf-8');
    die($output);
  }

  private static function onGetInternal(RequestContext $ctx)
  {
    switch ($mode = $ctx->get('mode', 'status')) {
    case 'list':
    case 'edit':
    case 'create':
    case 'logout':
    case 'status':
    case 'modules':
    case 'drafts':
    case 'trash':
      $method = 'onGet'. ucfirst(strtolower($mode));
      return call_user_func_array(array('AdminUIModule', $method), array($ctx));
    default:
      throw new PageNotFoundException();
    }
  }

  private static function onGetList(RequestContext $ctx)
  {
    $tmp = new AdminListHandler($ctx);
    return $tmp->getHTML($ctx->get('preset'));
  }

  private static function onGetListActions(RequestContext $ctx)
  {
    switch ($ctx->get('type')) {
    case 'user':
      return array(
        'delete',
        'enable',
        'disable',
        'clone',
        );
    case 'group':
      return array(
        'delete',
        'clone',
        );
    default:
      if ($ctx->get('deleted'))
        return array(
          'undelete',
          'erase',
          );
      return array(
        'delete',
        'publish',
        'unpublish',
        'clone',
        );
    }
  }

  private static function getDashboardIcons()
  {
    $result = array();

    $classes = mcms::getClassMap();
    $rootlen = strlen(dirname(dirname(dirname(dirname(__FILE__)))));

    foreach (mcms::getImplementors('iDashboard') as $class) {
      $icons = call_user_func(array($class, 'getDashboardIcons'));

      if (is_array($icons) and !empty($icons))
        foreach ($icons as $icon) {
          if (!empty($icon['img'])) {
            $classpath = dirname($classes[strtolower($class)]);
            $icon['img'] = substr($classpath, $rootlen) .'/'. $icon['img'];
          }

          $result[$icon['group']][] = $icon;
        }
    }

    return $result;
  }

  private static function onGetEdit(RequestContext $ctx)
  {
    if (null === ($nid = $ctx->get('id')))
      throw new PageNotFoundException();

    $node = Node::load(array('id' => $nid));

    $form = $node->formGet();
    $form->addClass('tabbed');

    return $form->getHTML($node->formGetData());
  }

  private static function onGetCreate(RequestContext $ctx)
  {
    if (null !== $ctx->get('type')) {
      $node = Node::create($type = $ctx->get('type'));

      $form = $node->formGet(false);
      $form->action = "/nodeapi.rpc?action=create&type={$type}&destination=". urlencode($_GET['destination']);

      return $form->getHTML($node->formGetData());
    }

    $types = Node::find(array(
      'class' => 'type',
      '-name' => array('type', 'widget', 'user', 'group', 'domain', 'tag', 'file'),
      '#sort' => array('type.title' => 'asc'),
      ));

    $output = '<dl>';

    foreach ($types as $type) {
      $output .= '<dt>';
      $output .= mcms::html('a', array(
        'href' => "/admin/?mode=create&type={$type->name}&destination=". urlencode($_GET['destination']),
        ), $type->title);
      $output .= '</dt>';

      if (isset($type->description))
        $output .= '<dd>'. $type->description .'</dd>';
    }

    $output .= '</dl>';

    return '<h2>Какой документ вы хотите создать?</h2>'. $output;
  }

  private static function onGetLogout(RequestContext $ctx)
  {
    mcms::user()->authorize();
    bebop_redirect($_GET['destination']);
  }

  private static function onGetStatus(RequestContext $ctx)
  {
    return 'Система в порядке.';
  }

  private static function onGetModules(RequestContext $ctx)
  {
    if (null !== ($tmp = $ctx->get('info')))
      return self::onGetModuleInfo($tmp);

    $map = self::onGetModulesGroups();

    $output = '';

    foreach ($map as $group => $modules) {
      $output .= "<tr class='modgroup'><th colspan='4'>{$group}</th></tr>";

      foreach ($modules as $modname => $module) {
        $output .= '<tr>';
        $output .= "<td><input type='checkbox' name='selected[]' value='{$modname}' /></td>";
        $output .= "<td><a href='/admin/?mode=modules&action=info&name={$modname}'>{$modname}</a></td>";
        $output .= "<td>{$module['name']['ru']}</td>";

        if (!empty($module['implementors']['iModuleConfig']))
          $output .= "<td><a href='/admin/?mode=modules&action=config&name={$modname}'>настроить</a></td>";
        else
          $output .= "<td>&nbsp;</td>";

        $output .= '</tr>';
      }
    }

    $output = mcms::html('table', array(
      'class' => 'modlist',
      ), $output);

    $output .= mcms::html('input', array(
      'type' => 'submit',
      'value' => t('Сохранить'),
      ));

    return mcms::html('form', array(
      'method' => 'post',
      'action' => $_SERVER['REQUEST_URI'],
      ), $output);
  }

  private static function onGetModuleInfo($name)
  {
    $map = mcms::getModuleMap();

    if (empty($map['modules'][$name]))
      throw new PageNotFoundException();

    $module = $map['modules'][$name];

    $output = "<h2>Информация о модуле mod_{$name}</h2>";
    $output .= '<table class=\'modinfo\'>';
    $output .= '<tr><th>Описание:</th><td>'. $module['name']['ru'] .'</td></tr>';
    $output .= '<tr><th>Классы:</th><td>'. join(', ', $module['classes']) .'</td></tr>';
    $output .= '<tr><th>Интерфейсы:</th><td>'. join(', ', $module['interfaces']) .'</td></tr>';
    $output .= '<tr><th>Минимальная версия CMS:</th><td>'. $module['version'] .'</td></tr>';

    if (!empty($module['docurl']))
      $output .= '<tr><th>Документация:</th><td><a href=\''. $module['docurl'] .'\'>есть</td></tr>';

    $output .= '</table>';

    return $output;

    bebop_debug($name, $module);
  }

  private static function onGetModulesGroups()
  {
    $map = mcms::getModuleMap();

    $groups = array();

    foreach ($map['modules'] as $modname => $module)
      $groups[$module['group']][$modname] = $module;

    return $groups;
  }
};

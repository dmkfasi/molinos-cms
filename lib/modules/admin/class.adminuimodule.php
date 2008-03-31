<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class AdminUIModule implements iAdminUI
{
  public static function onGet(RequestContext $ctx)
  {
    if (!mcms::user()->hasGroup('Content Managers'))
      throw new ForbiddenException();

    if (bebop_is_debugger() and !empty($_GET['flush'])) {
      mcms::flush(false);
      mcms::flush(true);

      bebop_redirect(bebop_split_url());
    }

    $result = array();

    if (null === ($module = $ctx->get('module')))
      $result['content'] = self::onGetInternal($ctx);

    elseif (!count($classes = mcms::getImplementors('iAdminUI', $module))) {
      throw new PageNotFoundException();
    }

    else {
      $result['content'] = call_user_func_array(array($classes[0], 'onGet'), array($ctx));
    }

    $tmp = new AdminMenu();
    $result['dashboard'] = $tmp->getHTML();

    $output = bebop_render_object('page', 'admin', 'admin', $result);

    header('Content-Type: text/html; charset=utf-8');
    die($output);
  }

  private static function onGetInternal(RequestContext $ctx)
  {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      $url = bebop_split_url();
      $url['args']['search'] = empty($_POST['search']) ? null : $_POST['search'];
      bebop_redirect($url);
    }

    switch ($mode = $ctx->get('mode', 'status')) {
    case 'list':
    case 'tree':
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

  private static function onGetTree(RequestContext $ctx)
  {
    $tmp = new AdminTreeHandler($ctx);
    return $tmp->getHTML($ctx->get('preset'));
  }

  private static function onGetEdit(RequestContext $ctx)
  {
    if (null === ($nid = $ctx->get('id')))
      throw new PageNotFoundException();

    $node = Node::load(array('id' => $nid));

    $form = $node->formGet(false);
    $form->addClass('tabbed');

    return $form->getHTML($node->formGetData());
  }

  private static function onGetCreate(RequestContext $ctx)
  {
    if (null !== $ctx->get('type')) {
      $node = Node::create($type = $ctx->get('type'), array(
        'parent_id' => $ctx->get('parent'),
        ));

      $form = $node->formGet(false);
      $form->addClass('tabbed');
      $form->addClass("node-{$type}-create-form");
      $form->action = "/nodeapi.rpc?action=create&type={$type}&destination=". urlencode($_GET['destination']);

      return $form->getHTML($node->formGetData());
    }

    $types = Node::find(array(
      'class' => 'type',
      '-name' => TypeNode::getInternal(),
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
    if ($ctx->get('action') == 'info' or $ctx->get('action') == 'config')
      return self::onGetModuleInfo($ctx->get('name'));

    $tmp = new ModuleAdminUI();
    return $tmp->getList();
  }

  private static function onGetModuleInfo($name)
  {
    $map = mcms::getModuleMap();

    if (empty($map['modules'][$name]))
      throw new PageNotFoundException();

    $module = $map['modules'][$name];

    $classes = $module['classes'];
    sort($classes);

    $output = "<h2>Информация о модуле mod_{$name}</h2>";
    $output .= '<table class=\'modinfo\'>';
    $output .= '<tr><th>Описание:</th><td>'. $module['name']['ru'] .'</td></tr>';
    $output .= '<tr><th>Классы:</th><td>'. join(', ', $classes) .'</td></tr>';

    if (!empty($module['interfaces']))
      $output .= '<tr><th>Интерфейсы:</th><td>'. join(', ', $module['interfaces']) .'</td></tr>';

    $output .= '<tr><th>Версия CMS:</th><td>≥'. $module['version'] .'</td></tr>';

    if (!empty($module['docurl'])) {
      $tmp = bebop_split_url($module['docurl']);

      $output .= '<tr><th>Документация:</th><td><a href=\''. $module['docurl'] .'\'>'
        .str_replace('www.', '', $tmp['host'])
        .'</td></tr>';
    }

    $output .= '</table>';

    return $output;
  }
};

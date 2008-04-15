<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class AdminMenu implements iAdminMenu
{
  public function getHTML()
  {
    $cgroup = empty($_GET['cgroup']) ? 'content' : $_GET['cgroup'];

    if ($tmp = BebopCache::getInstance()->{'adminmenu:'. $cgroup})
      return $tmp;

    $trans = array(
      'access' => t('Доступ'),
      'content' => t('Наполнение'),
      'developement' => t('Разработка'),
      'statistics' => t('Статистика'),
      'structure' => t('Структура'),
      );

    $menu = $this->getIcons();

    $output = '<ul>';

    foreach ($menu as $group => $icons) {
      $url = bebop_split_url($icons[0]['href']);
      $url['args']['cgroup'] = $group;

      if ($group == $cgroup)
        $output .= '<li class=\'current\'>';
      else
        $output .= '<li>';

      $output .= mcms::html('a', array(
        'href' => bebop_combine_url($url, false),
        ), array_key_exists($group, $trans) ? $trans[$group] : $group);

      $output .= '<ul>';

      foreach ($icons as $icon) {
        $tmp = mcms::html('a', array(
          'href' => $icon['href'],
          'title' => empty($icon['description']) ? null : $icon['description'],
          ), $icon['title']);
        $output .= mcms::html('li', array(), $tmp);
      }

      $output .= '</ul></li>';
    }

    $output .= '</ul>';

    BebopCache::getInstance()->{'adminmenu:'. $cgroup} = $output;

    return $output;
  }

  private function getIcons()
  {
    $result = array();

    $classes = mcms::getClassMap();
    $rootlen = strlen(dirname(dirname(dirname(dirname(__FILE__)))));

    foreach (mcms::getImplementors('iAdminMenu') as $class) {
      $icons = call_user_func(array($class, 'getMenuIcons'));

      if (is_array($icons) and !empty($icons)) {
        foreach ($icons as $icon) {
          $tmp = bebop_split_url($icon['href']);
          $tmp['args']['cgroup'] = $icon['group'];
          $icon['href'] = bebop_combine_url($tmp, false);

          $result[$icon['group']][] = $icon;
        }
      }
    }

    ksort($result);

    return $result;
  }

  public static function getMenuIcons()
  {
    $icons = array();
    $user = mcms::user();

    if ($user->hasAccess('u', 'tag'))
      $icons[] = array(
        'group' => 'content',
        'href' => '/admin/?mode=tree&preset=taxonomy',
        'title' => t('Разделы'),
        'description' => t('Управление разделами сайта.'),
        'weight' => -1,
        );

    if ($user->hasAccess('u', 'type'))
      $icons[] = array(
        'group' => 'structure',
        'href' => '/admin/?mode=list&preset=schema',
        'title' => t('Типы документов'),
        );

    if (count($user->getAccess('u'))) {
      $icons[] = array(
        'group' => 'content',
        'href' => '/admin/?mode=list&columns=name,class,uid,created',
        'title' => t('Документы'),
        'description' => t('Поиск, редактирование, добавление документов.'),
        );
      if (Node::count(array('published' => 0, '-class' => TypeNode::getInternal())))
        $icons[] = array(
          'group' => 'content',
          'href' => '/admin/?mode=list&preset=drafts',
          'title' => t('В модерации'),
          'description' => t('Поиск, редактирование, добавление документов.'),
          );
    }

    if ($user->hasAccess('u', 'domain')) {
      $icons[] = array(
        'group' => 'structure',
        'href' => '/admin/?mode=tree&preset=pages',
        'title' => t('Страницы'),
        'description' => t('Управление доменами, страницами и виджетами.'),
        );
      $icons[] = array(
        'group' => 'structure',
        'href' => '/admin/?mode=list&preset=widgets',
        'title' => t('Виджеты'),
        );
      $icons[] = array(
        'group' => 'structure',
        'href' => '/admin/?mode=modules',
        'title' => t('Модули'),
        );
    }

    if ($user->hasAccess('u', 'user'))
      $icons[] = array(
        'group' => 'access',
        'href' => '/admin/?mode=list&preset=users',
        'title' => t('Пользователи'),
        'description' => t('Управление профилями пользователей.'),
        );
    if ($user->hasAccess('u', 'group'))
      $icons[] = array(
        'group' => 'access',
        'href' => '/admin/?mode=list&preset=groups',
        'title' => t('Группы'),
        'description' => t('Управление группами пользователей.'),
        );

    if ($user->hasAccess('u', 'file'))
      $icons[] = array(
        'group' => 'content',
        'href' => '/admin/?mode=list&preset=files',
        'title' => t('Файлы'),
        'description' => t('Просмотр, редактирование и добавление файлов.'),
        );

    if (count($user->getAccess('u')) and Node::count(array('deleted' => 1, '-class' => TypeNode::getInternal())))
      $icons[] = array(
        'group' => 'content',
        'href' => '/admin/?mode=list&preset=trash',
        'title' => t('Корзина'),
        'description' => t('Просмотр и восстановление удалённых файлов.'),
        'weight' => 10,
        );

    return $icons;
  }
};

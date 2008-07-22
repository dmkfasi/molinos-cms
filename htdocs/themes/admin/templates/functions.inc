<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

function _admin_check_updates()
{
  $output = '';

  $um = new UpdateManager();
  $ver = $um->getVersionInfo();

  if ($ver['current_build'] < $ver['latest_build']) {
    $output = t("<a href='@link' title='%version.%current &rarr; %version.%latest' id='lnk_update'>Обновление</a>", array(
      '@link' => '/admin/update/',
      '%version' => $ver['release'],
      '%current' => $ver['current_build'],
      '%latest' => $ver['latest_build'],
      ));
  }

  return $output;
}

function _admin_actions()
{
  $output = '';
  $actions = array();

  $map = array(
    '/admin/taxonomy/' => array(
      '../node/create/?BebopNode.class=tag&amp;' => 'Добавить раздел',
      ),
    '/admin/schema/' => array(
      '../node/create/?BebopNode.class=type&amp;' => 'Добавить тип',
      ),
    '/admin/content/' => array(
      'create/?' => 'Создать документ',
      ),
    '/admin/builder/' => array(
      '../node/create/?BebopNode.class=domain&amp;' => 'Добавить страницу',
      'widgets/' => t('Список виджетов'),
      ),
    '/admin/builder/widgets/' => array(
      '/admin/node/create/?BebopNode.class=widget&amp;' => 'Добавить виджет',
      '../' => t('Список доменов и страниц'),
      ),
    '/admin/users/' => array(
      '../node/create/?BebopNode.class=user&amp;' => 'Добавить пользователя',
      'groups/' => 'Список групп',
      ),
    '/admin/users/groups/' => array(
      '../../node/create/?BebopNode.class=group&amp;' => 'Добавить группу',
      '../' => 'Список пользователей',
      ),
    '/admin/files/' => array(
      '../node/create/?BebopNode.class=file&amp;' => 'Добавить файл',
      ),
    '/admin/logs/' => array(
      '/admin/logs/?BebopLogs.op=search&amp;BebopLogs.mode=download&amp;' => 'Скачать (Microsoft Excel)',
      ),
    '/admin/subscription/' => array(
      '/admin/subscription/?BebopSubscription.mode=download&amp;BebopSubscription.format=xml&amp;' => 'Скачать отчёт (XML)',
      ),
    );

  $request_uri = preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI']);

  foreach ($map as $k => $links) {
    if ($request_uri == $k) {
      $xml = false;

      foreach ($_GET as $k1 => $v1)
        if (strstr($k1, 'BebopContentList_') !== false) {
          $xml = true;
          break;
        }

      // Добавляем ссылки на xml.
      if ($k == '/admin/content/' and $xml) {
        $url = bebop_split_url();
        $url['args']['BebopContentList']['mode'] = 'download';
        $url['args']['BebopContentList']['format'] = 'xml';
        $links[bebop_combine_url($url)] = 'Скачать отчёт (XML)';
      }

      foreach ($links as $k => $v) {
        if (substr($k, -1) == '?' or substr($k, -5) == '&amp;')
          $destination = 'destination='. urlencode($_SERVER['REQUEST_URI']);
        else
          $destination = '';

        if ($k == 'create/?' and !empty($_GET['BebopContentList_classes']) and count($_GET['BebopContentList_classes']) == 1) {
          $k .= 'BebopContentCreate.type='. $_GET['BebopContentList_classes'][0] .'&amp;';
        }

        $actions[] = "<li><a href='{$k}{$destination}'>{$v}</a></li>";
      }

      break;
    }
  }

  // Редактирование документа.
  if (preg_match('@^/admin/node/([0-9]+)/edit/.*@', $_SERVER['REQUEST_URI'], $m)) {
    $nid = $m[1];

    // Ссылки на ревизии.
    $revisions = PDO_Singleton::getInstance()->getResults("SELECT `r`.`rid`, `n`.`id`, `r`.`uid`, `u`.`login`, `r`.`created` FROM `node__rev` `r` LEFT JOIN `node` `n` ON `n`.`rid` = `r`.`rid` LEFT JOIN `node` `un` ON `un`.`id` = `r`.`uid` LEFT JOIN `node_user` `u` ON `u`.`rid` = `un`.`rid` WHERE `r`.`nid` = :nid ORDER BY `r`.`rid` DESC", array('nid' => $nid));

    // Запрошенная ревизия.
    $reqrev = empty($_GET['BebopNode_rev']) ? null : $_GET['BebopNode_rev'];

    if (count($revisions) > 1) {
      $output .= '<h4>Ревизии документа:</h4><ul>';
      $dest = empty($_GET['destination']) ? '' : '&destination='. urlencode($_GET['destination']);

      foreach ($revisions as $rev) {
        $text = "<li><a href='@revlink'>%revdate</a><br />&nbsp;&nbsp;автор: %author</li>";

        // Редактируем конкретную ревизию.
        if (!empty($rev['id']) and $rev['id'] == $reqrev) {
          $text = '<li><strong>%revdate</strong><br />&nbsp;&nbsp;автор: %author</li>';
        }

        // Редактируем дефолтную ревизию.
        elseif (empty($_GET['BebopNode_rev']) and $rev['id'] !== null) {
          $text = '<li><strong>%revdate</strong><br />&nbsp;&nbsp;автор: %author</li>';
        }

        // Редактируемая ревизия.
        elseif (!empty($_GET['BebopNode_rev']) and $_GET['BebopNode_rev'] == $rev['rid'])
          $text = '<li><strong>%revdate</strong><br />&nbsp;&nbsp;автор: %author</li>';

        // Формируем ссылку на ревизию.
        $url = array(
          'path' => "/admin/node/{$nid}/edit/",
          'args' => array(
            'BebopNode' => array(
              'rev' => $rev['rid'],
              ),
            'destination' => empty($_GET['destination']) ? null : $_GET['destination'],
            ),
          );

        $output .= t($text, array(
            '@revlink' => bebop_combine_url($url, false),
            '%revdate' => $rev['created'],
            '%author' => empty($rev['login']) ? t("неизвестен") : $rev['login'],
            ));
      }

      $output .= '<li>'. t("<a href='@doclink'>Подробнее о ревизиях</a>", array('@doclink' => 'http://code.google.com/p/molinos-cms/wiki/Revisions')) .'</li>';
      $output .= '<li>'. t("<a href='@cleanlink'>Очистить архив</a>", array('@cleanlink' => "/admin/node/{$nid}/purge/archive/?destination=". urlencode($_SERVER['REQUEST_URI']))) .'</li>';
      $output .= '<li>'. t("<a href='@cleanlink'>Очистить черновики</a>", array('@cleanlink' => "/admin/node/{$nid}/purge/drafts/?destination=". urlencode($_SERVER['REQUEST_URI']))) .'</li>';
      $output .= '</ul>';
    }
  }

  if (!empty($actions))
    $output = "<h4>Действия</h4><ul>". join('', $actions) ."</ul>" . $output;

  if (!empty($output))
    return "<div class='menu_block'>{$output}</div>";
}
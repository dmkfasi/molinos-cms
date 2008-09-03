<?php
/**
 * Статистика базы данных.
 *
 * @package mod_admin
 * @subpackage Core
 * @author Justin Forest <justin.forest@gmail.com>
 * @copyright 2006-2008 Molinos.RU
 * @license http://www.gnu.org/copyleft/gpl.html GPL
 */

/**
 * Провайдер статистики БД.
 *
 * Выводит статистику БД на главной странице административного интерфейса.
 *
 * @package mod_admin
 * @subpackage Core
 */
class AdminStatus implements iAdminMenu
{
  public static function getMenuIcons()
  {
    $icons = array();

    if (!($p = mcms::user()->password) or $p == md5(''))
      $icons[] = array(
        'group' => 'status',
        'message' => t('Пожалуйста, установите пароль на ваш аккаунт.'),
        );

    if (null !== ($stat = NodeIndexer::stats()))
      $icons[] = array(
        'group' => 'status',
        'message' => t('!count объектов нуждаются в индексации.  '
          .'Они будут проиндексирвоаны при выполнении планировщика, '
          .'или вы можете <a href=\'@url\'>проиндексировать их вручную</a>.  '
          .'Пока индексация не будет завершена, сортировка и выборка '
          .'будут работать некорректно.', array(
            '!count' => $stat['_total'],
            '@url' => 'admin.rpc?action=reindex',
            )),
        );

    if (null !== ($counts = self::getCounts()))
      $icons[] = array(
        'group' => 'status',
        'message' => $counts,
        );

    return $icons;
  }

  private static function getCounts()
  {
    static $parts = null;

    if (null === $parts) {
      $parts = array();

      mcms::db()->log('-- status counter --');

      self::count($parts, 'SELECT COUNT(*) FROM `node`',
        'Объектов: !count', 'admin?mode=list&cgroup=content&columns=name,class,uid,created');

      self::count($parts, 'SELECT COUNT(*) FROM `node` WHERE `deleted` = 1',
        'удалённых: !count', 'admin?mode=list&preset=trash&cgroup=content');

      self::count($parts, 'SELECT COUNT(*) FROM `node` WHERE `published` = 0 AND `deleted` = 0',
        'в модерации: !count', 'admin?mode=drafts&cgroup=content');

      self::count($parts, 'SELECT COUNT(*) FROM `node__rev`',
        'ревизий: !count');

      self::count($parts, 'SELECT COUNT(*) FROM `node__rev` WHERE `rid` NOT IN (SELECT `rid` FROM `node`)', 
        'архивных: !count');

      self::count($parts, 'SELECT COUNT(*) FROM `node__cache`',
        'кэш: !count');

      self::count($parts, 'SELECT COUNT(*) FROM `node__session`',
        'сессий: !count');

      if ('SQLite' == mcms::db()->getDbType())
        $parts[] = t('объём&nbsp;БД:&nbsp;%sizeКБ', array(
          '%size' => number_format(filesize(mcms::db()->getDbName()) / 1024),
          ));

      if ($tmp = mcms::config('runtime_modules')) {
        $parts[] = t('<a href=\'@url\'>модулей</a>:&nbsp;%count', array(
          '%count' => count(explode(',', $tmp)),
          '@url' => 'admin?mode=modules&cgroup=structure',
          ));
      }
    }

    return empty($parts)
      ? null
      : join(', ', $parts) .'.';
  }

  private static function count(array &$parts, $query, $text, $link = null)
  {
    if ($count = mcms::db()->fetch($query)) {
      if (null !== $link)
        $count = l($link, $count);

      $parts[] = t(str_replace(' ', '&nbsp;', $text), array(
        '!count' => $count,
        ));
    }
  }
}
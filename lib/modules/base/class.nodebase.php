<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2 fenc=utf8 enc=utf8:

class NodeBase
{
  protected $data = array();

  // Сюда складываем загруженные ноды.
  static private $cache = array();

  // Проверяет наличие других объектов с таким именем.
  protected function checkUnique($field, $message = null, array $filter = array())
  {
    $filter['class'] = $this->class;
    $filter[$field] = $this->$field;

    if ($this->id)
      $filter['-id'] = $this->id;

    try {
      if (Node::count($filter))
        throw new DuplicateException($message ? $message : t('Такой объект уже существует.'));
    } catch (PDOException $e) {
      throw new ValidationException($field, t('Объект %class требует уникальности по полю %field, однако индекс для этого поля отсутствует.  Необходимо настроить тип документа, включив индексирование этого поля.', array(
        '%class' => $this->class,
        '%field' => $field,
        )));
    }
  }

  public function getRaw()
  {
    return $this->data;
  }

  // Достаёт объект из кэша.
  private static function getCached($id)
  {
    $result = null;

    if (null !== ($mode = mcms::config('cache_documents'))) {
      if ($mode != 'local') {
        $key = 'node:'. $id;
        $result = mcms::cache($key);
      } elseif ($mode == 'local') {
        $result = array_key_exists($id, self::$cache)
          ? self::$cache[$id]
          : null;
      }
    }

    return (is_array($result) or is_object($result)) ? $result : null;
  }

  // Кладёт в кэш.
  private static function setCached($id, $data)
  {
    if (null !== ($mode = mcms::config('cache_documents'))) {
      if ($mode != 'local') {
        $key = 'node:'. $id;
        mcms::cache($key, $data);
      } elseif ($mode == 'local') {
        self::$cache[$id] = $data;
      }
    }
  }

  // Читаем объект.
  public static function load($id, $first = false)
  {
    if (!is_array($id))
      $id = array('id' => $id);

    $data = self::find($id);

    if (empty($data))
      throw new ObjectNotFoundException();

    elseif (count($data) > 1 and !$first)
      throw new InvalidArgumentException("Выборка объекта по условию вернула более одного объекта. Условие: ". var_export($id, true));

    $node = array_shift($data);

    self::setCached($node->id, $node);

    return $node;
  }

  // Поиск документов по критерию.
  public static function find(array $query, $limit = null, $offset = null)
  {
    $sql = null;
    $params = array();
    $fetch_extra = true;
    $fetch_att = !array_key_exists('#files', $query) or !empty($query['#files']);

    // Список запрашиваемых полей.
    $fields = array('`node`.`id`', '`node`.`rid`', '`node`.`code`',
      '`node`.`class`', '`node`.`parent_id`', '`node`.`uid`', '`node`.`created`',
      '`node`.`updated`', '`node`.`lang`', '`node`.`published`', '`node`.`deleted`',
      '`node`.`left`', '`node`.`right`', '`node__rev`.`name`', '`node__rev`.`data`');

    $qb = new NodeQueryBuilder($query);
    $qb->getSelectQuery($sql, $params, $fields);

    // Листалка.
    if (!empty($limit) and !empty($offset))
      $sql .= sprintf(" LIMIT %d, %d", $offset, $limit);
    elseif (!empty($limit))
      $sql .= sprintf(" LIMIT %d", $limit);

    if (!empty($query['class']))
      $sql .= ' -- Node::find(type='. join(',', (array)$query['class']) .')';
    elseif (!empty($query['id']) and is_numeric($query['id']))
      $sql .= ' -- Node::find('. $query['id'] .')';
    else
      $sql .= ' -- Node::find()';

    mcms::db()->log("--- Finding nodes ---");

    return self::dbRead($sql, $params, empty($query['#recurse']) ? 0 : intval($query['#recurse']));
  }

  // Возвращает количество документов, удовлетворяющих условию.
  public static function count(array $query)
  {
    $sql = null;
    $params = array();

    $qb = new NodeQueryBuilder($query);
    $qb->getCountQuery($sql, $params);

    if (!empty($query['#debug']))
      mcms::debug($query, $sql, $params);

    return mcms::db()->getResult($sql . " -- Node::count()", $params);
  }

  // Сохранение объекта.
  public function save()
  {
    $isnew = !isset($this->id);

    $this->dbWrite();

    mcms::flush();

    mcms::invoke('iNodeHook', 'hookNodeUpdate', array($this, $isnew ? 'create' : 'update'));

    $this->purgeRevisions();
  }

  private function purgeRevisions()
  {
    if (0 !== ($limit = intval(mcms::modconf('node', 'archive_limit')))) {
      $victim = mcms::db()->getResult("SELECT `rid` FROM `node__rev` WHERE `rid` < :current ORDER BY `rid` DESC LIMIT {$limit}, 1", array(
        ':current' => $this->rid,
        ));
      mcms::db()->exec("DELETE FROM `node__rev` WHERE `nid` = :nid AND `rid` <= :rid", array(
        ':nid' => $this->id,
        ':rid' => $victim,
        ));
    }
  }

  public function __set($key, $val)
  {
    if (in_array($key, array('class', 'left', 'right', 'published')))
      throw new InvalidArgumentException("node.{$key} is private");

    /*
    if ('uid' == $key and intval($val) != intval($this->uid)) {
      if (!empty($this->data['uid']) and $this->data['uid'] != mcms::user()->id) {
        throw new InvalidArgumentException(t('Нельзя изменить автора чужого документа.'));
      }
    }
    */

    if ($key == 'parent_id' and !empty($this->data['id']))
      throw new InvalidArgumentException("node.{$key} is private");

    if ($key == 'id') {
      $this->data['id'] = null;
      $this->data['rid'] = null;
      $this->data['code'] = null;
      return;
    }

    /*
    if ($key != 'parent_id' and $key != 'deleted' and $key != 'uid') {
      $schema = TypeNode::getSchema($this->class);

      if (empty($schema['fields'][$key]) and !bebop_skip_checks())
        throw new InvalidArgumentException("there is no {$key} property in {$this->data['class']}");
    }
    */

    $this->data[$key] = $val;
  }

  public function __get($key)
  {
    if (!is_array($this->data))
      return null;
    return array_key_exists($key, $this->data) ? $this->data[$key] : null;
  }

  public function __isset($key)
  {
    return !empty($this->data[$key]);
  }

  // Публикация ноды.  Логика:
  // 1. Если у пользователя нет прав на публикацию -- отправляет запрос модератору.
  // 2. Если указана конкретная ревизия -- она публикуется, в противном случае
  //    публикуется последняя существующая ревизия документа.
  //
  // Возвращает true, если нода успешно опубликована, false если нет.
  public function publish($rev = null)
  {
    // Документ уже опубликован.
    if ($this->published and $this->rid == $rev)
      return;

    $this->data['published'] = true;

    if (isset($this->id)) {
      mcms::db()->exec("UPDATE `node` SET `published` = 1, `rid` = :rid WHERE `id` = :id", array(':rid' => $rev ? $rev : $this->rid, ':id' => $this->id));

      // Даём другим модулям возможность обработать событие (например, mod_moderator).
      mcms::invoke('iNodeHook', 'hookNodeUpdate', array($this, 'publish'));

      mcms::flush();
    }

    return true;
  }

  public function unpublish()
  {
    if (!$this->published)
      return;

    // Скрываем документ.
    mcms::db()->exec("UPDATE `node` SET `published` = 0 WHERE `id` = :id", array(':id' => $this->id));
    $this->data['published'] = false;

    $user = mcms::user();

    // Даём другим модулям возможность обработать событие (например, mod_moderator).
    mcms::invoke('iNodeHook', 'hookNodeUpdate', array($this, 'unpublish'));

    mcms::flush();

    return true;
  }

  public function duplicate($parent = null)
  {
    if (null !== ($id = $this->id)) {
      $this->id = null;
      $this->data['published'] = false;
      $this->data['deleted'] = false;
      $this->data['code'] = null;

      // Даём возможность прикрепить клон к новому родителю.
      if (null !== $parent)
        $this->data['parent_id'] = $parent;

      $this->save();

      $pdo = mcms::db();
      $params = array(':new' => $this->id, ':old' => $id);

      // Копируем права.
      $pdo->exec("REPLACE INTO `node__access` (`nid`, `uid`, `c`, `r`, `u`, `d`)"
        ."SELECT :new, `uid`, `c`, `r`, `u`, `d` FROM `node__access` WHERE `nid` = :old", $params);

      // Копируем связи с другими объектами.
      $pdo->exec("REPLACE INTO `node__rel` (`tid`, `nid`, `key`) "
        ."SELECT :new, `nid`, `key` FROM `node__rel` WHERE `tid` = :old", $params);
      $pdo->exec("REPLACE INTO `node__rel` (`tid`, `nid`, `key`) "
        ."SELECT `tid`, :new, `key` FROM `node__rel` WHERE `nid` = :old", $params);

      mcms::flush();
    }
  }

  public function erase()
  {
    if (!$this->deleted)
      throw new ForbiddenException(t('Невозможно окончательно удалить объект, который не был помещён в корзину.'));

    if (!$this->checkPermission('d'))
      throw new ForbiddenException(t('У вас нет прав на удаление этого объекта.'));

    $pdo = mcms::db();

    $meta = $pdo->getResult("SELECT `left`, `right`, `right` - `left` + 1 AS `width` FROM `node` WHERE `id` = :id", array(':id' => $this->id));
    $nids = $pdo->getResultsKV("id", "class", "SELECT `id`, `class` FROM `node` WHERE `left` >= :left AND `right` <= :right", array(':left' => $meta['left'], ':right' => $meta['right']));

    // Вызываем дополнительную обработку.
    try {
      mcms::invoke('iNodeHook', 'hookNodeDelete', array(Node::load(array('id' => $nid, 'deleted' => array(0, 1))), 'erase'));
    } catch (Exception $e) {
    }

    $pdo->exec("DELETE FROM `node` WHERE `left` BETWEEN :left AND :right", array(':left' => $meta['left'], ':right' => $meta['right']));

    $order = $pdo->hasOrderedUpdates() ? ' ORDER BY `right` ASC' : '';
    $pdo->exec("UPDATE `node` SET `right` = `right` - :width WHERE `right` > :right". $order, $args = array(':width' => $meta['width'], ':right' => $meta['right']));

    $order = $pdo->hasOrderedUpdates() ? ' ORDER BY `left` ASC' : '';
    $pdo->exec("UPDATE `node` SET `left` = `left` - :width WHERE `left` > :right". $order, $args);

    mcms::flush();
  }

  // Создаём новый объект.
  public static function create($class, array $data = null)
  {
    if (!is_string($class))
      throw new InvalidArgumentException(t('Тип создаваемого объекта должен быть строкой, а не «%type».', array('%type' => gettype($class))));

    if (!mcms::class_exists($host = ucfirst(strtolower($class)) .'Node'))
      $host = 'Node';

    if (!is_array($data))
      $data = array();

    $data['class'] = $class;
    if (!array_key_exists('id', $data))
      $data['id'] = null;
    if (!array_key_exists('parent_id', $data))
      $data['parent_id'] = null;
    if (!array_key_exists('lang', $data))
      $data['lang'] = 'ru';

    return new $host($data);
  }

  // Удаление ноды.
  public function delete()
  {
    if ($this->id === null)
      throw new InvalidArgumentException(t("Попытка удаления несохранённой ноды."));

    if (!$this->checkPermission('d'))
      throw new ForbiddenException(t("У вас нет прав на удаление объекта &laquo;<a href='@link'>%name</a>&raquo;.", array(
        '@link' => "/admin/node/{$this->id}/edit/?destination=". urlencode(empty($_GET['destination']) ? '/admin/content/' : $_GET['destination']),
        '%name' => $this->name,
        )));

    $this->data['deleted'] = true;
    $pdo = mcms::db()->exec("UPDATE `node` SET `deleted` = 1 WHERE id = :nid", array('nid' => $this->id));

    mcms::invoke('iNodeHook', 'hookNodeUpdate', array($this, 'delete'));

    mcms::flush();
  }

  public function undelete()
  {
    if (empty($this->deleted))
      throw new InvalidArgumentException(t("Попытка восстановления неудалённой ноды."));

    if ($this->id === null)
      throw new InvalidArgumentException(t("Попытка удаления несохранённой ноды."));

    if (!$this->checkPermission('d'))
      throw new ForbiddenException(t("У вас нет прав на удаление объекта &laquo;%name&raquo;.", array('%name' => $this->name)));

    $this->deleted = false;
    $this->save();

    mcms::invoke('iNodeHook', 'hookNodeUpdate', array($this, 'restore'));

    mcms::flush();
  }

  // Загружает дерево дочерних объектов, формируя вложенные массивы children.
  public function loadChildren($class = null)
  {
    if (null === $class)
      $class = $this->class;

    if ((empty($this->left) or empty($this->right)) and !empty($this->id)) {
      $tmp = mcms::db()->getResults("SELECT `left`, `right` FROM `node` WHERE `id` = :id", array(':id' => $this->id));

      if (!empty($tmp[0])) {
        $this->data['left'] = $tmp[0]['left'];
        $this->data['right'] = $tmp[0]['right'];
      }
    }

    if (empty($this->left) or empty($this->right))
      throw new RuntimeException(t('Невозможно получить дочерние объекты: свойства left/right для объекта %id не заполнены.', array('%id' => $this->id)));

    // Загружаем детей в плоский список.
    $children = Node::find($filter = array(
      'class' => $class,
      'left' => array('>'. $this->left, '<'. $this->right),
      '#sort' => array(
        'left' => 'asc',
        ),
      ));

    // Превращаем плоский список в дерево.
    $this->data['children'] = $this->make_tree($children);
  }

  // Формирует из плоского списка элементов дерево.
  // Список обрабатывается в один проход.
  private function make_tree(array $nodes)
  {
    // Здесь будем хранить ссылки на все элементы списка.
    $map = array($this->id => $this);

    // Перебираем все данные.
    foreach ($nodes as $k => $node) {
      // Родитель есть, добавляем к нему.
      if (array_key_exists($node->data['parent_id'], $map))
          $map[$node->data['parent_id']]->data['children'][] = &$nodes[$k];

      // Добавляем все элементы в список.
      $map[$node->data['id']] = &$nodes[$k];
    }

    return $map[$this->id]->children;
  }

  // Возвращает список дочерних объектов, опционально фильтруя его.
  public function getChildren($mode, array $options = array())
  {
    if ($mode != 'nested' and $mode != 'flat' and $mode != 'select')
      throw new InvalidArgumentException(t('Неверный параметр $mode для Node::getChildren(), допустимые варианты: nested, flat.'));

    if (!array_key_exists('children', $this->data))
      $this->loadChildren();

    $result = $this;

    self::filterChildren($result,
      (array_key_exists('enabled', $options) and is_array($options['enabled'])) ? $options['enabled'] : null,
      (array_key_exists('search', $options) and is_string($options['search'])) ? $options['search'] : null);

    if ($mode == 'flat') {
      $result = self::makeFlat($result);
    } elseif ($mode == 'select') {
      $data = array();

      foreach (self::makeFlat($result) as $k => $v)
        $data[$v['id']] = str_repeat('&nbsp;', 2 * $v['depth']) . $v['name'];

      $result = $data;
    } elseif ($mode == 'nested') {
      $result = self::makeNested($result);
    }

    return $result;
  }

  // Фильтрует список дочерних объектов, оставляя только необходимые.
  // Попутно удаляет неопубликованные и удалённые объекты.
  private static function filterChildren(Node &$tree, array $enabled = null, $search = null)
  {
    if (array_key_exists('children', $tree->data) and is_array($tree->data['children'])) {
      foreach ($tree->data['children'] as $k => $v) {
        if (!($check = self::filterChildren($tree->data['children'][$k], $enabled, $search))) {
          unset($tree->data['children'][$k]);
        }
      }
    }

    $ok = true;

    // Удалённые объекты всегда удаляем.
    if (!empty($tree->data['deleted']))
      $ok = false;

    if ($enabled !== null and !in_array($tree->data['id'], $enabled))
      $ok = false;

    if ($search !== null and mb_stristr($tree->data['name'], $search) !== false)
      $ok = false;

    $tree->data['#disabled'] = !$ok;

    return $ok or !empty($tree->data['children']);
  }

  // Превращает дерево в плоский список.
  private static function makeFlat(Node $root, $depth = 0)
  {
    $output = array();

    $em = array(
      '#disabled' => !empty($root->data['#disabled']),
      'depth' => $depth,
      );

    foreach ($root->getRaw() as $k => $v)
      if (!is_array($v))
        $em[$k] = $v;

    $output[] = $em;

    if (!empty($root->data['children'])) {
      foreach ($root->data['children'] as $branch) {
        foreach (self::makeFlat($branch, $depth + 1) as $em)
          $output[] = $em;
      }
    }

    return $output;
  }

  // Превращает дерево объектов во вложенны массив.
  private static function makeNested(Node $root)
  {
    $output = $root->getRaw();

    if (!empty($output['children'])) {
      foreach ($output['children'] as $k => $v) {
        $output['children'][$k] = self::makeNested($v);
      }
    }

    return $output;
  }

  // Возвращает родителей текущего объекта, опционально исключая текущий объект.
  public function getParents($current = true)
  {
    if (null === ($tmp = $this->id))
      $tmp = $this->parent_id;

    if (null === $tmp)
      return null;

    $sql = "SELECT `parent`.`id` as `id`, `parent`.`parent_id` as `parent_id`, "
      ."`parent`.`code` as `code`, `parent`.`class` as `class`, `rev`.`name` as `name`, "
      ."`rev`.`data` as `data` "
      ."FROM `node` AS `self`, `node` AS `parent`, `node__rev` AS `rev` "
      ."WHERE `self`.`left` BETWEEN `parent`.`left` AND `parent`.`right` AND `self`.`id` = {$tmp} AND `rev`.`rid` = `parent`.`rid` "
      ."ORDER BY `parent`.`left` -- NodeBase::getParents({$tmp})";

    $nodes = self::dbRead($sql);

    if (!$current and array_key_exists($this->id, $nodes))
      unset($nodes[$this->id]);

    return $data;
  }

  // Применяет к объекту шаблон.  Формат имени шаблона: префикс.имя.tpl, префикс можно
  // передать извне (по умолчанию используется "doc").  Если массив с данными пуст,
  // будут использоваться данные текущего объекта.
  public function render($prefix = null, $theme = null, array $data = null)
  {
    if (null === $data)
      $data = $this->data;
    if (null === $prefix)
      $prefix = 'doc';

    return bebop_render_object($prefix, $theme, $data);
  }

  // РАБОТА СО СВЯЗЯМИ.
  // Документация: http://code.google.com/p/molinos-cms/wiki/Node_links

  public function linkListParents($class = null, $idsonly = false)
  {
    $params = array(':nid' => $this->id);
    $sql = "SELECT `r`.`tid` as `tid`, `r`.`key` as `key` FROM `node__rel` `r` INNER JOIN `node` `n` ON `n`.`id` = `r`.`tid` WHERE `n`.`deleted` = 0 AND `r`.`nid` = :nid";

    if (null !== $class) {
      $sql .= " AND `n`.`class` = :class";
      $params[':class'] = $class;
    }

    $sql .= sprintf(" ORDER BY `r`.`order` ASC -- linkListParents(%u, %s, %d)", $this->id, $class ? $class : 'NULL', $idsonly);

    $pdo = mcms::db();

    if ($idsonly)
      return $pdo->getResultsV("tid", $sql, $params);
    else
      return $pdo->getResults($sql, $params);
  }

  public function linkListChildren($class = null, $idsonly = false)
  {
    $params = array(':tid' => $this->id);
    $sql = "SELECT `r`.`nid` as `nid`, `r`.`key` as `key` FROM `node__rel` `r` INNER JOIN `node` `n` ON `n`.`id` = `r`.`nid` WHERE `n`.`deleted` = 0 AND `r`.`tid` = :tid";

    if (null !== $class) {
      $sql .= " AND `n`.`class` = :class";
      $params[':class'] = $class;
    }

    $sql .= sprintf(" ORDER BY `r`.`order` ASC -- linkListChildren(%u, %s, %d)", $this->id, $class ? $class : 'NULL', $idsonly);

    $pdo = mcms::db();

    if ($idsonly)
      return $pdo->getResultsV("nid", $sql, $params);
    else
      return $pdo->getResults($sql, $params);
  }

  public function linkAddParent($parent_id, $key = null)
  {
    if (null === $this->id)
      $this->save();

    $pdo = mcms::db();

    $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $parent_id, ':nid' => $this->id));

    if (null !== $key)
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `key` = :key", array(':tid' => $parent_id, ':key' => $key));

    $pdo->exec("INSERT INTO `node__rel` (`tid`, `nid`, `key`, `order`) VALUES (:tid, :nid, :key, :order)", array(
      ':tid' => $parent_id,
      ':nid' => $this->id,
      ':key' => $key,
      ':order' => self::getNextOrder($parent_id),
      ));

    mcms::flush();
  }

  public function linkAddChild($child_id, $key = null)
  {
    $pdo = mcms::db();

    if (null !== $key)
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `key` = :key", array(':tid' => $this->id, ':key' => $key));
    elseif (false) // FIXME: wtf?  где это нужно?
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `key` IS NULL", array(':tid' => $this->id));

    $pdo->exec("INSERT INTO `node__rel` (`tid`, `nid`, `key`, `order`) VALUES (:tid, :nid, :key, :order)", array(
      ':tid' => $this->id,
      ':nid' => $child_id,
      ':key' => $key,
      ':order' => self::getNextOrder($this->id),
      ));

    mcms::flush();
  }

  public function linkRemoveParent($parent_id)
  {
    $pdo = mcms::db();

    $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $parent_id, ':nid' => $this->id));

    mcms::flush();
  }

  public function linkRemoveChild($child_id = null, $key = null)
  {
    $pdo = mcms::db();

    if (null !== $child_id)
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $this->id, ':nid' => $child_id));
    elseif (null !== $key)
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `key` = :key", array(':tid' => $this->id, ':key' => $key));

    mcms::flush();
  }

  public function linkSetParents(array $list, $class = null, array $available = null)
  {
    $pdo = mcms::db();
    $xtra = '';

    if (null !== $available)
      $xtra = ' AND `tid` IN ('. join(', ', $available) .')';

    if (null === $class)
      $pdo->exec("DELETE FROM `node__rel` WHERE `nid` = :nid{$xtra} -- Node::linkSetParents({$this->id})",
        array(':nid' => $this->id));
    else
      $pdo->exec("DELETE FROM `node__rel` WHERE `nid` = :nid AND `tid` IN (SELECT `id` FROM `node` WHERE `class` = :class){$xtra} -- Node::linkSetParents({$this->id}, {$class})",
        array(':nid' => $this->id, ':class' => $class));

    foreach ($list as $item) {
      if (is_array($item))
        $params = array(
          ':tid' => $item['id'],
          ':nid' => $this->id,
          ':key' => empty($item['key']) ? null : $item['key'],
          ':order' => self::getNextOrder($item['id']),
          );
      else
        $params = array(
          ':tid' => $item,
          ':nid' => $this->id,
          ':key' => null,
          ':order' => self::getNextOrder($item),
          );

      $pdo->exec("INSERT INTO `node__rel` (`tid`, `nid`, `key`, `order`) VALUES (:tid, :nid, :key, :order) "
        ."-- Node::linkSetParents({$this->id})", $params);
    }

    mcms::flush();
  }

  public function linkSetChildren(array $list, $class = null)
  {
    $pdo = mcms::db();

    if (null === $class)
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid -- Node::linkSetChildren({$this->id})",
        array(':tid' => $this->id));
    else
      $pdo->exec("DELETE FROM `node__rel` WHERE `tid` = :tid AND `nid` IN (SELECT `id` FROM `node` WHERE `class` = :class) -- Node::linkSetChildren({$this->id}, {$class})",
        array(':tid' => $this->id, ':class' => $class));

    $order = self::getNextOrder($this->id);

    foreach ($list as $item) {
      if (is_array($item))
        $params = array(
          ':tid' => $this->id,
          ':nid' => $item['id'],
          ':key' => empty($item['key']) ? null : $item['key'],
          ':order' => $order++,
          );
      else
        $params = array(
          ':tid' => $this->id,
          ':nid' => $item,
          ':key' => null,
          ':order' => $order++,
          );

      $pdo->exec("INSERT INTO `node__rel` (`tid`, `nid`, `key`, `order`) VALUES (:tid, :nid, :key, :order) "
        ."-- Node::linkSetChildren({$this->id})", $params);
    }

    mcms::flush();
  }

  private static function getNextOrder($tid)
  {
    return mcms::db()->getResult("SELECT MAX(`order`) FROM `node__rel` WHERE `tid` = :tid", array(':tid' => $tid)) + 1;
  }


  public function moveBefore($tid)
  {
    // Прописываем дефолтные порядки.
    $this->orderFix();

    $pdo = mcms::db();
    $he = $pdo->getResult("SELECT `nid`, `tid`, `order` FROM `node__rel` WHERE `nid` >= :nid", array(':nid' => $tid));
    $me = $pdo->getResults("SELECT `nid`, `tid`, `order` FROM `node__rel` WHERE `tid` = :tid AND `order` >= :order ORDER BY `order` DESC", array(':tid' => $he['tid'], ':order' => $he['order']));

    $orderTo = $he['order'];
    foreach ($me as $k => $node) {
      $pdo->exec('UPDATE `node__rel` SET `order` = :order WHERE `nid` = :nid', array(':nid' => $node['nid'], ':order' => $node['order'] + 1));
    }
    $pdo->exec('UPDATE `node__rel` SET `order` = :order WHERE `nid` = :nid', array(':nid' => $me[0]['nid'], ':order' => $orderTo));

    mcms::flush();
  }

  public function moveAfter($tid)
  {
    // Прописываем дефолтные порядки.
    $this->orderFix();

    $pdo = mcms::db();
    $he = $pdo->getResult("SELECT `nid`, `tid`, `order` FROM `node__rel` WHERE `nid` >= :nid", array(':nid' => $tid));
    $me = $pdo->getResults("SELECT `nid`, `tid`, `order` FROM `node__rel` WHERE `tid` = :tid AND `order` <= :order ORDER BY `order` ASC", array(':tid' => $he['tid'], ':order' => $he['order']));

    $orderTo = $he['order'];
    foreach ($me as $k => $node) {
      $pdo->exec('UPDATE `node__rel` SET `order` = :order WHERE `nid` = :nid', array(':nid' => $node['nid'], ':order' => $node['order'] - 1));
    }
    $pdo->exec('UPDATE `node__rel` SET `order` = :order WHERE `nid` = :nid', array(':nid' => $me[0]['nid'], ':order' => $orderTo));

    mcms::flush();
  }

  // РАБОТА С ПРАВАМИ.
  // Документация: http://code.google.com/p/molinos-cms/wiki/Permissions

  public function getAccess()
  {
    static $default = null;

    $pdo = mcms::db();

    // Формируем список групп.
    if ($default === null)
      $default = $pdo->getResultsK("name", "SELECT `r`.`name` as `name` FROM `node` `n` "
        ."INNER JOIN `node__rev` `r` ON `r`.`rid` = `n`.`rid` "
        ."WHERE `n`.`class` = 'group' AND `n`.`deleted` = 0 ORDER BY `r`.`name`");

    $data = $default;

    $sql = "SELECT `r`.`name` as `name`, `a`.`c` as `c`, "
      ."`a`.`r` as `r`, `a`.`u` as `u`, `a`.`d` as `d` FROM `node__access` `a` "
      ."INNER JOIN `node` `n` ON `n`.`id` = `a`.`uid` "
      ."INNER JOIN `node__rev` `r` ON `r`.`rid` = `n`.`rid` "
      ."WHERE `a`.`nid` = :nid AND `n`.`class` = 'group' AND `n`.`deleted` = 0";

    // die($sql);

    // Формируем таблицу с правами.
    foreach ($pdo->getResultsK("name", $sql, array(':nid' => $this->id)) as $group => $perms) {
      $data[$group]['c'] = $perms['c'];
      $data[$group]['r'] = $perms['r'];
      $data[$group]['u'] = $perms['u'];
      $data[$group]['d'] = $perms['d'];
    }

    return $data;
  }

  public function setAccess(array $perms, $reset = true)
  {
    $pdo = mcms::db();

    if ($reset)
      $pdo->exec("DELETE FROM `node__access` WHERE `nid` = :nid", array(':nid' => $this->id));

    foreach ($perms as $uid => $values) {
      $args = array(
        ':nid' => $this->id,
        ':c' => in_array('c', $values) ? 1 : 0,
        ':r' => in_array('r', $values) ? 1 : 0,
        ':u' => in_array('u', $values) ? 1 : 0,
        ':d' => in_array('d', $values) ? 1 : 0,
        );

      if (is_numeric($uid)) {
        $args[':uid'] = $uid;
        $pdo->exec($sql = "REPLACE INTO `node__access` (`nid`, `uid`, `c`, `r`, `u`, `d`) VALUES (:nid, :uid, :c, :r, :u, :d)", $args);
      } else {
        $args[':name'] = $uid;
        $pdo->exec($sql = "REPLACE INTO `node__access` (`nid`, `uid`, `c`, `r`, `u`, `d`) "
          ."SELECT :nid, `n`.`id` as `id`, :c, :r, :u, :d "
          ."FROM `node` `n` "
          ."INNER JOIN `node__rev` `v` ON `v`.`rid` = `n`.`rid` "
          ."WHERE `n`.`class` = 'group'  AND `n`.`deleted` = 0 AND `v`.`name` = :name", $args);
      }
    }

    mcms::flush();
  }

  public function checkPermission($perm)
  {
    if (empty($_SERVER['HTTP_HOST']))
      return true;

    return mcms::user()->hasAccess($perm, $this->class);
  }

  // Если есть ноды с пустым `order`, надо бы их починить

  private function orderFix()
  {
    $pdo = mcms::db();
    $pdo->exec("UPDATE `node__rel` SET `order` = `nid` WHERE `order` IS NULL AND `tid` = :tid AND `key` IS NULL", array(':tid' => $parent));
  }

  // ИЗМЕНЕНИЕ ПОРЯДКА ДОКУМЕНТОВ.

  public function orderUp($parent = null)
  {
    if (null === $parent) {
      $tmp = new NodeExtras();
      $tmp->moveUp($this->id);
    } elseif (null !== $this->id) {
      $pdo = mcms::db();

      // Прописываем дефолтные порядки.
      $this->orderFix();

      // Определяем ближайшее верхнее значение.
      $my = $pdo->getResult("SELECT `order` FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $parent, ':nid' => $this->id));
      $order = $pdo->getResult("SELECT MAX(`order`) FROM `node__rel` WHERE `tid` = :tid AND `order` < :order", array(':tid' => $parent, ':order' => $my));

      // Двигать некуда.
      if (null === $order)
        return false;

      // Сдвигаем всё вниз, под себя.
      $pdo->exec("UPDATE `node__rel` SET `order` = `order` + :delta WHERE `order` >= :order AND `tid` = :tid AND `nid` <> :nid ORDER BY `order` DESC",
        array(':tid' => $parent, ':nid' => $this->id, ':order' => $order, ':delta' => $my - $order + 1));

      // Теперь сдвигаем всё наверх, на прежнее место, чтобы не было дырок.
      $pdo->exec("UPDATE `node__rel` SET `order` = `order` - :delta WHERE `order` >= :order AND `tid` = :tid ORDER BY `order` ASC",
        array(':tid' => $parent, ':order' => $my, ':delta' => $my - $order));
    }

    mcms::flush();

    return true;
  }

  public function orderDown($parent = null)
  {
    if (null === $parent) {
      $tmp = new NodeExtras();
      $tmp->moveDown($this->id);
    } elseif (null !== $this->id) {
      $pdo = mcms::db();

      // Прописываем дефолтные порядки.
      $pdo->exec("UPDATE `node__rel` SET `order` = `nid` WHERE `order` IS NULL AND `tid` = :tid AND `key` IS NULL", array(':tid' => $parent));

      // Определяем ближайшее нижнее значение.
      $my = $pdo->getResult("SELECT `order` FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $parent, ':nid' => $this->id));
      $next = $pdo->getResult("SELECT MIN(`order`) FROM `node__rel` WHERE `tid` = :tid AND `order` > :order", array(':tid' => $parent, ':order' => $my));

      if (null === $next)
        return false;

      // Сдвигаем всё вниз.
      $pdo->exec("UPDATE `node__rel` SET `order` = `order` + :delta WHERE `tid` = :tid AND (`order` > :order OR `nid` = :nid) ORDER BY `order` DESC",
        array(':tid' => $parent, ':nid' => $this->id, ':delta' => $next - $my + 1, ':order' => $next));
    }

    mcms::flush();

    return true;
  }

  // ОПРЕДЕЛЕНИЕ СОСЕДЕЙ.

  public function getNeighbors($parent, array $classes = null)
  {
    $pdo = mcms::db();

    if (null === ($order = $pdo->getResult("SELECT `order` FROM `node__rel` WHERE `tid` = :tid AND `nid` = :nid", array(':tid' => $parent, ':nid' => $this->id))))
      return null;

    if (null === $classes)
      $filter = '';
    else
      $filter = " AND `n`.`class` IN ('". join("', '", $classes) ."')";

    $left = $pdo->getResult($sql1 = "SELECT `n`.`id` as `id` FROM `node__rel` `r` INNER JOIN `node` `n` ON `n`.`id` = `r`.`nid` "
      ."WHERE `tid` = :tid{$filter} AND `n`.`deleted` = 0 AND `n`.`published` = 1 "
      ."AND `n`.`class` = :class "
      ."AND `r`.`order` < :order ORDER BY `r`.`order` DESC LIMIT 1",
      array(':tid' => $parent, ':order' => $order, ':class' => $this->class));

    $right = $pdo->getResult($sql2 = "SELECT `n`.`id` as `id` FROM `node__rel` `r` INNER JOIN `node` `n` ON `n`.`id` = `r`.`nid` "
      ."WHERE `tid` = :tid{$filter} AND `n`.`deleted` = 0 AND `n`.`published` = 1 "
      ."AND `n`.`class` = :class "
      ."AND `r`.`order` > :order ORDER BY `r`.`order` ASC LIMIT 1",
      array(':tid' => $parent, ':order' => $order, ':class' => $this->class));

    return array(
      'left' => $left === null ? null : Node::load($left),
      'right' => $right === null ? null : Node::load($right),
      );
  }

  // РАБОТА С ФОРМАМИ.
  // Документация: http://code.google.com/p/molinos-cms/wiki/Forms

  public function formGet($simple = false)
  {
    if (null !== $this->id and !$this->checkPermission('u')) {
      if (mcms::user()->id != $this->id and !bebop_is_debugger())
        throw new ForbiddenException(t('У вас недостаточно прав для редактирования этого документа.'));
    } elseif (null === $this->id and !$this->checkPermission('c')) {
      throw new ForbiddenException(t('У вас недостаточно прав для создания такого документа.'));
    }

    $tabs = array();
    $user = mcms::user();

    // Формируем вкладку с содержимым документа.
    $tabs['content'] = new FieldSetControl(array(
      'name' => 'content',
      'label' => t('Основные свойства'),
      'value' => 'tab_general',
      ));

    $schema = TypeNode::getSchema($this->class);

    if (!$simple and (null !== ($intro = $this->formGetIntro())))
      $tabs['content']->addControl($intro);

    if (array_key_exists('fields', $schema) and is_array($schema['fields'])) {
      foreach ($schema['fields'] as $k => $v) {
        if ($k == 'fk' and $simple)
          continue;

        if (mcms_ctlname($v['type']) != 'ArrayControl') {
          if (mcms_ctlname($v['type']) == 'AttachmentControl') {
            $v['value'] = 'node_content_files['. $k .']';
            $v['medium'] = true;
          } else {
            $v['value'] = 'node_content_'. $k;
          }

          if ($k == 'title')
            $v['class'] = 'form-title';
          elseif ($k == 'name' and !array_key_exists('title', $schema['fields']))
            $v['class'] = 'form-title';

          $v['wrapper_id'] = "control-node-{$k}-wrapper";

          $tabs['content']->addControl(Control::make($v));
        }
      }
    }

    if (!empty($schema['hasfiles']))
      $tabs['content']->addControl(new SetControl(array(
        'value' => 'node_ftp_files',
        'label' => t('Прикрепить файлы с FTP'),
        'options' => FileNode::listFilesOnFTP(),
        )));

    if (!$simple and empty($schema['notags']) and null !== ($tab = $this->formGetSections($schema)))
      $tabs['sections'] = $tab;

    if (!$simple and (null !== ($tab = $this->formGetFilesTab())))
      $tabs['files'] = $tab;

    if ($simple) {
      if (null === $this->id)
        $title = trim($schema['title']);
      else
        $title = $this->name;
    } elseif (!empty($schema['isdictionary']) and empty($this->id)) {
      $title = t('Добавление в справочник «%name»', array('%name' => mb_strtolower($schema['title'])));
    } else {
      if (null === $this->id)
        $title = t('Создание объекта типа "%type"', array('%type' => trim($schema['title'])));
      else
        $title = t('%name (редактирование)', array('%name' => empty($this->name) ? t('Безымянный документ') : trim($this->name)));
    }

    // Формируем окончательную форму.
    $form = new Form(array(
      'title' => $title,
      ));
    $form->addControl(new HiddenControl(array('value' => 'node_content_id')));

    if (null === $this->id) {
      $form->addControl(new HiddenControl(array('value' => 'node_content_class')));
      $form->addControl(new HiddenControl(array('value' => 'node_content_parent_id')));
    }

    if (!isset($this->id) and !empty($schema['isdictionary']))
      $form->addControl(new BoolControl(array(
        'value' => 'nodeapi_return',
        'default' => 1,
        'label' => t('Добавить ещё запись'),
        )));

    foreach ($tabs as $tab)
      $form->addControl($tab);

    $form->addControl(new SubmitControl(array(
      'text' => 'Сохранить',
      )));

    $next = empty($_GET['destination'])
      ? $_SERVER['REQUEST_URI']
      : $_GET['destination'];

    if ($this->id)
      $form->action = "/nodeapi.rpc?action=edit&node={$this->id}&destination=". urlencode($next);
    else
      $form->action = "/nodeapi.rpc?action=create&type={$this->class}&destination=". urlencode($next);

    return $form;
  }

  private function formGetIntro()
  {
    $schema = TypeNode::getSchema($this->class);

    $description = empty($schema['description']) ? '' : '&nbsp; '. t('Его описание: %description.', array('%description' => rtrim($schema['description'], '.')));

    $intro = array();

    /*
    if (null === $this->id)
      $intro[] = t('Вы создаёте документ типа &laquo;%type&raquo;.', array('%type' => $schema['title'])) . $description;
    else
      $intro[] = t('Вы изменяете документ типа &laquo;%type&raquo;.', array('%type' => $schema['title'])) . $description;
    */

    if (mcms::user()->hasAccess('u', 'type') and $this->class != 'type' and substr($_SERVER['REQUEST_URI'], 0, 7) == '/admin/') {
      if (empty($schema['isdictionary']))
        $intro[] = t("Вы можете <a href='@typelink'>настроить этот тип</a>, добавив новые поля.", array(
          '@typelink' => "/admin/?mode=edit&id={$schema['id']}&destination=CURRENT",
          ));
      else
        $intro[] = t("Вы можете <a href='@typelink'>настроить этот справочник</a>, добавив новые поля.", array(
          '@typelink' => "/admin/?mode=edit&id={$schema['id']}&destination=CURRENT",
          ));
    }

    /*
    if (!empty($schema['fields']))
      foreach ($schema['fields'] as $k => $v)
        if (!empty($v['required'])) {
          $intro[] = t('Поля, отмеченные звёздочкой, обязательны для заполнения.');
          break;
        }
    */

    if (!empty($intro))
      return new InfoControl(array(
        'text' => '<p>'. join('</p><p>', $intro) .'</p>',
        ));

    return null;
  }

  private function formGetFilesTab()
  {
    $schema = TypeNode::getSchema($this->class);

    if (empty($schema['hasfiles']))
      return null;

    $tab = new FieldSetControl(array(
      'name' => 'files',
      'label' => t('Файлы'),
      'value' => 'tab_files',
      ));

    $tab->addControl(new FileListControl(array(
      'value' => 'node_content_files',
      )));

    $tab->addControl(new AttachmentControl(array(
      'extended' => true,
      'value' => 'node_content_files[__bebop]',
      'uploadtxt' => t('Загрузить'),
      'unzip' => true,
      )));

    return $tab;
  }

  private function getSectionsForThisType()
  {
    try {
      $list = Node::load(array('class' => 'type', 'name' => $this->class))->linkListParents('tag', true);
    } catch (ObjectNotFoundException $e) {
      $list = array();
    }

    return $list;
  }

  private function formGetSections(array $schema)
  {
    $options = array();

    if (!empty($schema['isdictionary']))
      return;

    if ('type' == $this->class) {
      if (in_array($this->name, array('comment', 'file')))
        return;
      if (!empty($this->notags))
        return;
    }

    switch (count($enabled = $this->getSectionsForThisType())) {
    case 0:
      break;
    case 1:
      return new HiddenControl(array(
        'value' => 'reset_rel',
        'default' => 1,
        ));
    default:
      $options['enabled'] = $enabled;
    }

    $tab = new FieldSetControl(array(
      'name' => 'sections',
      'label' => t('Разделы'),
      'value' => 'tab_sections',
      ));
    $tab->addControl(new HiddenControl(array(
      'value' => 'reset_rel',
      'default' => 1,
      )));
    $tab->addControl(new SetControl(array(
      'value' => 'node_tags',
      'label' => t('Поместить документ в разделы'),
      'options' => TagNode::getTags('select', $options),
      )));

    return $tab;
  }

  public function formGetData()
  {
    $schema = TypeNode::getSchema($this->class);

    $data = array(
      'node_content_id' => $this->id,
      'node_content_class' => $this->class,
      'node_content_parent_id' => $this->parent_id,
      'reset_rel' => 1,
      );

    if (array_key_exists('fields', $schema) and is_array($schema['fields']))
      foreach ($schema['fields'] as $k => $v) {
        $value = null;

        switch (mcms_ctlname($v['type'])) {
        case 'AttachmentControl':
          if (isset($this->files[$k]))
            $value = $this->files[$k];
          break;

        default:
          $value = $this->$k;
        }

        $data['node_content_'. $k] = $value;
      }

    if (!empty($this->files)) {
      foreach ($this->files as $key => $file) {
        $data['node_content_files['. $key .']'] = $file;
      }
    }

    if (empty($schema['notags'])) {
      $data['node_tags'] = $this->linkListParents('tag', true);
    }

    return $data;
  }

  public function formProcess(array $data)
  {
    $schema = TypeNode::getSchema($this->class);

    if (array_key_exists('fields', $schema)) {
      foreach ($schema['fields'] as $k => $v) {
        if ($k != 'parent_id' and $k != 'fields' and $k != 'config') {
          switch (mcms_ctlname($v['type'])) {
          case 'AttachmentControl':
            break;

          default:
            $key = 'node_content_'. $k;
            $this->$k = array_key_exists($key, $data) ? $data[$key] : null;
          }
        }
      }
    }

    if (array_key_exists('#node_override', $data))
        $this->data = array_merge($this->data, $data['#node_override']);

    $this->save();

    if (!empty($data['node_content_files'])) {
      foreach ($data['node_content_files'] as $field => $fileinfo) {
        if (!is_array($fileinfo))
          continue;

        $fkey = (!is_numeric($field) and $field != '__bebop') ? $field : null;

        // Удаление (отвязка) прикреплённого файла.
        if (!empty($fileinfo['delete'])) {
          $this->linkRemoveChild(empty($fileinfo['id']) ? null : $fileinfo['id'], $field);
        }

        // Загрузка нового файла.
        elseif (!empty($fileinfo['tmp_name'])) {
          $fileinfo['parent_id'] = $this->id;

          $node = Node::create('file');
          $node->import($fileinfo);

          $this->linkAddChild($node->id, $fkey);
        }

        // Подключение файла.
        elseif (!empty($fileinfo['id'])) {
          $this->linkAddChild($fileinfo['id'], $fkey);
        }

        // Обновление других свойств — поддерживается только для файлов в дополнительной вкладке,
        // у которых числовые индексы; для полей типа «файл» эта возможность отсутствует.
        elseif (is_numeric($field)) {
          $file = Node::load(array('class' => 'file', 'id' => $field));

          if (!empty($fileinfo['unlink']))
            $file->linkRemoveParent($this->id);

          else {
            if (!empty($fileinfo['name']))
              $file->data['name'] = $fileinfo['name'];
            if (!empty($fileinfo['description']))
              $file->data['description'] = $fileinfo['description'];

            if (!empty($fileinfo['tmp_name']))
              $file->import($fileinfo);

            $file->save();
          }
        }
      }
    }

    if (!empty($data['reset_rel'])) {
      $sections = $this->getSectionsForThisType();

      if (count($sections) == 1)
        $data['node_tags'] = $sections;
      elseif (empty($data['node_tags']) or !is_array($data['node_tags']))
        $data['node_tags'] = array();

      $this->linkSetParents($data['node_tags'], 'tag');
    }

    if (!empty($schema['hasfiles']) and !empty($data['node_ftp_files'])) {
      FileNode::getFilesFromFTP($data['node_ftp_files'], $this->id);
    }
  }
  
  private function getUTCTime()
  {
    $curtime = time();
    $utcdiff = date('Z', $curtime);
    $utctime = $curtime-$utcdiff;
    $tm = date('Y-m-d H:i:s', $utctime);
    return $tm;
  }  

  // Сохранение объекта в БД.
  private function dbWrite()
  {
    $extra = $this->data;

    // Вытаскиваем данные, которые идут в поля таблиц.
    $node = $this->dbWriteExtract($extra, array('id', 'lang', 'parent_id', 'class', 'code', 'left', 'right', 'uid', 'created', 'published', 'deleted'));
    $node_rev = $this->dbWriteExtract($extra, array('name'));

    // Выделяем место в иерархии, если это необходимо.
    $this->dbExpandParent($node);

    // Удаляем лишние поля.
    $this->dbWriteExtract($extra, array('rid', 'updated', 'files'), true);

    // Создание новой ноды.
    if (empty($node['id'])) {
      mcms::db()->exec($sql = "INSERT INTO `node` (`id`, `lang`, `parent_id`, `class`, `code`, `left`, `right`, `uid`, `created`, `updated`, `published`, `deleted`) VALUES (:id, :lang, :parent_id, :class, :code, :left, :right, :uid, :created, :updated, :published, :deleted)", $params = array(
        'id' => $this->dbGetNextId('node', 'id'),
        'lang' => $node['lang'],
        'parent_id' => $node['parent_id'],
        'class' => $node['class'],
        'code' => is_numeric($node['code']) ? null : $node['code'],
        'left' => $node['left'],
        'right' => $node['right'],
        'uid' => $node['uid'],
        'created' => $node['created'],
        'updated' => mcms::now(),
        'published' => empty($node['published']) ? 0 : 1,
        'deleted' => empty($node['deleted']) ? 0 : 1,
        ));

      $this->data['id'] = $node['id'] = mcms::db()->lastInsertId();

      if (empty($this->data['id'])) {
        mcms::debug($sql, $params);
      }
    }

    // Обновление существующей ноды.
    else {
      mcms::db()->exec("UPDATE `node` SET `code` = :code, `uid` = :uid, `created` = :created, `updated` = :updated, `published` = :published, `deleted` = :deleted WHERE `id` = :id AND `lang` = :lang", array(
        'code' => is_numeric($node['code']) ? null : $node['code'],
        'uid' => $node['uid'],
        'created' => $node['created'],
        'updated' => mcms::now(),
        'published' => empty($node['published']) ? 0 : 1,
        'deleted' => empty($node['deleted']) ? 0 : 1,
        'id' => $node['id'],
        'lang' => $node['lang'],
        ));
    }

    // Сохранение ревизии.
    mcms::db()->exec($sql = "INSERT INTO `node__rev` (`nid`, `uid`, `name`, `created`, `data`) VALUES (:nid, :uid, :name, :created, :data)", $params = array(
      'nid' => $node['id'],
      'uid' => $node['uid'],
      'name' => $node_rev['name'],
      'created' => mcms::now(),
      'data' => empty($extra) ? null : serialize($extra),
      ));
    $this->data['rid'] = mcms::db()->lastInsertId();

    if (empty($this->data['rid'])) {
      mcms::debug('Error saving revision', $this->data, $sql, $params);
      throw new RuntimeException(t('Не удалось получить номер сохранённой ревизии.'));
    }

    // Замена старой ревизии новой.
    mcms::db()->exec("UPDATE `node` SET `rid` = :rid WHERE `id` = :id AND `lang` = :lang", array(
      'rid' => $this->data['rid'],
      'id' => $node['id'],
      'lang' => $node['lang'],
      ));

    if (empty($this->data['id']) or empty($this->data['rid']))
      mcms::fatal('Either id or rid not defined.', $this->data);
  }

  // Вытаскиывает из $data значения полей, перечисленных в $fields.  Возвращает
  // их в виде массива, из исходного массива поля удаляются.  При $cleanup из
  // него также удаляются пустые значения (empty()).
  private function dbWriteExtract(array &$data, array $fields, $cleanup = false)
  {
    $tmp = array();

    foreach ($fields as $f) {
      if (array_key_exists($f, $data)) {
        $tmp[$f] = $data[$f];
        unset($data[$f]);
      } else {
        $tmp[$f] = null;
      }
    }

    if ($cleanup) {
      foreach ($data as $k => $v) {
        if (empty($v))
          unset($data[$k]);
      }
    }

    return $tmp;
  }

  // Если создаётся новый документ, и для него указан родитель — вписываем
  // в иерархию, в противном случае ничего не делаем.
  private function dbExpandParent(array &$node)
  {
    if (empty($node['id']) and !empty($node['parent_id'])) {
      $parent = array_shift(mcms::db()->getResults("SELECT `left`, `right` FROM `node` WHERE `id` = :parent AND `lang` = :lang",
        array(':parent' => $node['parent_id'], 'lang' => $node['lang'])));

      // Родитель сам вне дерева — прописываем его.
      if (empty($parent['left']) or empty($parent['right'])) {
        $pos = $this->dbGetNextId('node', 'right');

        $parent['left'] = $pos;
        $parent['right'] = $pos + 3;

        $node['left'] = $pos + 1;
        $node['right'] = $pos + 2;

        mcms::db()->exec("UPDATE `node` SET `left` = :left, `right` = :right WHERE `id` = :id AND `lang` = :lang", array(
          'left' => $parent['left'],
          'right' => $parent['right'],
          'id' => $node['parent_id'],
          'lang' => $node['lang'],
          ));
      }

      // Раздвигаем родителя.
      else {
        $node['left'] = $parent['right'];
        $node['right'] = $node['left'] + 1;

        mcms::db()->exec("UPDATE `node` SET `left` = `left` + 2 WHERE `left` >= :pos", array(':pos' => $node['left']));
        mcms::db()->exec("UPDATE `node` SET `right` = `right` + 2 WHERE `right` >= :pos", array(':pos' => $node['left']));
      }
    }
  }

  // Возвращает следующий доступный идентификатор для таблицы.
  // FIXME: при большой конкуренции будут проблемы.
  private function dbGetNextId($table, $field)
  {
    return mcms::db()->getResult("SELECT MAX(`{$field}`) FROM `{$table}`") + 1;
  }

  // Чтение данных.  Входной параметр — массив условий, например:
  //  "`node`.`id` IN (1, 2, 3)"
  public static function dbRead($sql, array $params = null, $recurse = 0)
  {
    $nodes = array();

    foreach (mcms::db()->getResults($sql, $params) as $row) {
      // Складывание массивов таким образом может привести к перетиранию
      // системных полей, но в нормальной ситуации такого быть не должно:
      // при сохранении мы удаляем всё системное перед сериализацией.
      // Зато такой подход быстрее ручного перебора.
      if (!empty($row['data']))
        $row = array_merge($row, unserialize($row['data']));

      unset($row['data']);

      $nodes[$row['id']] = Node::create($row['class'], $row);
    }

    // Подгружаем прилинкованые ноды.
    if ($recurse and !empty($nodes)) {
      $map = array();

      foreach (mcms::db()->getResults($sql = "SELECT `tid`, `nid`, `key` FROM `node__rel` WHERE `tid` IN (". join(', ', array_keys($nodes)) .")") as $l)
        $map[$l['nid']][] = $l;

      if (!empty($map)) {
        $extras = Node::find(array('id' => array_keys($map), '#recurse' => $recurse - 1));

        foreach ($map as $k => $v) {
          if (array_key_exists($k, $extras)) {
            foreach ($v as $link) {
              $extra = $extras[$link['nid']];

              if (null !== $link['key'])
                $nodes[$link['tid']]->$link['key'] = $extra;
              elseif ('file' == $extra->class)
                $nodes[$link['tid']]->files[] = $extra;
            }
          }
        }
      }
    }

    return $nodes;
  }
};

<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2 fenc=utf8 enc=utf8:

class TagNode extends Node implements iContentType
{
  public function save()
  {
    $isnew = empty($this->id);

    if (null === $this->parent_id) {
      try {
        $node = Node::load(array('class' => 'tag', 'parent_id' => null, 'deleted' => 0));
        $this->data['parent_id'] = $node->id;
      } catch (ObjectNotFoundException $e) {
      }
    }

    parent::save();

    // При добавлении раздела копируем родительский список типов.
    if ($isnew and !empty($this->parent_id))
      mcms::db()->exec("INSERT INTO `node__rel` (`tid`, `nid`, `key`, `order`) "
        ."SELECT :me, `nid`, `key`, `order` FROM `node__rel` "
        ."WHERE `tid` = :parent AND `nid` IN (SELECT `id` FROM `node` WHERE `class` = 'type')",
        array(':me' => $this->id, ':parent' => $this->parent_id));
  }

  // Возвращает список существующих разделов, в виде плоского списка
  // с элементом depth, для рендеринга в виде дерева.
  public static function getTags($mode, array $options = array())
  {
    $result = array();

    // Загружаем все корневые разделы (в нормальных условиях такой должен быть один,
    // но на случае ошибок в БД мы всё таки даём возможность работать с ошибочными
    // разделами).
    foreach (Node::find(array('class' => 'tag', 'parent_id' => null)) as $node) {
      if ($mode == 'select') {
        foreach ($node->getChildren($mode, $options) as $k => $v)
          $result[$k] = $v;
       } else {
        $result = array_merge($result, $node->getChildren($mode, $options));
       }
    }

    return $result;
  }

  public function formGet($simple = true)
  {
    $form = parent::formGet($simple);

    if (null === $this->id and null === $this->parent_id and self::haveRoot())
      mcms::message(t('Вы пытаетесь создать второй корневой раздел.  Он будет добавлен в существующий корень.  Для добавления подразделов используйте ссылку «добавить» в правой части таблицы разделов.'));

    $form->title = (null === $this->id)
      ? t('Добавление нового раздела')
      : t('Редактирование раздела "%name"', array('%name' => $this->name));

    return $form;
  }

  private static function haveRoot()
  {
    return Node::count(array('class' => 'tag', 'parent_id' => null, 'deleted' => 0));
  }
};

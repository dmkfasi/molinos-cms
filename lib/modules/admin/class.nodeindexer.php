<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class NodeIndexer
{
  public static function stats()
  {
    $stat = array(
      '_total' => 0,
      );

    foreach (TypeNode::getSchema() as $type => $meta) {
      $indexed = false;

      foreach ($meta['fields'] as $v) {
        if (!empty($v['indexed'])) {
          $indexed = true;
          break;
        }
      }

      if ($indexed) {
        if ($count = mcms::db()->getResult("SELECT COUNT(*) FROM `node` `n` WHERE `n`.`class` = '{$type}' AND `n`.`deleted` = 0 AND NOT EXISTS (SELECT 1 FROM `node__idx_{$type}` `n1` WHERE `n1`.`id` = `n`.`id`)")) {
          $stat[$type] = $count;
          $stat['_total'] += $count;
        }
      }
    }

    return empty($stat['_total']) ? null : $stat;
  }

  public static function run()
  {
    $repeat = false;

    if (null !== ($stat = self::stats())) {
      if ('_total' != ($class = array_pop(array_keys($stat)))) {
        $ids = mcms::db()->getResultsV('id', "SELECT `n`.`id` FROM `node` `n` WHERE `n`.`deleted` = 0 AND `n`.`class` = :class AND NOT EXISTS (SELECT 1 FROM `node__idx_{$class}` `i` WHERE `i`.`id` = `n`.`id`) LIMIT 50", array(':class' => $class));

        foreach ($count = Node::find(array('class' => $class, 'id' => $ids)) as $n)
          $n->reindex();

        $repeat = true;
      }
    }

    return $repeat;
  }
}
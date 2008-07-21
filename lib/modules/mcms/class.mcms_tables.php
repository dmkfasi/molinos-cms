<?php

class mcms_tables extends Yadro
{
  protected static function on_mcms_update_table_node(PDO $pdo)
  {
    $columns = array (
      'id' => array (
        'type' => 'int',
        'required' => true,
        'key' => 'pri',
        'autoincrement' => true,
      ),
      'rid' => array (
        'type' => 'int',
        'required' => 0,
        'key' => 'mul',
      ),
      'parent_id' => array (
        'type' => 'int',
        'required' => 0,
      ),
      'class' => array (
        'type' => 'varchar(16)',
        'required' => 1,
        'key' => 'mul',
      ),
      'code' => array (
        'type' => 'varchar(16)',
        'required' => 0,
        'key' => 'uni',
      ),
      'left' => array (
        'type' => 'int',
        'required' => 0,
        'key' => 'mul',
      ),
      'right' => array (
        'type' => 'int',
        'required' => 0,
        'key' => 'mul',
      ),
      'uid' => array (
        'type' => 'int',
        'required' => 0,
        'key' => 'mul',
      ),
      'created' => array (
        'type' => 'datetime',
        'required' => 0,
        'key' => 'mul',
      ),
      'updated' => array (
        'type' => 'datetime',
        'required' => 0,
        'key' => 'mul',
      ),
      'published' => array (
        'type' => 'tinyint(1)',
        'required' => 1,
        'default' => 0,
        'key' => 'mul',
      ),
      'deleted' => array (
        'type' => 'tinyint(1)',
        'required' => 1,
        'default' => 0,
        'key' => 'mul',
      ),
    );

    return TableInfo::update($pdo, 'node', $columns);
  }

  protected static function on_mcms_update_table_node__rel(PDO $pdo)
  {
    return TableInfo::update($pdo, 'node__rel', array(
      'nid' => array(
        'type' => 'int',
        'required' => 1,
        'key' => 'mul'
        ),
      'tid' => array(
        'type' => 'int',
        'required' => 1,
        'key' => 'mul'
        ),
      'key' => array(
        'type' => 'varchar(255)',
        'required' => 0,
        'key' =>'mul'
        ),
      'order' => array(
        'type' => 'int',
        'required' => 0,
        'key' =>'mul'
        ),
      ));
  }
}

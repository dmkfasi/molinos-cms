<?php

class AliasModule implements iRequestHook
{
  public static function hookRequest(RequestContext $ctx = null)
  {
    if (null === $ctx) {
      try {
        if (null !== ($next = mcms::db()->getResult("SELECT `dst` FROM `node__alias` WHERE `src` = :src", array(':src' => $_SERVER['REQUEST_URI']))))
          mcms::redirect($next, 301);
      } catch (PDOException $e) {
      }
    }
  }

  public static function hookPostInstall()
  {
    $t = new TableInfo('node__alias');

    if (!$t->exists()) {
      $t->columnSet('src', array(
        'type' => 'varchar(255)',
        'required' => true,
        'key' => 'mul',
        ));
      $t->columnSet('dst', array(
        'type' => 'varchar(255)',
        'required' => true,
        'key' => 'uni',
        ));

      $t->commit();
    }
  }
};

<?php

class mcms_admin_ui_domains extends Yadro
{
  protected static function on_mcms_admin_dashboard(Context $ctx)
  {
    $result = array(
      'test' => array(
        'group' => 'Domains',
        'text' => 'Управление доменами',
        'description' => 'Переход к панели управления доменами.',
        'url' => 'http://www.webnames.ru/',
        ),
      );

    return $result;
  }
}

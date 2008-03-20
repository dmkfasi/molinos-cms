<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class AdminUISearch extends Control
{
  public static function getInfo()
  {
    return array(
      'name' => t('Форма поиска по списку'),
      'hidden' => true,
      );
  }

  public function __construct(array $form)
  {
    parent::__construct($form);
  }

  public function getHTML(array $data)
  {
    $output = $this->getLeftPart();
    return mcms::html('div', array('class' => 'tb_1'), $output);
  }

  private function getLeftPart()
  {
    $output = $this->getCreateHTML();
    $output .= '&nbsp;|&nbsp;';
    $output .= $this->getSearchHTML();

    return mcms::html('div', array('class' => 'ctrl_left'), $output);
  }

  private function getCreateHTML()
  {
    if (count($this->type) == 1)
      $type = $this->type[0];
    else
      $type = null;

    $output = mcms::html('a', array(
      'class' => 'newlink',
      'href' => "/admin/?mode=create&type={$type}&destination=". urlencode($_SERVER['REQUEST_URI']),
      ), 'Добавить');

    return $output;
  }

  private function getSearchHTML()
  {
    $output = '';

    $output = mcms::html('input', array(
      'type' => 'text',
      'name' => $this->value,
      'class' => 'search_field',
      'value' => $this->q,
      ));

    $output .= mcms::html('input', array(
      'type' => 'submit',
      'value' => 'Найти',
      ));

    return $output;
  }
};

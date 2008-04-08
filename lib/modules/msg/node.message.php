<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class MessageNode extends Node
{
  public function save()
  {
    if (!isset($this->id)) {
      try {
        $dst = Node::load(array('class' => 'user', 'id' => $this->re));

        if (isset($dst->email) and class_exists('BebopMimeMail')) {
          BebopMimeMail::send(null, $dst->email, $this->name, $this->text);
          $this->data['sent'] = 1;
        }
      } catch (ObjectNotFoundException $e) {
      }
    }

    return parent::save();
  }

  public function getDefaultSchema()
  {
    return array(
      'name' => 'message',
      'title' => t('Сообщение'),
      'description' => t('Используется для обмена сообщениями между пользователями, а также для доставки внутренних уведомлений.'),
      'lang' => 'ru',
      'fields' => array(
        'uid' => array(
          'label' => t('Отправитель'),
          'type' => 'NodeLinkControl',
          'required' => true,
          'values' => 'user.name',
          ),
        're' => array(
          'label' => t('Получатель'),
          'type' => 'NodeLinkControl',
          'required' => true,
          'values' => 'user.name',
          'indexed' => true,
          ),
        'name' => array(
          'label' => ('Заголовок'),
          'type' => 'TextLineControl',
          'required' => true,
          ),
        'created' => array(
          'label' => t('Дата отправления'),
          'type' => 'DateTimeControl',
          'required' => true,
          ),
        'sent' => array(
          'label' => t('Отправлено по почте'),
          'type' => 'BoolControl',
          'required' => false,
          'indexed' => true,
          ),
        'received' => array(
          'label' => t('Дата прочтения'),
          'type' => 'DateTimeControl',
          'required' => false,
          'indexed' => true,
          ),
        'text' => array(
          'label' => t('Текст'),
          'type' => 'TextHTMLControl',
          'required' => true,
          ),
        ),
      );
  }

  // Доступ к сообщению имеют только отправитель и получатель.
  public function checkPermission($perm)
  {
    $user = mcms::user();

    if ($user->id == $this->uid or $user->id == $this->re)
      return true;

    return false;
  }
}
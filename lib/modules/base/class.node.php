<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2 fenc=utf8 enc=utf8:

class Node extends NodeBase implements iContentType, iModuleConfig, iNodeHook
{
  // Создаём пустой объект указанного типа, проверяем тип на валидность.
  protected function __construct(array $data = null)
  {
    $this->data = $data;
  }

  // Форматирует документ в соответствии с шаблоном.
  public function render()
  {
    return bebop_render_object("class", $this->class, "all", $this->data);
  }

  // Проверка прав на объект.  Менеджеры контента всегда всё могут.
  public function checkPermission($perm)
  {
    if (mcms::user()->hasGroup('Content Managers'))
      return true;
    return NodeBase::checkPermission($perm);
  }

  // РАБОТА С ФОРМАМИ.

  public function formGetData()
  {
    $user = mcms::user();

    $data = parent::formGetData();

    if ($user->hasGroup('Access Managers'))
      $data['node_access'] = $this->getAccess();

    $data['reset_access'] = 1;
    $data['node_published'] = $this->published;

    return $data;
  }

  public function formProcess(array $data)
  {
    if (null === $this->id and empty($data['node_access']))
      // Документы без прав создаются, как правило,
      // через сайт, в виде обратной связи.
      $data['node_access'] = array(
        'Content Managers' => array('r', 'u', 'd'),
        'Visitors' => array('r'),
        );

    parent::formProcess($data);

    $user = mcms::user();

    if (!empty($data['reset_access'])) {
      if ($user->hasGroup('Access Managers'))
        $this->setAccess(empty($data['node_access']) ? array() : $data['node_access']);
      if ($user->hasGroup('Publishers')) {
        if ($this->published and empty($data['node_published']))
          $this->unpublish();
        elseif (!$this->published and !empty($data['node_published']))
          $this->publish();
      }
    }
  }

  public function getAccess()
  {
    $data = parent::getAccess();

    if (null === $this->id and get_class($this) == 'Node') {
      $data['Content Managers']['r'] = 1;
      $data['Content Managers']['u'] = 1;
      $data['Content Managers']['d'] = 1;
      $data['Visitors']['r'] = 1;
    }

    return $data;
  }

  public static function formGetModuleConfig()
  {
    $form = new Form(array());

    $form->addControl(new NumberControl(array(
      'value' => 'config_archive_limit',
      'label' => t('Количество архивных ревизий'),
      'default' => 10,
      'description' => t('При сохранении документов будет оставлено указанное количество архивных ревизий, все остальные будут удалены.'),
      )));

    return $form;
  }

  public static function hookNodeUpdate(Node $node, $op)
  {
    switch ($op) {
    case 'erase':
      // Удаляем расширенные данные.
      $t = new TableInfo('node_'. $node->class);
      if ($t->exists())
        mcms::db()->exec("DELETE FROM `node_{$node->class}` WHERE `rid` IN (SELECT `rid` FROM `node__rev` WHERE `nid` = :nid)", array(':nid' => $node->id));

      // Удаляем все ревизии.
      mcms::db()->exec("DELETE FROM `node__rev` WHERE `nid` = :nid", array(':nid' => $node->id));

      // Удаляем связи.
      mcms::db()->exec("DELETE FROM `node__rel` WHERE `nid` = :nid OR `tid` = :tid", array(':nid' => $node->id, ':tid' => $node->id));

      // Удаляем доступ.
      mcms::db()->exec("DELETE FROM `node__access` WHERE `nid` = :nid OR `uid` = :uid", array(':nid' => $node->id, ':uid' => $node->id));

      // Удаление статистики.
      $t = new TableInfo('node__astat');
      if ($t->exists())
        mcms::db()->exec("DELETE FROM `node__astat` WHERE `nid` = :nid", array(':nid' => $node->id));

      break;
    }
  }

  public static function hookPostInstall()
  {
  }

  public function getDefaultSchema()
  {
    return array(
      'title' => 'Без названия',
      'lang' => 'ru',
      'fields' => array(
        'name' => array(
          'label' => t('Заголовок'),
          'type' => 'TextLineControl',
          'required' => true,
          ),
        'created' => array(
          'label' => t('Дата создания'),
          'type' => 'DateTimeControl',
          'required' => false,
          ),
        'uid' => array(
          'label' => t('Автор'),
          'type' => 'NodeLinkControl',
          'required' => false,
          'values' => 'user.name',
          ),
        ),
      );
  }
};
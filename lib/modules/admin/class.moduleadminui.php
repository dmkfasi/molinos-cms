<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class ModuleAdminUI
{
  public function getList()
  {
    $map = $this->getModules();

    $output = '';

    foreach ($map as $group => $modules) {
      $output .= "<tr class='modgroup'><th colspan='5'>{$group}</th></tr>";

      foreach ($modules as $modname => $module) {
        $output .= '<tr>';

        $output .= "<td>". mcms::html('input', array(
          'type' => 'checkbox',
          'name' => 'selected[]',
          'value' => $modname,
          'checked' => empty($module['enabled']) ? null : 'checked',
          'disabled' => ('core' == strtolower($group)) ? 'disabled' : null,
          )) ."</td>";

        if (!empty($module['implementors']['iModuleConfig']) and !empty($module['enabled']))
          $output .= mcms::html('td', mcms::html('a', array(
            'class' => 'icon configure',
            'href' => "/admin/?mode=modules&action=config&name={$modname}&cgroup={$_GET['cgroup']}&destination=CURRENT",
            ), '<span>настроить</span>'));
        else
          $output .= mcms::html('td');

        if (!empty($module['docurl']))
          $output .= "<td>". mcms::html('a', array(
            'class' => 'icon information',
            'href' => $module['docurl'],
            ), "<span>информация</span>") ."</td>";
        else
          $output .= "<td>&nbsp;</td>";

        $output .= mcms::html('td', mcms::html('a', array('href' => "/admin/?mode=modules&amp;action=info&amp;name={$modname}&amp;cgroup={$_GET['cgroup']}&destination=CURRENT"), $modname));
        $output .= mcms::html('td', $module['name']['ru']);

        $output .= '</tr>';
      }
    }

    $output = mcms::html('table', array(
      'class' => 'modlist',
      ), $output);

    $output .= mcms::html('input', array(
      'type' => 'submit',
      'value' => t('Сохранить'),
      ));

    return '<h2>Список модулей</h2>'. mcms::html('form', array(
      'method' => 'post',
      'action' => "/admin.rpc?action=modlist&destination=CURRENT",
      ), $output);
  }

  private function getModules()
  {
    $map = mcms::getModuleMap();

    $groups = array();

    foreach ($map['modules'] as $modname => $module)
      $groups[$module['group']][$modname] = $module;

    ksort($groups);

    return $groups;
  }
};

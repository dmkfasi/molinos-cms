<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class BebopDashboard extends Widget implements iAdminWidget
{
  public function __construct(Node $node)
  {
    parent::__construct($node);
  }

  public static function getWidgetInfo()
  {
    return array(
      'name' => t('Панель управления'),
      'description' => t("Возвращает описание основных разделов админки."),
      );
  }

  // Препроцессор параметров.
  public function getRequestOptions(RequestContext $ctx)
  {
    $options = array_merge(parent::getRequestOptions($ctx), array(
      'groups' => $this->user->getGroups(true),
      ));
    return $options;
  }

  // Обработка запросов.  Возвращает список действий, предоставляемых административными виджетами.
  public function onGet(array $options)
  {
    $result = array();

    $classes = mcms::getClassMap();
    $rootlen = strlen(getcwd());

    foreach (mcms::getImplementors('iDashboard') as $classname) {
      if (is_array($items = call_user_func(array($classname, 'getDashboardIcons'))) and !empty($items)) {
        $path = substr(dirname($classes[strtolower($classname)]), $rootlen);

        foreach ($items as $item) {
          if (isset($item['img'])) {
            $img = substr($path, 1) .'/'. $item['img'];

            if (!file_exists($img))
              $item['img'] = '/lib/modules/dashboard/img/cms-default.png';
            else
              $item['img'] = '/'. $img;
          }

          if (empty($item['group']))
            $group = t('Misc');
          else {
            $group = $item['group'];
            unset($item['group']);
          }

          $result[$group][] = $item;
        }
      }
    }

    ksort($result);

    return self::formatDashBoard2($result);

    /*
    if (!empty($result['list']))
      usort($result['list'], array('BebopDashboard', 'usort'));
    */
  }

  private static function formatDashBoard2(array $result)
  {
    $controls = $items = array();
    $index = $groupidx = 1;

    $oldgroup = null;

    foreach ($result as $group => $icons) {
      if ($oldgroup !== $group) {
        $controls[] = mcms::html('a', array(
          'href' => '#'. $index,
          ), mcms_plain($group));
      }

      foreach ($icons as $icon) {
        if (!empty($icon['img'])) {
          $html = '<li class=\'group-'. $groupidx .'\'>';
          $html .= mcms::html('a', array(
            'href' => $icon['href'],
            'style' => 'display: block; background: transparent url('. $icon['img'] .') no-repeat top center; padding: 85px 2px 0 2px; text-align: center',
            'description' => isset($icon['description']) ? $icon['description'] : null,
            ), mcms_plain($icon['title']));
          $html .= '</li>';

          $items[] = $html;
        }

        $index++;
      }

      $groupidx++;
    }

    $html = '<div class=\'carousel-control\'>'. join('', $controls) .'</div>';
    $html .= '<div class=\'carousel jcarousel-skin-tango\'>';
    $html .= '<ul>'. join('', $items) .'</ul>';
    $html .= '</div>';

    return $html;
  }

  private static function formatDashBoard(array $result)
  {
    // Формируем HTML код.
    $html = '';

    foreach ($result as $group => $icons) {
      $html .= '<li><span class=\'group-header\'>'. mcms_plain($group) .'</span>';
      $html .= '<ul>';

      foreach ($icons as $icon) {
        $img = empty($icon['img']) ? '' : mcms::html('span', array(
          'class' => 'icon',
          'style' => 'display:none'
          ), $icon['img']);

        $html .= '<li class=\'item\'>';
        $html .= mcms::html('a', array(
          'href' => $icon['href'],
          'title' => empty($icon['description']) ? null : $icon['description'],
          ), $img . mcms_plain($icon['title']));
        $html .= '</li>';
      }

      $html .= '</ul>';
    }
    
    return '<div class=\'dashboard\'><ul>'. $html .'</ul><div class=\'separator\'></div></div>';
  }

  private function usort(array $a, array $b)
  {
    if (0 !== ($tmp = $a['weight'] - $b['weight']))
      return $tmp;

    return strcmp($a['title'], $b['title']);
  }
};
<?php
// Molinos CMS administration module.
// This class manages the administrative dashboard and dispatches
// requests across other handlers which implement administrative
// functions.

class mcms_admin_ui extends Yadro
{
  protected static function on_mcms_rpc_admin(Context $ctx)
  {
    $data = array();
    $data['nodes'] = $ctx->db->get("SELECT COUNT(*) FROM {node}");
    $data['blocks']['dashboard'] = self::format_dashboard($ctx);

    $text = mcms::render(null, $data, null,
      'lib/modules/mcms_admin/templates/default.phtml');

    mcms::send($text, 'text/html', 200);
  }

  private static function format_dashboard(Context $ctx)
  {
    $result = array();

    if (null !== ($tmp = Yadro::call('mcms_admin_dashboard', $ctx))) {
      foreach ($tmp as $t1) {
        foreach ($t1 as $t2) {
          if (!empty($t2['group'])) {
            $group = $t2['group'];
            unset($t2['group']);

            $result[$group][] = $t2;
          }
        }
      }
    }

    Yadro::dump($result);

    $text = mcms::render(null, $result, null,
      'lib/modules/mcms_admin/templates/block.dashboard.phtml');

    return $text;
  }
}

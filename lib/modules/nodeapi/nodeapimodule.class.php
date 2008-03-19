<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

class NodeApiModule implements iRemoteCall
{
  public static function hookRemoteCall(RequestContext $ctx)
  {
    if ($ctx->get('action') == 'mass')
      self::doMassAction($ctx);
    else
      self::doSingleAction($ctx);

    if (null !== ($next = $ctx->get('destination')))
      bebop_redirect($next);
  }

  private static function doMassAction(RequestContext $ctx)
  {
    if (!empty($_POST['nodes']) and !empty($_POST['action']) and is_array($_POST['action'])) {
      foreach ($_POST['action'] as $action) {
        if (!empty($action)) {
          foreach ($_POST['nodes'] as $nid)
            self::doSingleAction($ctx, $action, $nid);
          break;
        }
      }
    }
  }

  private static function doSingleAction(RequestContext $ctx, $action = null, $nid = null)
  {
    if (null === $action)
      $action = $ctx->get('action');

    if (null === $nid)
      $nid = $ctx->get('node');

    switch ($action) {
    case 'publish':
      if (null !== $nid) {
        $node = Node::load($nid);
        $node->publish();
      }
      break;

    case 'unpublish':
      if (null !== $nid) {
        $node = Node::load($nid);
        $node->unpublish();
      }
      break;

    case 'delete':
      if (null !== $nid) {
        $node = Node::load($nid);
        $node->delete();
      }
      break;

    case 'clone':
      $node = Node::load(array(
        'id' => $nid,
        'deleted' => array(0, 1),
        ));
      $node->duplicate();
      break;

    case 'create':
      $node = Node::create($ctx->get('type'));
      $node->formProcess($_POST);
      $node->save();
      break;

    case 'edit':
      $node = Node::load($ctx->get('node'));
      $node->formProcess($_POST);
      $node->save();
      break;

    case 'undelete':
      $node = Node::load(array(
        'id' => $nid,
        'deleted' => 1,
        ));
      $node->undelete();
      break;

    case 'erase':
      $node = Node::load(array(
        'id' => $nid,
        'deleted' => 1,
        ));
      $node->erase();
      break;

    default:
      bebop_debug($ctx, $_POST);
    }
  }
};

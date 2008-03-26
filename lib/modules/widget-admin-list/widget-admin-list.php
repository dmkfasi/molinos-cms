<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

require_once(dirname(__FILE__) .'/widget-admin-list-table.inc');

class ListAdminWidget extends ListWidget
{
  public function __construct(Node $node)
  {
    parent::__construct($node);
  }

  public static function getWidgetInfo()
  {
    return array(
      'name' => 'Название виджета',
      'description' => 'Описание виджета.',
      );
  }

  // Препроцессор параметров.
  public function getRequestOptions(RequestContext $ctx)
  {
    $options = parent::getRequestOptions($ctx);

    $options['picker'] = $ctx->get('picker');

    if (null !== ($tmp = $ctx->get('section')))
      $options['filter']['tags'][] = $tmp;

    if ($options['mode'] == 'raise' or $options['mode'] == 'sink') {
      $options['nid'] = $ctx->get('nid');
      $options['tid'] = $ctx->get('tid');
      $options['#nocache'] = true;
    }

    if (null !== ($tmp = $ctx->get('sort')))
      $options['sort']= array($tmp => $ctx->get('sortmode', 'asc'));

    if (null !== ($tmp = $ctx->get('published')))
      $options['filter']['published'] = $tmp;

    $this->options = $options;

    return $options;
  }

  // Обработка GET запросов.
  public function onGet(array $options)
  {
    return $this->dispatch(array($options['mode']), $options);
  }

  protected function onGetList(array $options)
  {
    $html = parent::formRender('document-list', $this->formGetData('document-list'));
    return $html;
  }

  protected function onGetRaise(array $options)
  {
    $node = Node::load($options['nid']);
    $node->orderUp($options['tid']);
    bebop_redirect($_GET['destination']);
  }

  protected function onGetSink(array $options)
  {
    $node = Node::load($options['nid']);
    $node->orderDown($options['tid']);
    bebop_redirect($_GET['destination']);
  }

  protected function queryGet(array $options = null)
  {
    if (null === $options)
      $options = $this->options;

    if (is_array($this->filter))
      $options['filter'] = array_merge($this->filter, $options['filter']);

    return parent::queryGet($options);
  }

  // РАБОТА С ФОРМАМИ.

  public function formGet($id)
  {
    // bebop_debug($this->me->config, $this->options);

    switch ($id) {
    case 'document-list':
      $columns = $this->columns;

      if (!empty($this->options['filter']['tags']) and !array_key_exists('actions', $columns))
        $columns['actions'] = t('Действия');

      $form = new Form(array(
        'title' => $this->me->title,
        ));

      $form->addControl(new DocSearchControl(array(
        'value' => 'document_list_search',
        'widget' => $this->getInstanceName(),
        'sections' => $this->sections ? 'document_list_search_section' : null,
        'filterform' => $this->filterform,
        )));

      $form->addControl(new DocMassControl(array(
        'value' => 'document_list_mass',
        'class' => 'tb_2_top',
        'table' => 'contentTable',
        )));

      $form->addControl(new DocListControl(array(
        'id' => 'contentTable',
        'value' => 'document_list',
        'widget' => $this->getInstanceName(),
        'columns' => $columns,
        'sortable' => $this->sortable,
        'picker' => $this->options['picker'],
        'sort' => empty($this->options['sort']) ? array() : $this->options['sort'],
        )));

      $form->addControl(new DocMassControl(array(
        'value' => 'document_list_mass',
        'class' => 'tb_2_btm',
        'table' => 'contentTable',
        )));

      $form->addControl(new PagerControl(array(
        'value' => 'document_list_pager',
        'widget' => $this->getInstanceName(),
        'showempty' => true,
        )));

      return $form;
    }
  }

  public function formGetData($id)
  {
    switch ($id) {
    case 'document-list':
      $result = array(
        'document_list_pager' => null,
        'document_list_mass' => array(),
        'document_list' => array(),
        );

      if (!is_array($result['document_list_mass']['selectors'] = $this->selectors))
        $result['document_list_mass']['selectors'] = array(
          'all' => t('все'),
          'none' => t('ни одного'),
          'published' => t('опубликованные'),
          'unpublished' => t('скрытые'),
          );

      if (!is_array($result['document_list_mass']['operations'] = $this->operations))
        $result['document_list_mass']['operations'] = array(
          'delete' => t('Удалить'),
          'publish' => t('Опубликовать'),
          'unpublish' => t('Скрыть'),
          'clone' => t('Клонировать'),
          );

      if (null !== $this->sections) {
        $result['document_list_search_section'] = $this->getSectionList();
        $result['document_list_search_section_current'] = $this->ctx->get('section');
      }

      if (is_array($this->filter))
        $this->getNodeList($result);
      elseif (null !== $this->tree)
        $this->getNodeTree($result);

      $result['document_list_search'] = $this->ctx->get('search');

      return $result;
    }
  }

  private function getNodeList(array &$result)
  {
    $query = $this->queryGet();

    $pagelim = empty($this->options['limit']) ? null : $this->options['limit'];
    $pagenum = empty($this->options['page']) ? null : $this->options['page'];

    $result['document_list_pager'] = array(
      'total' => Node::count($query),
      'page' => $pagenum,
      'limit' => $pagelim,
      );

    $nodelist = Node::find($query, $pagelim, $pagelim ? (($pagenum - 1) * $pagelim) : null);

    foreach ($nodelist as $node) {
      $item = array(
        'published' => !empty($node->published),
        );

      $link = true;

      foreach ($this->columns as $field => $title) {
        if (empty($node->$field))
          $text = null;
        elseif ($field == 'uid')
          $text = $this->resolveUid($node->$field);
        elseif ($field == 'class')
          $text = $this->resolveType($node->$field);
        else
          $text = mcms_plain($node->$field);

        if ($link) {
          $mod = '';
          $classes = array();

          if (null === $text)
            $text = t('(без названия)');

          if (!empty($node->description)) {
            $mod .= ' title=\''. mcms_plain($node->description) .'\'';
            $classes[] = 'hint';
          }

          if (null === $this->options['picker'] or $node->class != 'file') {
            $url = "/admin/node/{$node->id}/edit/?destination=". urlencode($_SERVER['REQUEST_URI']);
          } else {
            $url = "/attachment/{$node->id}";
            $classes[] = 'returnHref';
          }

          if (!empty($classes))
            $mod .= ' class=\''. join(' ', $classes) .'\'';

          $text = "<a href='{$url}'{$mod}>{$text}</a>";
          $link = false;
        } elseif ($field == 'email' and null !== $text) {
          $text = "<a href='mailto:{$text}'>{$text}</a>";
        }

        if ($field == 'name')
          $text = $this->getNodePreview($node) . $text;

        $item[$field] = $text;
      }

      if (!empty($this->options['filter']['tags']) and empty($this->options['sort']) and count($nodelist) > 1) {
        $actions = array();

        $actions[] = l(t('поднять'), array($this->getInstanceName() => array('mode' => 'raise', 'nid' => $node->id, 'tid' => $this->options['filter']['tags'][0]), 'destination' => $_SERVER['REQUEST_URI']));
        $actions[] = l(t('опустить'), array($this->getInstanceName() => array('mode' => 'sink', 'nid' => $node->id, 'tid' => $this->options['filter']['tags'][0]), 'destination' => $_SERVER['REQUEST_URI']));

        $item['actions'] = join('&nbsp;', $actions);
      }

      $result['document_list'][$node->id] = $item;
    }
  }

  private function getNodeTree(array &$result)
  {
    $list = &$result['document_list'];
    $user = AuthCore::getInstance()->getUser();

    foreach (Node::find(array('class' => $this->tree, 'parent_id' => null)) as $root) {
      $children = $root->getChildren('flat');

      foreach ($children as $node) {
        if ($this->tree == 'domain' and $node['theme'] == 'admin' and !$user->hasGroup('CMS Developers'))
          continue;

        $item = array(
          'published' => !empty($node['published']),
          'internal' => !empty($node['internal']),
          );

        $link = true;

        foreach ($this->columns as $field => $title) {
          if ($field == 'actions')
            continue;

          if (empty($node[$field]))
            $text = null;
          else
            $text = mcms_plain($node[$field]);

          if ($field == 'code' and is_numeric($text))
            $text = null;

          if ($link) {
            if (empty($text))
              $text = t('(без названия)');

            $mod = empty($node['description']) ? '' : " class='hint' title='". mcms_plain($node['description']) ."'";

            $text = "<a{$mod} href='/admin/node/{$node['id']}/edit/?destination=". urlencode($_SERVER['REQUEST_URI']) ."'>{$text}</a>";
            $link = false;
          }

          if ($field == 'name')
            $text = str_repeat('&nbsp;', 4 * $node['depth']) . $text;

          $item[$field] = $text;
        }

        if (array_key_exists('actions', $this->columns)) {
          $actions = array();

          $uri = urlencode($_SERVER['REQUEST_URI']);

          $actions[] = "<a href='/admin/node/{$node['id']}/raise/?destination={$uri}'>поднять</a>";
          $actions[] = "<a href='/admin/node/{$node['id']}/sink/?destination={$uri}'>опустить</a>";

          if ($this->tree == 'tag')
            $actions[] = "<a href='/admin/node/create/?BebopNode.class=tag&amp;BebopNode.parent={$node['id']}&amp;destination={$uri}'>добавить</a>";

          $item['actions'] = join('&nbsp;', $actions);
        }

        $list[$node['id']] = $item;
      }
    }
  }

  private function getSectionList()
  {
    $list = array();

    foreach (Node::find(array('class' => 'tag', 'parent_id' => null)) as $root) {
      foreach ($root->getChildren('flat') as $node) {
        $list[$node['id']] = str_repeat('&nbsp;', 4 * $node['depth']) . $node['name'];
      }
    }

    return $list;
  }

  public function formProcess($id, array $data)
  {
    $next = null;

    switch ($id) {
    case 'document-list':
      if (empty($data['document_list_mass']) or empty($data['document_list_selected'])) {
        $url = bebop_split_url();

        if (empty($data['document_list_search']))
          $data['document_list_search'] = null;

        $url['args'][$this->getInstanceName()]['search'] = $data['document_list_search'];
        $url['args'][$this->getInstanceName()]['page'] = null;

        if (!$this->sections or empty($data['document_list_search_section']) or !is_numeric($section = $data['document_list_search_section']))
          $section = null;
        $url['args'][$this->getInstanceName()]['section'] = $section;

        $next = bebop_combine_url($url, false);
      }

      else {
        foreach ($data['document_list_mass'] as $op) {
          if (!empty($op)) {
            foreach ($data['document_list_selected'] as $nid) {
              $node = Node::load(array(
                'id' => $nid,
                'deleted' => in_array($op, array('erase', 'undelete')) ? 1 : 0,
                ));

              switch ($op) {
              case 'delete':
                $node->delete();
                break;

              case 'unpublish':
                $node->unpublish();
                break;

              case 'publish':
                $node->publish();
                break;

              case 'clone':
                $node->duplicate();
                break;

              case 'erase':
                $node->erase();
                break;

              case 'undelete':
                $node->undelete();
                break;

              default:
                bebop_debug($op);
                throw new PageNotFoundException();
              }

              BebopCache::getInstance()->flush();
            }

            break;
          }
        }
      }
    }

    return $next;
  }

  private function getNodePreview(Node $node)
  {
    $output = '';

    if ($node->class == 'file') {
      $mod = empty($this->options['picker']) ? '' : " class='returnHref'";

      if (substr($node->filetype, 0, 6) == 'image/') {
        $storage = rtrim(BebopConfig::getInstance()->filestorage, '/') .'/';

        if (file_exists($storage . $node->filepath)) {
          $output = "<a{$mod} title='". mcms_plain(t('Просмотреть в натуральную величину')) ."' href='/attachment/{$node->id}'><img src='/attachment/{$node->id},16,16,cdw' alt='preview' width='16' height='16' class='filepreview' /></a>";
        } else {
          $output = "<img title='". mcms_plain(t('Файл отсутствует в файловой системе')) ."' src='/themes/admin/img/brokenimage.png' alt='broken' width='16' height='16' class='filepreview' />";
        }
      } else {
        $output = "<a href='/attachment/{$node->id}'><img src='/themes/all/img/media-floppy.png' alt='download' width='16' height='16' class='filepreview' /></a>";
      }
    }

    return $output;
  }

  private function resolveUid($uid)
  {
    static $cache = array();

    if (!array_key_exists($uid, $cache)) {
      try {
        $user = Node::load($uid);
      } catch (ObjectNotFoundException $e) {
        $user = null;
      }
      $cache[$uid] = $user;
    }

    if (null === ($user = $cache[$uid]))
      return null;

    $text = $user->login;

    if (AuthCore::getInstance()->getUser()->hasGroup('User Managers'))
      $text = "<a href='/admin/node/{$user->id}/edit/?destination=". urlencode($_SERVER['REQUEST_URI']) ."' class='hint' title='". mcms_plain($user->name) ."'>{$text}</a>";

    return $text;
  }

  private function resolveType($type)
  {
    $schema = TypeNode::getSchema($type);

    if (empty($schema['id']))
      return null;

    $text = $schema['title'];

    if (AuthCore::getInstance()->getUser()->hasGroup('Schema Managers')) {
      $mod = empty($schema['description']) ? '' : " class='hint' title='". mcms_plain($schema['description']) ."'";
      $text = "<a{$mod} href='/admin/node/{$schema['id']}/edit/?destination=". urlencode($_SERVER['REQUEST_URI']) ."'>{$text}</a>";
    }

    return $text;
  }
};
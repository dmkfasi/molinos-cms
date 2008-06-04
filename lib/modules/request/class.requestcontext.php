<?php
// vim: expandtab tabstop=2 shiftwidth=2 softtabstop=2:

// Контекст, в котором выполняется запрос.  Содержит всю параметризацию.
class RequestContext
{
  // Элементы пути, ведущие к текущей странице.
  private $ppath;

  // Дополнительные элементы пути.
  private $apath;

  // Относящиеся к виджету параметры и файлы.
  private $get = null;
  private $post = null;

  // Текущий раздел и документ.
  private $section = null;
  private $document = null;

  // Основной раздел для страницы.
  private $root = null;

  // Шкура текущей страницы.
  private $theme = null;

  // Сохраняем глобальный контекст.
  private static $global = null;

  // POST data.
  private static $postdata = null;

  // Запрещаем создавать объекты напрямую.
  private function __construct()
  {
  }

  // Разгребаем параметры, подставляя текущие значения из пути
  // в локальные свойства $section и $document.
  private function setObjectIds(Node $page)
  {
    switch ($page->params) {
    case 'sec+doc':
      $this->document = empty($this->apath[1]) ? null : $this->apath[1];

    case 'sec':
      if (!empty($page->defaultsection) and is_numeric($page->defaultsection))
        $this->root = $page->defaultsection;

      if (null === ($this->section = empty($this->apath[0]) ? null : $this->apath[0]))
        $this->section = $this->root;

      if (count($this->apath) > 1)
        throw new PageNotFoundException();

      break;

    case 'doc':
      $this->document = empty($this->apath[0]) ? null : $this->apath[0];

      if (count($this->apath) > 1)
        throw new PageNotFoundException();

      break;

    default:
      if (!empty($this->apath))
        throw new PageNotFoundException();
    }

    if (null === $this->root and is_numeric($page->defaultsection)) {
      if (null === $this->section)
        $this->root = $this->section = $page->defaultsection;
    }

    // mcms::debug($this, $page);

    // Нормализуем идентификаторы.

    if (null !== $this->document and !is_numeric($this->document))
      if (null === ($this->document = mcms::db()->getResult("SELECT `id` FROM `node` WHERE `code` = :code", array(':code' => $this->document))))
          throw new PageNotFoundException();

    if (null !== $this->section and !is_numeric($this->section))
      if (null === ($this->section = mcms::db()->getResult("SELECT `id` FROM `node` WHERE `code` = :code", array(':code' => $this->section))))
        throw new PageNotFoundException();
  }

  // Запрещаем изменять свойства извне.
  public function __set($key, $value)
  {
    throw new InvalidArgumentException("RequestContext is a read-only object.");
  }

  public function __isset($key)
  {
    switch ($key) {
    case 'ppath':
    case 'apath':
    case 'get':
    case 'post':
    case 'root':
    case 'section':
    case 'document':
    case 'theme':
      return isset($this->$key);

    case 'section_id':
      return !empty($this->section);

    case 'document':
      return !empty($this->document);

    default:
      return false;
    }
  }

  public function __get($key)
  {
    switch ($key) {
      case 'ppath':
      case 'apath':
      case 'get':
        if ($this->$key === null)
          return array();
        return $this->$key;

      case 'post':
        return self::getPostData();

      case 'theme':
        return $this->theme;

      case 'root':
        return empty($this->root) ? null : $this->root;

      case 'section':
      case 'document':
        $node = &$this->$key;

        if ($node === null)
          return null;
        elseif (is_object($node))
          return $this->$key;
        else {
          $tmp = Node::load($node);

          if ($tmp->class == 'tag' and $key != 'section')
            return null;

          return $tmp;
        }

      case 'section_id':
        return $this->section;

      case 'document_id':
        return $this->document;

      default:
        throw new InvalidArgumentException("Unknown key for RequestContext: ". $key);
    }
  }

  // Упрощённое обращение к параметрам $_GET.
  public function get($key, $default = null)
  {
    if ($this->get !== null and array_key_exists($key, $this->get))
      return $this->get[$key];
    return $default;
  }

  // Упрощённое обращение к параметрам $_POST.
  public function post($key, $default = null)
  {
    $data = self::getPostData();

    if (array_key_exists($key, $data))
      return $data[$key];
    return $default;
  }

  // Формирует глобальный контекст.
  public static function setGlobal(array $ppath = null, array $apath = null, Node $page = null)
  {
    if (self::$global !== null)
      throw new InvalidArgumentException("There is a global request context already.");

    $ctx = new RequestContext();

    $ctx->ppath = $ppath;
    $ctx->apath = $apath;

    if (null !== $page) {
      $ctx->theme = $page->theme;
      $ctx->setObjectIds($page);
    }

    return self::$global = $ctx;
  }

  // Возвращает текущий контекст.
  public static function getGlobal()
  {
    if (self::$global === null)
      throw new InvalidArgumentException("There is no global request context yet.");

    return self::$global;
  }

  // Формирует контекст для виджета, из глобального.
  public static function getWidget(array $get, array $post = null)
  {
    if (null === self::$global)
      $ctx = new RequestContext();
    else
      $ctx = clone(self::getGlobal());

    $ctx->get = $get;
    $ctx->post = $post;

    return $ctx;
  }

  private static function getPostData()
  {
    if ('POST' != $_SERVER['REQUEST_METHOD'])
      throw new InvalidArgumentException(t('POST data is only available during POST requests.'));

    if (null === self::$postdata) {
      self::$postdata = $_POST;
      self::getFiles(self::$postdata);
      self::remapControls(self::$postdata);
    }

    return self::$postdata;
  }

  public static function getFiles(array &$data)
  {
    foreach ($_FILES as $field => $fileinfo) {
      if (is_array($fileinfo['name'])) {
        foreach (array_keys($fileinfo) as $key) {
          // FIXME: быстрый хак для работы плагина на jQuery.  Судя по документации,
          // обращение к файлам действительно должно быть таким идиотским:
          //   http://docs.php.net/manual/en/features.file-upload.multiple.php
          //   $_FILES['userfile']['name'][0]
          // При загрузке в двойной массив (file[name][]) — ещё глубже.
          // Как бы это по-красивее пропарсить?
          if (array_key_exists('__bebop', $tmp = $fileinfo[$key])) {
            $tmp = $tmp['__bebop'];
            $prefix = '__bebop';
          } else {
            $prefix = '';
          }

          foreach ($tmp as $k => $v) {
            $data[$field][$prefix . $k][$key] = $v;
          }
        }
      }

      else {
        foreach ($fileinfo as $k => $v)
          $data[$field][$k] = $v;
      }
    }
  }

  private static function remapControls(array &$data)
  {
    if (array_key_exists('nodelink_remap', $data)) {
      if (is_array($data['nodelink_remap'])) {
        foreach ($data['nodelink_remap'] as $k => $v) {
          if (substr($k, 0, 13) != 'node_content_')
            continue;

          $value = null;

          // Определяем обязательность поля.
          if (substr($v, -1) != '!') {
            $required = false;
          } else {
            $required = true;
            $v = substr($v, 0, -1);
          }

          if (!empty($data[$k]) or $required) {
            if (count($parts = explode('.', $v)) == 2) {
              try {
                if (count($node = array_values(Node::find(array('class' => $parts[0], $parts[1] => $data[$k]), 1))))
                  $value = intval($node[0]->id);
              } catch (ObjectNotFoundException $e) {
              }
            }
          }

          if ((null === $value) and $required)
            throw new ValidationException(substr($k, 13));

          $data[$k] = $value;
        }
      }

      unset($data['nodelink_remap']);
    }
  }
};

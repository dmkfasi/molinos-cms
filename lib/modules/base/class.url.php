<?php
/**
 * Работа с урлами.
 *
 * @package mod_base
 * @subpackage Core
 * @author Justin Forest <justin.forest@gmail.com>
 * @copyright 2006-2008 Molinos.RU
 * @license http://www.gnu.org/copyleft/gpl.html GPL
 */

/**
 * Работа с урлами.
 *
 * @package mod_base
 * @subpackage Core
 */
class url
{
  private static $clean = null;
  private static $localhost = null;
  private static $root = null;

  private $readonly;

  private $scheme = null;
  private $user = null;
  private $pass = null;
  private $host = null;
  private $path = null;
  private $args = null;
  private $fragment = null;
  private $islocal = null;

  /**
   * Разбор урла.
   *
   * @param mixed $source строка, url или массив (см. as_array()).
   */
  public function __construct($source = null, $readonly = false)
  {
    self::init();

    if ($source instanceof url)
      $this->fromArray($source->as_array());
    elseif (is_array($source))
      $this->fromArray($source);
    elseif (is_string($source))
      $this->fromString($source);
    elseif (!empty($_SERVER['QUERY_STRING']))
      $this->fromString('?'. $_SERVER['QUERY_STRING']);
    else
      $this->fromArray(array());

    if ($this->islocal and $this->path != trim($this->path, '/'))
      $this->path = trim($this->path, '/');

    $this->readonly = $readonly;
  }

  /**
   * Формирование строкового урла.
   *
   * Локальные ссылки оптимизируются, внешние ссылки возвращаются в полной форме
   * (со схемой, хостом итд).
   *
   * @return string Урл в строковой форме.
   */
  public function __toString()
  {
    if ('mailto' == $this->scheme)
      return 'mailto:'. urlencode($this->path);

    $result = '';

    if (!empty($this->scheme) or !empty($this->host)) {
      $scheme = empty($this->scheme) ? 'http' : $this->scheme;
      $result .= $scheme .'://';
    }

    if (!empty($this->user)) {
      $result .= urlencode($this->user);
      if (!empty($this->pass))
        $result .= ':'. urlencode($this->pass);
      $result .= '@';
    }

    if (!empty($this->host))
      $result .= $this->host;

    if (!empty($this->path) and (!$this->islocal or is_readable($this->path) or self::$clean))
      $result .= $this->path;

    $result .= $this->getArgsAsString();

    if (!empty($this->fragment))
      $result .= '#'. $this->fragment;

    return $result;
  }

  /**
   * Получение сведений об урле.
   *
   * Метод устарел.
   *
   * @return array Свойства урла: scheme, host, path, args, fragment.
   */
  public function as_array()
  {
    return array(
      'scheme' => $this->scheme,
      'host' => $this->host,
      'path' => $this->path,
      'args' => $this->args,
      'fragment' => $this->fragment,
      );
  }

  /**
   * Чтение свойства урла.
   *
   * При обращении к несуществующему свойству возникает
   * InvalidArgumentException().
   *
   * @param string $key имя свойства (path, scheme, ...).
   *
   * @return mixed значение.
   */
  public function __get($key)
  {
    switch ($key) {
    case 'path':
    case 'scheme':
    case 'host':
    case 'path':
    case 'args':
    case 'fragment':
    case 'islocal':
      return $this->$key;
    default:
      throw new InvalidArgumentException(t('Свойство '. $key
        .' у ссылки отсутствует.'));
    }
  }

  private function __isset($key)
  {
    return null !== $this->$key;
  }

  /**
   * Изменение свойства урла.
   *
   * При попытке изменить несуществующее свойство возникает исключение
   * InvalidArgumentException.
   *
   * @param string $key имя свойства.
   *
   * @param mixed $val значение.
   *
   * @return void
   */
  public function __set($key, $val)
  {
    if ($this->readonly)
      throw new RuntimeException(t('Этот URL модифицировать нельзя.'));

    switch ($key) {
    case 'args':
      throw new InvalidArgumentException(t('Для изменения аргументов '
        .'ссылки используйте метод setarg().'));
    case 'path':
    case 'scheme':
    case 'host':
    case 'path':
    case 'fragment':
      $this->$key = $val;
      break;
    default:
      throw new InvalidArgumentException(t('Свойство '. $key
        .' у ссылки отсутствует.'));
    }
  }

  /**
   * Изменение GET-аргументов ссылки.
   *
   * @param string $key имя аргумента.
   *
   * @param string $value значение.
   */
  public function setarg($key, $value)
  {
    if ($this->readonly)
      throw new RuntimeException(t('Trying to modify an immutable URL.'));

    if (false === strpos($key, '.'))
      $this->args[$key] = $value;
    else {
      list($a, $b) = explode('.', $key, 2);
      $this->args[$a][$b] = $value;
    }
  }

  public function arg($key, $default = null)
  {
    if (null !== $this->args and array_key_exists($key, $this->args))
      return $this->args[$key];
    return $default;
  }

  /**
   * Получение текущего пути.
   *
   * @return string Путь к инсталляции CMS относительно корня сайта.  При
   * установке в корень сайта возвращается "" (пустая строка).
   */
  public static function path()
  {
    if (null === self::$root)
      self::init();
    return self::$root;
  }

  private static function init()
  {
    if (null === self::$clean) {
      self::$clean = mcms::config('cleanurls');
      self::$localhost = empty($_SERVER['HTTP_HOST'])
        ? 'example.com'
        : $_SERVER['HTTP_HOST'];

      if (empty($_SERVER['REMOTE_ADDR']))
        self::$root = '';
      else
        self::$root = mcms::path();
    }
  }

  private function fromString($source)
  {
    // Функция не любит неэкранированные слэши в параметрах,
    // зато их любят некоторые OpenID провайдеры.
    $parts = explode('?', $source, 2);
    if (count($parts) > 1)
      $parts[1] = str_replace('/', '%2F', $parts[1]);
    $source = join('?', $parts);

    $url = parse_url($source);

    if (!is_array($url)) {
      mcms::debug('Could not parse this URL.', $source);
    } else {
      // Парсим дополнительные параметры.
      if (array_key_exists('query', $url)) {
        $url['args'] = $this->parse_request_args($url['query']);
        unset($url['query']);
      }

      // Дальше работаем как с массивом.
      $this->fromArray($url);
    }
  }

  private function parse_request_args($string)
  {
    $res = $keys = array();

    foreach (explode('&', $string) as $element) {
      $parts = explode('=', $element, 2);

      $k = $parts[0];
      if (count($parts) > 1)
        $v = $parts[1];
      else
        $v = '';

      // Упрощаем жизнь парсеру, удаляя пустые ключи.
      if ($v == '')
        continue;

      // Заворачиваем начальные конструкции: "group.key"
      $k = preg_replace('/^([a-z0-9_]+)\.([a-z0-9_]+)/i', '\1%5B\2%5D', $k);

      // Заменяем все остальные точки на ][, т.к. они будут находиться внутри массива.
      // $k = str_replace('.', '%5D%5B', $k);

      $keys[] = $k .'='. $v;
    }

    parse_str(join('&', $keys), $res);
    return $res;
  }

  private function fromArray(array $url)
  {
    // Применяем некоторые дефолты.
    $url = $this->complement($url);

    if (!empty($url['path']) and mcms::path()) {
      $prefixpos = strpos($url['path'], mcms::path());
    } else {
      $prefixpos = null;
    }

    if (empty($url['host'])  or (self::$localhost == $url['host'])) {
      if (substr($url['path'], 0, 1) != '/')
        $this->islocal = true;
      elseif ($prefixpos === 0)
        $this->islocal = true;
    }

    if (($this->islocal) && ($prefixpos === 0))
      $url['path'] = ltrim(substr_replace($url['path'], "", 0, strlen(mcms::path())),'/');


    // Для локальных ссылок предпочитаем q реальному пути.
    if ($this->islocal) {
      if (array_key_exists('q', $url['args'])) {
        $url['path'] = trim($url['args']['q'], '/');
        unset($url['args']['q']);
      }

      // Если CMS установлена в папку (/cms/), при парсинге урлов надо удалять
      // эту папку из пути, чтобы /cms/index.php превращалось в /index.php, итд.
      //
      // Без этого возникают проблемы если, например, мы пытаемся вернуться по
      // адресу, указанному в $_GET['destination'] — %2Fcms%2Findex.php.  Если
      // путь к CMS не вырезать, он продублируется (получится /cms/cms/index.php).
      //
      // @todo поправить парсер так, чтобы он корректно обрабатывал относительные
      // и абсолютные урлы (не пытался придавлять self::path() к тому, что начинается
      // со слэша), но тогда возникнет другая проблема: в коде надо будет очень
      // внимательно формировать ссылки: l(/compress.rpc) будет работать на большинстве
      // инсталляций — с поддержкой mod_rewrite и с установкой в корень, но в остальных
      // ситуациях будут возникать неожиданные проблемы.

      if (substr($url['path'], 1, strlen(self::path())) == self::path())
        $url['path'] = substr($url['path'], strlen(self::path()));
    }

    foreach (array('scheme', 'user', 'pass', 'host', 'path', 'args', 'fragment') as $key)
      if (array_key_exists($key, $url))
        $this->$key = $url[$key];
  }

  private function complement(array $url)
  {
    return array_merge(array(
      'scheme' => null,
      'host' => null,
      'path' => null,
      'args' => array(),
      'fragment' => null,
      ), $url);
  }

  /**
   * Получение параметров в виде строки.
   *
   * Переводит массив args в строку.  При отсутствии поддержки чистых урлов
   * (mod_rewrite) использует форму "index.php?q=", иначе — чистую форму.  Ключи
   * "nocache", "widget", "__cleanurls" и "__rootpath" не возвращаются.
   *
   * @return string Строка с параметрами, включая начальный "?".  В качестве
   * разделителя используется "&" — может понадобиться экранирование.
   */
  public function getArgsAsString()
  {
    $result = '';

    $args = $this->args;

    if ($this->islocal and !is_readable($this->path)) {
      if (self::$clean or 'index.php' == $this->path)
        $args['q'] = null;
      else
        $args['q'] = trim($this->path, '/');
    }

    if (!empty($args)) {
      $forbidden = array('nocache', 'widget', '__cleanurls', '__rootpath');

      $pairs = array();

      foreach ($args as $k => $v) {
        if ($v === null)
          continue;

        elseif (is_array($v)) {
          foreach ($v as $argname => $argval) {
            $prefix = $k .'.'. $argname;

            if (is_array($argval)) {
              foreach ($argval as $k1 => $v1) {
                if (is_numeric($k1))
                  $pairs[] = $prefix .'[]='. urlencode($v1);
                elseif (is_array($v1))
                  ;
                else
                  $pairs[] = "{$prefix}[{$k1}]=". urlencode($v1);
              }
            }

            elseif (null !== $argval and '' !== $argval) {
              $pairs[] = $prefix .'='. urlencode($argval);
            }
          }
        }

        elseif ($v !== '' and !in_array($k, $forbidden)) {
          if ('destination' === $k and 'CURRENT' === $v) {
            $pairs[] = $k .'='. urlencode($_SERVER['REQUEST_URI']);
          } else {
            $pairs[] = $k .'='. urlencode($v);
          }
        }
      }

      if (!empty($pairs))
        $result = '?'. join('&', $pairs);
    }

    return $result;
  }

  /**
   * Одурачивание системы распознавания mod_rewrite, для тестов.
   */
  public function __setclean($value)
  {
    self::$clean = empty($value) ? false : true;
  }

  /**
   * Формирование абсолютной ссылки.
   *
   * Функция используется для получения полного URL, пригодного для
   * использования в перенаправлениях (mcms::redirect()), где с локальными
   * ссылками возникают трудности.
   *
   * @todo Заполнять недостающие части нужно при парсинге урлов, тогда для
   * получения абсолютной версии достаточно будет strval($url).  Кроме того, это
   * будет логически правильнее.
   *
   * @param RequestContext $ctx контекст, в котором существуют локальные ссылки.
   * Используется, в основном, при написании тестов.
   *
   * @return string абсолютная ссылка.
   */
  public function getAbsolute()
  {
    $result = '';

    if (!$this->islocal) {
      $result .= $this->scheme
        ? $this->scheme : 'http';

      $result .= '://';

      if (!empty($this->host))
        $result .= $this->host;
      elseif (!empty($_SERVER['HTTP_HOST']))
        $result .= $_SERVER['HTTP_HOST'];
      else
        $result .= 'localhost';
    }

    $result .= mcms::path() .'/';

    if (self::$clean or !$this->islocal)
      $result .= ltrim($this->path, '/');
    elseif (!empty($_SERVER['SCRIPT_FILENAME']))
      $result .= basename($_SERVER['SCRIPT_FILENAME']);
    else
      $result .= 'index.php';

    $result .= $this->getArgsAsString();

    return $result;
  }
}

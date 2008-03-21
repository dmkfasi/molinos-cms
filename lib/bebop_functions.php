<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:

function bebop_redirect($path, $status = 301)
{
    if (is_array($path))
      $path = bebop_combine_url($path, false);

    if ($_SERVER['REQUEST_METHOD'] == 'POST')
      $status = 303;

    if (!in_array($status, array('301', '302', '303', '307')))
      throw new Exception("Статус перенаправления {$status} не определён в стандарте HTTP/1.1");

    bebop_session_end();
    mcms::db()->commit();
    mcms::flush(mcms::FLUSH_NOW);

    if (substr($path, 0, 1) == '/') {
      $proto = 'http'.((array_key_exists('HTTPS', $_SERVER) and $_SERVER['HTTPS'] == 'on') ? 's' : '');
      $domain = $_SERVER['HTTP_HOST'];
      $path = $proto.'://'.$domain.$path;
    }

    // Если нас вызвали через AJAX, просто возвращаем адрес редиректа.
    if (!empty($_POST['ajax']))
      exit($path);

    header('Location: '. $path);
    exit();
}

function bebop_mail($from, $to, $subject, $body, array $attachments = null, array $headers = null)
{
  return BebopMimeMail::send($from, $to, $subject, $body, $attachments, $headers);
}

// Проверяет, является ли пользователь отладчиком.
function bebop_is_debugger()
{
  static $skip = false;

  if ($skip === false) {
    if (empty($_SESSION)) {
      bebop_session_start();
      bebop_session_end();
    }

    if (empty($_SERVER['REQUEST_METHOD'])) {
      $skip = false;
    }

    elseif (!empty($_SESSION['user']['systemgroups']) and in_array('Developers', $_SESSION['user']['systemgroups']))
      $skip = false;

    else {
      $tmp = mcms::config('debuggers');

      if (empty($tmp))
        $skip = true;
      elseif (!in_array($_SERVER['REMOTE_ADDR'], $list = preg_split('/[, ]+/', $tmp)))
        $skip = true;
    }
  }

  return !$skip;
}

function bebop_skip_checks()
{
  if ($_SERVER['SCRIPT_NAME'] == '/install.php')
    return true;
  return false;
}

// Выводит содержимое параметров и стэк вызова, если пользователь является
// отладчиком (ip в конфиге) или состоит в группе Developers.
function bebop_debug()
{
  if (bebop_is_debugger()) {
    $output = array();

    foreach (func_get_args() as $arg) {
      $output[] = var_export($arg, true);
    }

    bebop_on_json(array('args' => $output));

    ob_end_clean();

    if (!empty($_SERVER['REQUEST_METHOD']))
      header("Content-Type: text/plain; charset=utf-8");

    print join(";\n\n", $output) .";\n\n";

    if (!empty($_SERVER['REMOTE_ADDR'])) {
      print "--- backtrace ---\n";
      debug_print_backtrace();
    }

    die();
  }
}

// Разбивает текущий запрос на составляющие.
function bebop_split_url($url = null)
{
  if ($url === null)
    $url = $_SERVER['REQUEST_URI'];

  $tmp = parse_url($url);

  if (array_key_exists('query', $tmp)) {
    $tmp['args'] = parse_request_args($tmp['query']);
    unset($tmp['query']);
  } else {
    $tmp['args'] = array();
  }

  return $tmp;
}

function parse_request_args($string)
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

// Заворачивает результат работы предыдущей функции обратно.
function bebop_combine_url(array $url, $escape = true)
{
  $result = '';

  $forbidden = array('nocache', 'flush');

  if (bebop_is_json())
    $forbidden[] = 'widget';

  // Если текущий хост отличается от нужного -- делаем абсолютную ссылку.
  if (!empty($url['host']) and ($_SERVER['HTTP_HOST'] != $url['host'] or !empty($url['#absolute']) or in_array('absolute', $url['args'])))
    $result .= 'http://'. $url['host'];

  if (strstr($url['path'], '#') !== false) {
    $parts = explode('#', $url['path']);
    $url['path'] = $parts[0];
    $url['anchor'] = $parts[1];
  }

  $result .= $url['path'];

  if (!empty($url['args'])) {
    $pairs = array();

    ksort($url['args']);

    foreach ($url['args'] as $k => $v) {
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

      elseif ($v !== '' and !in_array($k, $forbidden))
        $pairs[] = $k .'='. urlencode($v);
    }

    if (!empty($pairs))
      $result .= '?'. join('&', $pairs);
  }

  if ($escape)
    $result = mcms_plain($result);

  if (!empty($url['anchor']))
    $result .= '#'. $url['anchor'];

  return $result;
}

// Возвращает отформатированную ссылку.
function l($title, array $args, array $options = null)
{
  $url = bebop_split_url();
  $url['args'] = array_merge($url['args'], $args);

  foreach (array('flush', 'nocache') as $k)
    if (array_key_exists($k, $url['args']))
      unset($url['args'][$k]);

  $mod = '';

  if (!empty($options['class']))
    $mod .= " class='{$options['class']}'";
  if (!empty($options['title']))
    $mod .= " title='". mcms_plain($options['title']) ."'";
  if (!empty($options['id']))
    $mod .= " id='{$options['id']}'";

  if ($title === null)
    return bebop_combine_url($url, false);
  else
    return "<a href='". bebop_combine_url($url, true) ."'{$mod}>{$title}</a>";
}

// Формирует дерево из связки по parent_id.
function bebop_make_tree($data, $id, $parent_id, $children = 'children')
{
  // Здесь будем хранить ссылки на все элементы списка.
  $map = array();

  // Здесь будет идентификатор корневого объекта.
  $root = null;

  // Перебираем все данные.
  foreach ($data as $k => $row) {
    // Запоминаем корень.
    if ($root === null)
      $root = intval($row[$id]);

    // Родитель есть, добавляем к нему.
    if (array_key_exists($row[$parent_id], $map))
        $map[$row[$parent_id]][$children][] = &$data[$k];

    // Добавляем все элементы в список.
    $map[$row[$id]] = &$data[$k];
  }

  // Возвращаем результат.
  return (array)@$map[$root];
}

function t($message, array $argv = array())
{
  /*
  // TODO lang detection
  $lang = 'ru';

  static $sth = null;

  if (null == $sth)
    $sth = mcms::db()->prepare("SELECT m2.* FROM `node__messages` m1 LEFT JOIN `node__messages` m2 ON m1.id = m2.id WHERE m2.lang = :lang AND m1.message = :message");

  $sth->execute(array(
    ':lang' => $lang,
    ':message' => $message,
  ));

  $result = $sth->fetchColumn(2);

  if (false !== $result)
    $message = str_replace($message, $result, $message);
  */

  foreach ($argv as $k => $v) {
    switch (substr($k, 0, 1)) {
    case '!':
    case '%':
      $message = str_replace($k, $v, $message);
      break;
    case '@':
      $message = str_replace($k, mcms_plain($v), $message);
      break;
    }
  }

  return $message;
}

function bebop_is_json()
{
  return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) and $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
}

// Возвращает массив в виде JSON.
function bebop_on_json(array $result)
{
  if (bebop_is_json()) {
    mcms::db()->commit();
    mcms::flush(mcms::FLUSH_NOW);

    setlocale(LC_ALL, "en_US.UTF-8");

    $output = json_encode($result);
    header('Content-Type: application/x-json');
    header('Content-Length: '. strlen($output));
    die($output);
  }
}

// Применяет шаблон к данным.
function bebop_render_object($type, $name, $theme = null, $data)
{
  $__root = $_SERVER['DOCUMENT_ROOT'];

  if (null === $theme) {
    $ctx = RequestContext::getGlobal();
    $theme = $ctx->theme;
  }

  if ($data instanceof Exception) {
    $data = array('error' => array(
      'code' => $data->getCode(),
      'class' => get_class($data),
      'message' => $data->getMessage(),
      'description' => $data->getMessage(),
      ));
  } elseif (!is_array($data)) {
    $data = array($data);
  }

  // Варианты шаблонов для этого объекта.
  $__options = array(
    "themes/{$theme}/templates/{$type}.{$name}.tpl",
    "themes/{$theme}/templates/{$type}.{$name}.php",
    "themes/{$theme}/templates/{$type}.default.tpl",
    "themes/{$theme}/templates/{$type}.default.php",
    "themes/all/templates/{$type}.{$name}.tpl",
    "themes/all/templates/{$type}.{$name}.php",
    "themes/all/templates/{$type}.default.tpl",
    "themes/all/templates/{$type}.default.php",
    );

  foreach ($__options as $__filename) {
    if (file_exists($__fullpath = $__root .'/'. $__filename)) {
      $data['prefix'] = '/'. dirname(dirname($__filename));

      ob_start();

      if (substr($__filename, -4) == '.tpl') {
        $__smarty = new BebopSmarty($type == 'page');
        $__smarty->template_dir = ($__dir = dirname($__fullpath));

        if (is_dir($__dir .'/plugins')) {
          $__plugins = $__smarty->plugins_dir;
          $__plugins[] = $__dir .'/plugins';
          $__smarty->plugins_dir = $__plugins;
        }

        foreach ($data as $k => $v)
          $__smarty->assign($k, $v);

        error_reporting(($old = error_reporting()) & ~E_NOTICE);

        $compile_id = md5($__fullpath);
        $__smarty->display($__fullpath, $compile_id, $compile_id);

        error_reporting($old);
      }

      elseif (substr($__filename, -4) == '.php') {
        extract($data, EXTR_SKIP);
        include($__fullpath);
      }

      $output = ob_get_clean();
      return trim($output);
    }
  }
}

// Определяет тип файла.
function bebop_get_file_type($filename, $realname = null)
{
  $result = 'application/octet-stream';

  if (function_exists('mime_content_type')) {
    $result = mime_content_type($filename);
  }

  elseif (function_exists('finfo_open')) {
    if (false !== ($r = finfo_open(FILEINFO_MIME))) {
      $result = finfo_file($r, $filename);
      $result = str_replace(strrchr($result, ';'), '', $result);
      finfo_close($r);
    }
  }

  if (isset($realname) and ('application/octet-stream' == $result)) {
    switch (strrchr($realname, '.')) {
    case '.ttf':
      $result = 'application/x-font-ttf';
      break;
    }
  }

  return $result;
}

static $bebop_session_status = false;

function bebop_session_start($check = false)
{
  global $bebop_session_status;

  if (!$check and !$bebop_session_status) {
    session_start();
    $bebop_session_status = true;
  }

  return $bebop_session_status;
}

function bebop_session_end()
{
  global $bebop_session_status;

  if ($bebop_session_status) {
    session_write_close();
    $bebop_session_status = false;
  }
}

function mcms_fetch_file($url, $content = true, $cache = true)
{
  $outfile = mcms::config('tmpdir') . "/mcms-fetch.". md5($url);

  // Проверяем, не вышло ли время хранения файла на диске, если истекло - удаляем файл.
  // Если время жизни кэша не определено в конфигурации, принимаем его за астрономический один час.
  if (null === ($ttl = mcms::config('file_cache_ttl')))
    $ttl = 60 * 60;

  if (file_exists($outfile) and (!$cache or ((time() - $ttl) > @filectime($outfile))))
    if (is_writable(dirname($outfile)))
      unlink($outfile);

  // Скачиваем файл только если его нет на диске во временной директории
  if (!file_exists($outfile)) {
    $ch = curl_init($url);
    $fp = fopen($outfile, "w+");

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Molinos.CMS/' . BEBOP_VERSION . '; http://' . mcms::config('basedomain') . '/');

    if (!ini_get('safe_mode'))
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    fclose($fp);

    if (200 != $code) {
      unlink($outfile);
      return null;
    }
  }

  if ($content) {
    $content = file_get_contents($outfile);
    return $content;
  } else {
    return $outfile;
  }
}

function mcms_ctlname($name)
{
  if (substr($name, 0, 4) == 'Type')
    return substr($name, 4) .'Control';
  return $name;
}

function mcms_plain($text, $strip = true)
{
  if ($strip)
    $text = strip_tags($text);
  return str_replace(array('&amp;quot;'), array('&quot;'), htmlspecialchars($text, ENT_QUOTES));
}

function mcms_cut($text, $length)
{
  if (mb_strlen($text) > $length)
    $text = mb_substr(trim($text), 0, $length) .'...';
  return $text;
}

function mcms_url(array $options = null)
{
  $url = array_merge(bebop_split_url(), $options);
  return bebop_combine_url($url, false);
}

function mcms_encrypt($input)
{
    $textkey = mcms::config('guid');
    $securekey = hash('sha256', $textkey, true);

    $iv = mcrypt_create_iv(32);

    return rawurlencode(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $securekey, $input, MCRYPT_MODE_ECB, $iv)));
}

function mcms_decrypt($input)
{
    $textkey = mcms::config('guid');
    $securekey = hash('sha256', $textkey, true);

    $iv = mcrypt_create_iv(32);

    return trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $securekey, base64_decode(rawurldecode($input)), MCRYPT_MODE_ECB, $iv));
}

class mcms
{
  const MEDIA_AUDIO = 1;
  const MEDIA_VIDEO = 2;
  const MEDIA_IMAGE = 4;

  const FLUSH_NOW = 1;

  public static function html($name, array $parts = null, $content = null)
  {
    $output = '<'. $name;

    if (null !== $parts) {
      foreach ($parts as $k => $v) {
        if (!empty($v)) {
          if (is_array($v))
            if ($k == 'class')
              $v = join(' ', $v);
            else {
              // bebop_debug("Trying to assign this to <{$name} {$k}= />", $v, $parts, $content);
              // throw new InvalidArgumentException(t("Свойство {$k} элемента HTML {$name} не может быть массивом."));
              $v = null;
            }

          $output .= ' '.$k.'=\''. mcms_plain($v, false) .'\'';
        } elseif ($k == 'value') {
          $output .= " value=''";
        }
      }
    }

    if (null === $content and !in_array($name, array('a', 'script', 'div', 'textarea', 'span'))) {
      $output .= ' />';
    } else {
      $output .= '>'. $content .'</'. $name .'>';
    }

    return $output;
  }

  public static function mediaGetPlayer(array $files, $types = null, array $custom_options = array())
  {
    $nodes = array();
    $havetypes = 0;

    if (null === $types)
      $types = self::MEDIA_AUDIO | self::MEDIA_VIDEO;

    foreach ($files as $k => $v) {
      switch ($v['filetype']) {
      case 'audio/mpeg':
        if ($types & self::MEDIA_AUDIO) {
          $nodes[] = $v['id'];
          $havetypes |= self::MEDIA_AUDIO;
        }
        break;
      case 'video/flv':
      case 'video/x-flv':
        if ($types & self::MEDIA_VIDEO) {
          $nodes[] = $v['id'];
          $havetypes |= self::MEDIA_VIDEO;
        }
        break;
      }
    }

    // Подходящих файлов нет, выходим.
    if (empty($nodes))
      return null;

    // Параметризация проигрывателя.
    $options = array_merge(array(
      'file' => 'http://'. $_SERVER['HTTP_HOST'] .'/playlist.rpc?nodes='. join(',', $nodes),
      'showdigits' => 'true',
      'autostart' => 'false',
      'repeat' => 'true',
      'shuffle' => 'false',
      'width' => 350,
      'height' => 100,
      'showdownload' => 'false',
      'displayheight' => 0,
      ), $custom_options);

    if ($havetypes & self::MEDIA_VIDEO) {
      $dheight = ($options['width'] / 4) * 3;
      $options['displayheight'] = $dheight;

      if (count($nodes) < 2)
        $options['height'] = 0;

      $options['height'] += $dheight;
    }

    $args = array();

    foreach ($options as $k => $v)
      $args[] = $k .'='. urlencode($v);

    $url = 'http://'. $_SERVER['HTTP_HOST'] .'/themes/all/flash/player.swf?'. join('&', $args);

    $params = mcms::html('param', array(
      'name' => 'movie',
      'value' => $url,
      ));
    $params .= mcms::html('param', array(
      'name' => 'wmode',
      'value' => 'transparent',
      ));

    return mcms::html('object', array(
      'type' => 'application/x-shockwave-flash',
      'data' => $url,
      'width' => $options['width'],
      'height' => $options['height'],
      ), $params);
  }

  public static function cache()
  {
    $result = null;

    if (null === ($cache = BebopCache::getInstance()))
      return $result;

    $args = func_get_args();

    switch (count($args)) {
    case 1:
      $result = $cache->$args[0];
      break;
    case 2:
      $cache->$args[0] = $args[1];
      break;
    }

    return $result;
  }

  public static function config($key)
  {
    if (!class_exists('BebopConfig'))
      die(debug_print_backtrace());
    return BebopConfig::getInstance()->$key;
  }

  public static function modconf($modulename, $key = null)
  {
    static $cache = array();

    if (!array_key_exists($modulename, $cache)) {
      $data = array();
      $ckey = 'moduleinfo:'. $modulename;

      if (is_array($tmp = mcms::cache($ckey)))
        $data = $tmp;
      else {
        try {
          $node = Node::load(array('class' => 'moduleinfo', 'name' => $modulename));

          if (is_array($tmp = $node->config)) {
            mcms::cache($ckey, $data = $tmp);
          }
        } catch (ObjectNotFoundException $e) { }
      }

      $cache[$modulename] = $tmp;
    }

    if (null !== $key)
      return empty($cache[$modulename][$key]) ? null : $cache[$modulename][$key];
    else
      return $cache[$modulename];
  }

  public static function ismodule($name)
  {
    $tmp = mcms::getModuleMap();
    return array_key_exists($name, $tmp['modules']);
  }

  public static function modpath($name)
  {
    return 'lib/modules/'. $name;
  }

  public static function flush($flags = null)
  {
    if (null !== ($cache = BebopCache::getInstance()))
      $cache->flush(true & self::FLUSH_NOW ? true : false);
  }

  public static function db()
  {
    return PDO_Singleton::getInstance();
  }

  public static function user()
  {
    return AuthCore::getInstance()->getUser();
  }

  public static function auth($user = 'anonymous', $pass = null, $bypass = false)
  {
    $auth = AuthCore::getInstance();

    if ($user == 'anonymous')
      $auth->userLogOut();
    else
      $auth->userLogIn($user, $pass, $bypass);
  }

  public static function invoke($interface, $method, array $args = array())
  {
    foreach (mcms::getImplementors($interface) as $class)
      if (mcms::class_exists($class))
        call_user_func_array(array($class, $method), $args);
  }

  public static function log($op, $message, $nid = null)
  {
    if (mcms::ismodule('syslog'))
      SysLogModule::log($op, $message, $nid);
  }

  public static function resolveNodeLink($spec, $value, $string = false)
  {
    $parts = explode('.', $spec, 2);

    if (count($nodes = Node::find(array('class' => $parts[0], 'id' => $value)))) {
      $node = $nodes[key($nodes)];

      if ($string) {
        $output = $value;
        $output .= ' ('. $node->$parts[1] .')';
      } else {
        $output = $node;
      }

      return $output;
    }

    return null;
  }

  public static function message($text = null)
  {
    $rc = null;

    bebop_session_start();

    if (null === $text) {
      $rc = !empty($_SESSION['messages']) ? array_unique((array)$_SESSION['messages']) : null;
      $_SESSION['messages'] = array();
    } else {
      $_SESSION['messages'][] = $text;
    }

    bebop_session_end();

    return $rc;
  }

  public static function url(array $options, $inherit = false)
  {
    $args = $inherit ? bebop_split_url() : array();
    return bebop_combine_url($args, $options);
  }

  public static function report(Exception $e)
  {
    if (null === ($recipient = mcms::config('backtracerecipient')))
      return;

    switch (get_class($e)) {
    case 'ObjectNotFoundException':
    case 'UnauthorizedException':
    case 'ForbiddenException':
    case 'PageNotFoundException':
      return;
    }

    $body = t('<p>%method request for %url from %ip resulted in an %class exception (code %code) with the following message:</p>', array(
      '%method' => $_SERVER['REQUEST_METHOD'],
      '%url' => 'http://'. $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
      '%ip' => $_SERVER['REMOTE_ADDR'],
      '%class' => get_class($e),
      '%code' => $e->getCode(),
      ));

    $body .= '<blockquote><em>'. mcms_plain($e->getMessage()) .'</em></blockquote>';

    $body .= t('<p>Here is the stack trace:</p><blockquote>%stack</blockquote>', array(
      '%stack' => str_replace("\n", '<br/>', $e->getTraceAsString()),
      ));

    if (mcms::user()->getUid())
      $body .= t('<p>The user was identified as %user (#%uid).</p>', array(
        '%user' => mcms::user()->getName(),
        '%uid' => mcms::user()->getUid(),
        ));
    else
      $body .= t('<p>The user responsible for this action could not be identified.</p>');

    $body .= t('<p>The server runs Molinos.CMS version %version (<a href="%buglist">see the bug list</a>).</p>', array(
      '%version' => BEBOP_VERSION,
      '%buglist' => preg_replace('/^(\d+\.\d+)\..*$/', 'http://code.google.com/p/molinos-cms/issues/list?q=label:Milestone-R\1', BEBOP_VERSION),
      ));

    $subject = 'Molinos.CMS crash report for '. $_SERVER['HTTP_HOST'];

    $rc = bebop_mail('cms-bugs@molinos.ru', $recipient, $subject, $body);
  }

  public static function captchaGen()
  {
    if (mcms::user()->getUid() != 0)
      return null;

    $result = strtolower(substr(base64_encode(rand()), 0, 6));
    return $result;
  }

  public static function captchaCheck(array $data)
  {
    if (mcms::user()->getUid() != 0)
      return true;

    if (!empty($data['captcha']) and is_array($data['captcha']) and count($data['captcha']) == 2) {
      $usr = $data['captcha'][0];
      $ref = mcms_decrypt($data['captcha'][1]);

      if (0 === strcmp($usr, $ref))
        return true;
    }

    throw new ForbiddenException(t('Проверьте правильность ввода текста с изображения.'));
  }

  // Возвращает список доступных классов и файлов, в которых они описаны.
  // Информация о классах кэшируется в tmp/.classes.php или -- если доступен
  // класс BebopCache -- в более быстром кэше.
  public static function getClassMap()
  {
    $tmp = self::getModuleMap();
    return $tmp['classes'];
  }

  public static function getImplementors($interface, $module = null)
  {
    static $map = null;

    if (null === $map) {
      $map = self::getModuleMap();
    }

    if (null === $module and array_key_exists($interface, $map['interfaces']))
      return $map['interfaces'][$interface];
    elseif (!empty($map['modules'][$module]['implementors'][$interface]))
      return $map['modules'][$module]['implementors'][$interface];

    return array();
  }

  public static function getModuleMap($name = null)
  {
    $result = null;
    $filename = 'tmp/.modmap.php';

    if (!bebop_is_debugger() or empty($_GET['reload'])) {
      if (file_exists($filename) and is_readable($filename) and filesize($filename)) {
        if (is_array($result = unserialize(file_get_contents($filename))))
          return $result;
      }
    }

    $result = self::getModuleMapScan();

    if (null !== $name)
      return $result['modules'][$name];

    if (is_writable(dirname($filename)))
      file_put_contents($filename, serialize($result));

    return $result;
  }

  private static function getModuleMapScan()
  {
    $root = dirname(__FILE__) .'/modules/';

    $result = array(
      'modules' => array(),
      'classes' => array(),
      'interfaces' => array(),
      );

    foreach ($modules = glob($root .'*') as $path) {
      $modname = basename($path);

      $result['modules'][$modname] = array(
        'classes' => array(),
        'interfaces' => array(),
        'implementors' => array(),
        );

      if (file_exists($modinfo = $path .'/module.info')) {
        if (is_array($ini = parse_ini_file($modinfo, true))) {
          // Копируем базовые свойства.
          foreach (array('group', 'version', 'name', 'docurl') as $k)
            if (array_key_exists($k, $ini))
              $result['modules'][$modname][$k] = $ini[$k];
        }
      }

      // Составляем список доступных классов.
      foreach (glob($path .'/'. '*.*.php') as $classpath) {
        $parts = explode('.', basename($classpath), 3);

        if (count($parts) != 3 or $parts[2] != 'php')
          continue;

        $classname = null;

        switch ($type = $parts[0]) {
        case 'class':
          $classname = $parts[1];
          break;
        case 'control':
        case 'node':
        case 'widget':
        case 'exception':
          $classname = $parts[1] . $type;
          break;
        case 'interface':
          $classname = 'i'. $parts[1];
          break;
        }

        if (null !== $classname and is_readable($classpath)) {
          // Добавляем в список только первый найденный класс.
          if (!array_key_exists($classname, $result['classes'])) {
            $result['classes'][$classname] = $classpath;
            $result['modules'][$modname]['classes'][] = $classname;
          }

          // Строим список интерфейсов.
          if ($type !== 'interface') {
            if (preg_match('@^\s*(abstract\s+){0,1}class\s+([^\s]+)(\s+extends\s+([^\s]+))*(\s+implements\s+(.+))*@im', file_get_contents($classpath), $m)) {
              $classname = $m[2];

              if (!empty($m[6]))
                $interfaces = explode(',', str_replace(' ', '', $m[6]));
              else
                $interfaces = array();

              if (!empty($m[4])) {
                switch ($m[4]) {
                case 'Control':
                  $interfaces[] = 'iFormControl';
                  break;
                case 'Widget':
                  $interfaces[] = 'iWidget';
                  break;
                case 'Node':
                case 'NodeBase':
                  $interfaces[] = 'iContentType';
                  break;
                }
              }

              foreach ($interfaces as $i) {
                if (!in_array($i, $result['modules'][$modname]['interfaces']))
                  $result['modules'][$modname]['interfaces'][] = $i;
                $result['modules'][$modname]['implementors'][$i][] = $classname;
                $result['interfaces'][$i][] = $classname;
              }
            } else {
              bebop_debug(time(), $classname, $classpath, $m, $result);
            }
          }
        }
      }

      if (empty($result['modules'][$modname]['classes']))
        unset($result['modules'][$modname]);
    }

    ksort($result['classes']);

    return $result;
  }

  public static function class_exists($name)
  {
    return array_key_exists(strtolower($name), self::getClassMap());
  }

  public static function getFiles(array $data = null)
  {
    if ('POST' != $_SERVER['REQUEST_METHOD'])
      return null;

    if (null === ($result = $data))
      $result = array();

    foreach ($_FILES as $field => $fileinfo) {
      if (is_array($fileinfo['name'])) {
        foreach (array_keys($fileinfo) as $key) {
          foreach ($fileinfo[$key] as $k => $v)
            $result[$field][$k][$key] = $v;
        }
      }

      else {
        foreach ($fileinfo as $k => $v)
          $result[$field][$k] = $v;
      }
    }

    return $result;
  }

  public static function pager($total, $current, $limit, $paramname = 'page', $default = 1)
  {
    $result = array();

    if (empty($limit))
      return null;

    $result['documents'] = $total;
    $result['pages'] = $pages = ceil($total / $limit);
    $result['perpage'] = intval($limit);
    $result['current'] = $current;

    if ('last' == $current)
      $result['current'] = $current = $pages;

    if ('last' == $default)
      $default = $pages;

    if ($pages > 0) {
      // Немного валидации.
      if ($current > $pages or $current <= 0)
        throw new UserErrorException("Страница не найдена", 404, "Страница не найдена", "Вы обратились к странице {$current} списка, содержащего {$pages} страниц.&nbsp; Это недопустимо.");

      // С какой страницы начинаем список?
      $beg = max(1, $current - 5);
      // На какой заканчиваем?
      $end = min($pages, $current + 5);

      // Расщеплённый текущий урл.
      $url = bebop_split_url();

      for ($i = $beg; $i <= $end; $i++) {
        $url['args'][$paramname] = ($i == $default) ? '' : $i;
        $result['list'][$i] = ($i == $current) ? '' : bebop_combine_url($url);
      }

      if (!empty($result['list'][$current - 1]))
        $result['prev'] = $result['list'][$current - 1];
      if (!empty($result['list'][$current + 1]))
        $result['next'] = $result['list'][$current + 1];
    }

    return $result;
  }

  public static function fatal()
  {
    $output = array();

    foreach (func_get_args() as $arg) {
      $output[] = var_export($arg, true);
    }

    bebop_on_json(array('args' => $output));

    ob_end_clean();

    if (!empty($_SERVER['REQUEST_METHOD']))
      header("Content-Type: text/plain; charset=utf-8");

    print join(";\n\n", $output) .";\n\n";

    if (!empty($_SERVER['REMOTE_ADDR'])) {
      print "--- backtrace ---\n";
      debug_print_backtrace();
    }

    die();
  }
};

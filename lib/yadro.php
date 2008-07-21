<?php
// vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2:
//
// Yadro is a light-weight kernel for building web applications.
// It doesn't do anything visible itself, just lets you easily
// extend the application using plug-ins, and helps debug it.
//
// Yadro is based on two concepts:
//
//   (1) Pluggable modules, in the form of folders with scripts,
//       under the lib/ web site hierarchy.  Files named class.*.php
//       have special meaning to Yadro and contain PHP classes that
//       can be used by it.  One class per file.  Classes are loaded
//       automatically, so you don't have to include them directly.
//
//   (2) Message subscription, in the form of static methods with
//       special names.  Modules subscribe to messages by implementing
//       the corresponding methods; they send messages by calling
//       Yadro::call().  Each call() is delivered to all modules
//       subscribed to it.
//
// To use Yadro, you need to:
//
//   (1) Copy it to your htdocs directory.  Support for system-wide
//       installation is planned, but not currently implemented.
//
//   (2) Place your code in htdocs/lib/modules/*/class.*.php files.
//
//   (3) Include yadro.php and call Yadro::init().  You can pass it
//       the initial message name, which defaults to yadro_start.
//
//   (4) Create a class which has the static on_yadro_start()
//       method and extends Yadro.  This method will be called when a request
//       arrives.  You can do whatever you want in that code, typically
//       this involves calling out other modules, processing results and
//       returning them to the user agent.
//
// Benefits of using Yadro:
//
//   (1) No need to include files and implement autoload manually.
//
//   (2) The class-per-file rule means the code is easy to maintain.
//
//   (3) Your web application is easily extensible.
//
//   (4) You get the complete message trace by creating .yadro-debug
//       in your application's root folder.  The trace is built using
//       the standard error_log() function, so look for it in logs.
//
// Control files:
//
//   .yadro-debug:  enables tracing to error_log,
//   .yadro-reload: flushes class/method map (the file is deleted afterwards),
//
//
// Licensed under GPL.  Based on parabellym and influenced by QNX.
//
// (c) Justin Forest, 2008.
//
// http://code.google.com/p/molinos-cms/wiki/Yadro

class Yadro
{
  private static $methodmap;
  private static $classmap;

  private static $yadro_debug = false;
  private static $root;

  private static $time;

  // Reference to a cache interface object.
  private static $cache;

  public final static function init($initmsg = 'yadro_start')
  {
    if (null !== self::$methodmap)
      throw new Exception("Yadro is already initialized.");

    self::$time = microtime(true);

    self::$root = rtrim(empty($_SERVER['SCRIPT_FILENAME'])
      ? dirname(__FILE__)
      : dirname($_SERVER['SCRIPT_FILENAME']), '/');

    if (self::$yadro_debug = file_exists('.yadro-debug')) {
      if (!ini_get('log_errors'))
        ini_set('log_errors', true);
      self::log('logging enabled');
    }

    self::init_method_map();
    self::init_autoload();

    if (!empty($_GET['yadro_show_map'])) {
      header('content-type: text/plain; charset=utf-8');
      die(var_dump(self::$methodmap, self::$classmap));
    }

    self::call($initmsg);
  }

  public static final function finish()
  {
    self::log(sprintf('request processed in %s msec',
      microtime(true) - self::$time));
  }

  // TODO: добавить отлов циклов.
  public static final function call($name)
  {
    if (null === self::$methodmap)
      throw new Exception('Yadro needs to be initialized befor being used.');

    if ('on_' == substr($name, 0, 3))
      throw new Exception('You must NOT have the "on_" prefix in message names.');

    $results = array();
    $method = 'on_'. str_replace('.', '_', $name);

    $arguments = array_slice(func_get_args(), 1);

    if (array_key_exists($method, self::$methodmap)) {
      self::log('sending '. $name);

      foreach (self::$methodmap[$method] as $class) {
        if (get_parent_class($class) != __CLASS__) {
          self::log($class .' is not a subsclass of '. __CLASS__);
        } else {
          // self::log($method .': delivering to '. $class);
          $tmp = call_user_func(array($class, 'dispatch'),
            $class, $method, $arguments);
          if (null !== $tmp)
            $results[$class] = $tmp;
        }
      }
    } else {
      self::log($name .': no recipients, not delivered');
    }

    return empty($results) ? null : $results;
  }

  // Вывод отладочного сообщения.
  public final static function dump()
  {
    if (ob_get_length())
      ob_end_clean();

    $output = array();
    $exception = false;

    if (func_num_args()) {
      $args = func_get_args();

      if (count($args) == 1 and $args[0] instanceof Exception) {
        $exception = true;
        $output[] = get_class($args[0]);
        $output[] = $args[0]->getMessage();
      } else {
        foreach ($args as $arg)
          $output[] = preg_replace('/ =>\s+/', ' => ', var_export($arg, true));
      }
    } else {
      $output[] = 'breakpoint';
    }

    if (!empty($_SERVER['REQUEST_METHOD']))
      header("Content-Type: text/plain; charset=utf-8");

    print join(";\n\n", $output) .";\n\n";

    if (true /* !empty($_SERVER['REMOTE_ADDR']) */) {
      printf("--- backtrace (time: %s) ---\n", microtime());
      if ($exception)
        print self::__backtrace($args[0]->getTrace());
      else
        print self::__backtrace();
    }

    die();
  }

  // Корень приложения.
  public static final function root()
  {
    return self::$root;
  }

  private static final function dispatch($class, $name, array $arguments)
  {
    // self::log('dispatching '. $name);
    return call_user_func_array(array($class, $name), $arguments);
  }

  public static function log($message)
  {
    if (self::$yadro_debug === true)
      error_log('[yadro.'. posix_getpid() .'] '. $message);
  }

  private static function init_method_map()
  {
    if (self::init_method_map_from_cache())
      return;

    self::log('no class map in cache, rebuilding.');

    $methods = $classes = array();

    $filemask = 'lib/modules/*/{module,class}.*.php';
    $methodre = '@^\s*protected\s+static\s+function\s+'
      .'(on_[0-9a-z_]+|dispatch)@mS';

    foreach (glob($filemask, GLOB_BRACE|GLOB_NOSORT) as $file) {
      if (!is_readable($file)) {
        self::log($file .' is unreadable');
        continue;
      }

      $content = file_get_contents($file);

      if (!preg_match('@^\s*class\s+([a-zA-Z0-9_]+)@m', $content, $m1)) {
        self::log('[nc] '. $file);
        continue;
      }

      // Класс с таким именем уже попадался — пропускаем, иначе будут коллизии.
      if (array_key_exists($classname = $m1[1], $classes)) {
        self::log('[dc] '. $classname .', '. $file);
        continue;
      } else {
        $classes[$m1[1]] = $file;
      }

      if (!preg_match_all($methodre, $content, $m2)) {
        self::log('[nh] '. $file);
        continue;
      }

      foreach ($m2[1] as $methodname) {
        self::log($file .' handles '. $methodname);
        $methods[$methodname][] = $classname;
      }
    }

    self::$classmap = $classes;
    self::$methodmap = $methods;

    self::cache('classmap', array(
      'classes' => $classes,
      'methods' => $methods,
      ));
  }

  private static function init_method_map_from_cache()
  {
    if (!file_exists('.yadro-reload')) {
      if (is_array($tmp = self::cache('classmap'))) {
        self::$classmap = $tmp['classes'];
        self::$methodmap = $tmp['methods'];
        self::log('using cached classmap');
        return true;
      }
    } else {
      if (is_writable(getcwd()))
        unlink('.yadro-reload');
    }

    return false;
  }

  private static function init_autoload()
  {
    if (!function_exists('spl_autoload_register'))
      throw new Exception("Yadro needs SPL to function, see: "
        ."http://docs.php.net/manual/ru/book.spl.php");

    spl_autoload_register('Yadro::__autoload');
  }

  public static function __autoload($classname)
  {
    if (array_key_exists($classname, self::$classmap)) {
      $filename = self::$classmap[$classname];

      if (!is_readable(realpath($filename))) {
        self::log("{$filename} is unreadable, "
          ."failing to autoload {$classname}");

        // TODO: reset cache
        self::cache('classmap', null);
        self::log('class map cache reset');
      } else {
        self::log("loading {$classname} from {$filename}");
        include(realpath($filename));
      }
    } else {
      self::log("class {$classname} could not be loaded");
    }
  }

  private static function __backtrace($stack = null)
  {
    $output = '';

    if ($stack instanceof Exception) {
      $tmp = $stack->getTrace();
      array_unshift($tmp, array(
        'file' => $stack->getFile(),
        'line' => $stack->getLine(),
        'function' => sprintf('throw new %s', get_class($stack)),
        ));
      $stack = $tmp;
    } elseif (null === $stack or !is_array($stack)) {
      $stack = debug_backtrace();
      array_shift($stack);
    }

    foreach ($stack as $k => $v) {
      if (!empty($v['class']))
        $func = $v['class'] .$v['type']. $v['function'];
      else
        $func = $v['function'];

      $output .= sprintf("%2d. ", $k + 1);

      if (!empty($v['file']) and !empty($v['line'])) {
        $path = ltrim(str_replace(self::$root, '', $v['file']), '/');
        $output .= sprintf('%s(%d) — ', $path, $v['line']);
      } else {
        $output .= '??? — ';
      }

      $output .= $func .'()';

      $output .= "\n";
    }

    return $output;
  }

  protected static function cache()
  {
    if (null === self::$cache) {
      if (function_exists('xcache_set')) {
        self::$cache = new YadroCacheX();
        self::log('cache engine: xcache');
      } elseif (function_exists('dba_open')) {
        self::$cache = new YadroDBA();
      } else {
        self::log('cache engine: none');
        header('HTTP/1.1 501 Not Implemented');
        header('content-type: text/html; charset=utf-8');
        die('<html><head><title>Fatal Error</title></head><body>'
          .'<h1>Fatal Error</h1><p>No cache engine.  Please install '
          .'either of: xcache, apc, dba, sqlite.</p></body></html>');
      }
    }

    $args = func_get_args();

    switch (func_num_args()) {
      case 0:
        return self::$cache;
      case 1:
        return self::$cache->get($args[0]);
      case 2:
        return self::$cache->set($args[0], $args[1]);
      default:
        return false;
    }
  }
};

// XCache interface
class YadroCacheX
{
  public function get($key)
  {
    if ($tmp = xcache_get($key))
      return unserialize($tmp);
    return false;
  }

  public function set($key, $value)
  {
    return xcache_set($key, serialize($value));
  }
}

class YadroDBA
{
  const filename = '.yadro-cache';

  private $db;
  private $write = false;

  public function name($link = false)
  {
    return $link
      ? '<a href=\'http://docs.php.net/dba\'>DBA</a>'
      : 'DBA';
  }

  public function __construct()
  {
    $this->db = dba_open(getcwd() .DIRECTORY_SEPARATOR. self::filename, 'cd');
  }

  public function get($key)
  {
    if (false !== ($tmp = dba_fetch($key, $this->db)))
      return unserialize($tmp);
    return false;
  }

  public function set($key, $value)
  {
    if (!$this->write) {
      dba_close($this->db);
      $this->db = dba_open(getcwd() .DIRECTORY_SEPARATOR. self::filename, 'wd');
    }

    return dba_replace($key, serialize($value), $this->db);
  }
}

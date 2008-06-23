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
//       the initial message name, which defaults to ru_molinos_yadro_start.
//
//   (4) Create a class which has the static on_ru_molinos_yadro_start()
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
// Licensed under GPL.  Based on parabellym and influenced by QNX.
//
// (c) Justin Forest, 2008.
//
// http://code.google.com/p/molinos-cms/wiki/Yadro

class Yadro
{
  private static $methodmap;
  private static $classmap;
  private static $classmap_cache;

  private static $yadro_debug = false;
  private static $root;

  private static $time;

  public final static function init($initmsg = 'ru_molinos_yadro_start')
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
      self::yadro_log('logging enabled');
    }

    self::init_method_map();
    self::init_autoload();

    self::call($initmsg);
  }

  public static final function finish()
  {
    self::yadro_log(sprintf('request processed in %s msec',
      microtime(true) - self::$time));
  }

  // TODO: добавить отлов циклов.
  protected static final function call($name)
  {
    if (null === self::$methodmap)
      throw new Exception('Yadro needs to be initialized befor being used.');

    $results = array();
    $method = 'on_'. str_replace('.', '_', $name);

    $arguments = array_slice(func_get_args(), 1);

    self::yadro_log('sending '. $name);

    if (array_key_exists($method, self::$methodmap)) {
      foreach (self::$methodmap[$method] as $class) {
        if (get_parent_class($class) != __CLASS__) {
          self::yadro_log($class .' is not a subsclass of '. __CLASS__);
        } else {
          $tmp = call_user_func(array($class, 'dispatch'),
            $class, $method, $arguments);
          if (null !== $tmp)
            $results[$class] = $tmp;
        }
      }
    }

    return empty($results) ? null : $results;
  }

  // Вывод отладочного сообщения.
  protected final static function dump()
  {
    if (ob_get_length())
      ob_end_clean();

    $output = array();

    if (func_num_args()) {
      foreach (func_get_args() as $arg) {
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
    self::yadro_log('dispatching '. $name);
    return call_user_func_array(array($class, $name), $arguments);
  }

  private static function yadro_log($message)
  {
    if (self::$yadro_debug === true)
      error_log('[yadro.'. posix_getpid() .'] '. $message);
  }

  private static function init_method_map()
  {
    if (self::init_method_map_from_cache())
      return;

    $methods = $classes = array();

    $filemask = 'lib/modules/*/{module,class}.*.php';
    $methodre = '@^\s*protected\s+static\s+function\s+'
      .'(on_[0-9a-z_]+|dispatch)@mS';

    foreach (glob($filemask, GLOB_BRACE|GLOB_NOSORT) as $file) {
      if (!is_readable($file)) {
        self::yadro_log($file .' is unreadable');
        continue;
      }

      $content = file_get_contents($file);

      if (!preg_match('@^\s*class\s+([a-zA-Z0-9_]+)@m', $content, $m1)) {
        self::yadro_log('[nc] '. $file);
        continue;
      }

      // Класс с таким именем уже попадался — пропускаем, иначе будут коллизии.
      if (array_key_exists($classname = $m1[1], $classes)) {
        self::yadro_log('[dc] '. $classname .', '. $file);
        continue;
      } else {
        $classes[$m1[1]] = $file;
      }

      if (!preg_match_all($methodre, $content, $m2)) {
        self::yadro_log('[nh] '. $file);
        continue;
      }

      foreach ($m2[1] as $methodname) {
        self::yadro_log($file .' handles '. $methodname);
        $methods[$methodname][] = $classname;
      }
    }

    self::$classmap = $classes;
    self::$methodmap = $methods;

    if (!is_writable(self::$root)) {
      self::yadro_log('cwd unwritable, classmap not cached');
    } else {
      $cache = serialize(array(
        'classes' => $classes,
        'methods' => $methods,
        ));
      file_put_contents(self::$root .'/.yadro-classmap', $cache);
      self::yadro_log('classmap cached in .yadro-classmap');
    }
  }

  private static function init_method_map_from_cache()
  {
    self::$classmap_cache = self::$root .'/.yadro-classmap';

    if (!is_readable(self::$classmap_cache)) {
      self::yadro_log('no cached class map');
      return false;
    }

    if (!filesize(self::$classmap_cache)) {
      self::yadro_log('empty cached classmap');
      return false;
    }

    if (filemtime(self::$classmap_cache) < filemtime(realpath('lib'))) {
      self::yadro_log('cached classmap is old');
      return false;
    }

    if (filemtime(self::$classmap_cache) < filemtime(__FILE__)) {
      self::yadro_log('cached classmap is old');
      return false;
    }

    $tmp = unserialize(file_get_contents(self::$classmap_cache));
    if (!is_array($tmp)) {
      self::yadro_log('unable to unserialize cached classmap: '. $tmp);
      return false;
    }

    if (!array_key_exists('classes', $tmp))
      return false;
    if (!array_key_exists('methods', $tmp))
      return false;

    self::$classmap = $tmp['classes'];
    self::$methodmap = $tmp['methods'];

    self::yadro_log('using cached classmap');

    return true;
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
        self::yadro_log("{$filename} is unreadable, "
          ."failing to autoload {$classname}");

        if (file_exists(self::$classmap_cache)) {
          if (is_writable(dirname(self::$classmap_cache))) {
            unlink(self::$classmap_cache);
            self::yadro_log('class map cache reset');
          }
        }
      } else {
        self::yadro_log("loading {$classname} from {$filename}");
        include(realpath($filename));
      }
    } else {
      self::yadro_log("class {$classname} could not be loaded");
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
};

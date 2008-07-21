<?php

class mcms extends Yadro
{
  private static $tpltypes = null;

  public static function render($type, array $data, $theme = 'all', $default = null)
  {
    if (null === self::$tpltypes) {
      self::$tpltypes = array();

      if (is_array($map = self::call('mcms_template_list')))
        foreach ($map as $t)
          self::$tpltypes = array_merge(self::$tpltypes, (array)$t);
    }

    if (null !== $type) {
      $paths = array("themes/{$theme}/templates/{$type}");
      if ('all' != $theme)
        $paths[] = "themes/all/templates/{$type}";

      foreach ($paths as $path) {
        foreach (self::$tpltypes as $type) {
          if (is_readable($filename = $path .'.'. $type)) {
            $output = self::call('mcms_template_'. $type, $filename, $data);
            return is_array($output) ? array_shift($output) : null;
          }
        }
      }
    }

    if (null !== $default and is_readable($default)) {
      $output = self::call('mcms_template_phtml', $default, $data);
      return is_array($output) ? array_shift($output) : null;
    }

    return null;
  }

  public static function send($text, $type = 'text/html', $code = 200)
  {
    $names = array(
      200 => 'OK',
      404 => 'Not Found',
      );

    if (array_key_exists($code, $names))
      $title = $names[$code];
    else
      $title = 'Unknown';

    header('HTTP/1.1 '. $code .' '. $title);
    header('Content-Length: '. strlen($text));
    header('Content-Type: '. $type .'; charset=utf-8');
    die($text);
  }

  public static function html()
  {
    if (func_num_args() == 0 or func_num_args() > 3)
      throw new InvalidArgumentException(t('mcms::html() принимает от одного до трёх параметров.'));
    else {
      $args = func_get_args();
      $name = array_shift($args);

      if (empty($name))
        throw new InvalidArgumentException(t('Попытка создать HTML элемент без имени.'));

      $parts = null;
      $content = null;

      if (is_array($tmp = array_shift($args)))
        $parts = $tmp;
      else
        $content = $tmp;

      if (!empty($args))
        $content = array_shift($args);
    }

    $output = '<'. $name;

    if (('td' == $name or 'th' == $name) and empty($content))
      $content = '&nbsp;';

    if (empty($parts))
      $parts = array();

    $fixmap = array(
      'img' => 'src',
      'a' => 'href',
      'form' => 'action',
      'script' => 'src',
      'link' => 'href',
      );

    // Прозрачная поддержка чистых урлов.
    foreach ($fixmap as $k => $v) {
      if ($k != $name or !array_key_exists($v, $parts))
        continue;

      if (false !== strstr($parts[$v], '://'))
        continue;

      if ('/' == substr($parts[$v], 0, 1))
        continue;

      if (is_readable(MCMS_ROOT .'/'. $parts[$v]))
        continue;

      if ('form' == $k)
        $url = mcms::path() .'/'. strval(new url($parts[$v]));
      else
        $url = strval(new url($parts[$v]));

      $parts[$v] = $url;
    }

    if (null !== $parts) {
      foreach ($parts as $k => $v) {
        if (!empty($v)) {
          if (is_array($v))
            if ($k == 'class')
              $v = join(' ', $v);
            else {
              $v = null;
            }

          $output .= ' '.$k.'=\''. htmlspecialchars($v, ENT_QUOTES) .'\'';
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

  // Tempalte processing.

  protected static function on_mcms_template_list()
  {
    return 'phtml';
  }

  protected static function on_mcms_template_phtml($filename, array $data)
  {
    if (is_readable($filename)) {
      extract($data);

      ob_start();
      include($filename);
      $result = ob_get_clean();

      return empty($result) ? null : $result;
    }
  }

  public static function signature()
  {
    $cache = Yadro::cache()->name(true);
    $memory = ini_get('memory_limit');

    return "<a href='http://molinos-cms.googlecode.com/'>Molinos CMS</a>/8.09 "
      ."[{$memory}+{$cache}] "
      ."at <a href='http://{$_SERVER['HTTP_HOST']}'>{$_SERVER['HTTP_HOST']}</a>";
  }
}

<?php

class RequestProcessor extends Yadro
{
  protected static function on_yadro_start()
  {
    $ctx = new Context();

    set_exception_handler('RequestProcessor::exception_handler');

    if (null !== ($res = Yadro::call('mcms_pre', $ctx))) {
      $data = array('messages' => $res);
      $text = mcms::render('error.500', $data,
        'lib/modules/mcms/default.500.phtml');
      return mcms::send($text, 'text/html', 500);
    }

    if (null === ($res = parent::call('mcms_request', $ctx))) {
      $text = mcms::render('error.404', array(),
        'lib/modules/mcms/default.404.phtml');
      return mcms::send($text, 'text/html', 404);
    }
  }

  protected static function on_mcms_pre(Context $ctx)
  {
    $htreq = array(
      'register_globals' => 0,
      'magic_quotes_gpc' => 0,
      'magic_quotes_runtime' => 0,
      'magic_quotes_sybase' => 0,
      );

    $errors = $messages = array();

    foreach ($htreq as $k => $v) {
      $key = substr($k, 0, 1) == '@' ? substr($k, 1) : $k;

      ini_set($key, $v);

      if (($v != ($current = ini_get($key))) and (substr($k, 0, 1) != '@'))
        $errors[] = $key;
    }

    if (!extension_loaded('pdo'))
      $messages[] = 'Отсутствует поддержка <a href=\'@url\'>PDO</a>.  Она очень нужна, '
        .'без неё не получится работать с базами данных.';

    if (!extension_loaded('mbstring'))
      $messages[] = 'Отсутствует поддержка юникода.  21й век на дворе, '
        .'пожалуйста, установите расширение '
        .'<a href=\'http://php.net/mbstring\'>mbstring</a>.';
    elseif (!mb_internal_encoding('UTF-8'))
      $messages[] = 'Не удалось установить UTF-8 в качестве '
        .'базовой кодировки для модуля mbstr.';

    if (!empty($errors) or !empty($messages)) {
      $output = "<html><head><title>Ошибка конфигурации</title></head><body>";

      if (!empty($errors)) {
        $output .= '<h1>'. t('Нарушение безопасности') .'</h1>';
        $output .= "<p>Следующие настройки <a href='http://php.net/'>PHP</a> неверны и не могут быть <a href='http://php.net/ini_set'>изменены на лету</a>:</p>";
        $output .= "<table border='1'><tr><th>Параметр</th><th>Значение</th><th>Требуется</th></tr>";

        foreach ($errors as $key)
          $output .= "<tr><td>{$key}</td><td>". ini_get($key) ."</td><td>{$htreq[$key]}</td></tr>";

        $output .= "</table>";
      }

      if (!empty($messages)) {
        $output .= '<h1>'. t('Ошибка настройки') .'</h1>';
        $output .= '<ol><li>'. join('</li><li>', $messages) .'</li></ol>';
      }

      $output .= '<p>'. t('Свяжитесь с администратором вашего хостинга для исправления этих проблем.&nbsp; <a href=\'http://code.google.com/p/molinos-cms/\'>Molinos.CMS</a> на данный момент не может работать.') .'</p>';
      $output .= "</body></html>";

      return $output;
    }
  }

  protected static function on_mcms_request(Context $ctx)
  {
    $path = $ctx->get('q');

    if ('.rpc' == substr($path, -4)) {
      $name = substr($path, 0, -4);
      $result = Yadro::call('mcms_rpc_'. $name, $ctx);

      Yadro::dump($name, $result);
    }
  }

  // Error handling.

  public static function exception_handler($e)
  {
    Yadro::dump($e);

    try {
      $text = mcms::render('error.500', array(
        'messages' => (array)$e->getMessage()));
      // Normal sh-t.
      return mcms::send($text, 'text/html', 500);
    } catch (Exception $e) {
      // Deep sh-t.
      return mcms::send($e->getMessage(), 'text/plain', 500);
    }
  }
}

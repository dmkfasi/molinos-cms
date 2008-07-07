<?php

class Twitter implements iModuleConfig
{
  const API_URL = 'twitter.com';
  const PATH_STATUS_UPDATE = '/statuses/update';
  const STATUS_MAXLENGTH = 140;

  public static function formGetModuleConfig()
  {
    $form = new Form(array(
      'title' => t('Настройка авторизации'),
      'class' => 'tabbed',
    ));

    $form->addControl(new TextLineControl(array(
      'value' => 'config_login',
      'label' => t('Имя пользователя <a href="@url">Twitter.com</a>', array('@url' => 'http://www.twitter.com/')),
      'description' => t('Здесь указывается имя пользователя, чья лента статуса будет обновляться через отправленные сообщения. Все подписавшиеся на нее, будут получать соответствующие уведомления.'),
    )
    ));

    $form->addControl(new PasswordControl(array(
      'value' => 'config_password',
      'label' => t('Пароль'),
    )
    ));

//    $form->addControl($tab);

    return $form;
  }

  private static function send($status, $requestedFormat = 'xml')
  {
    $max = self::STATUS_MAXLENGTH;
    if(empty($status)) {
      throw new RuntimeException('Не стоит отправлять пустые сообщения.');
    }

    if($max < strlen($status)) {
      throw new RuntimeException("Сообщение не должно быть более {$max} символов.");
    }

    $login = mcms::modconf('twitter', 'login');
    $passwords = mcms::modconf('twitter', 'password');

    $url =  self::PATH_STATUS_UPDATE . ".{$requestedFormat}";
    $url .= '?status=' . urlencode(stripslashes(urldecode($status)));

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, self::API_URL . $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$login}:{$passwords[0]}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $data = curl_exec($ch);

    if (CURLE_OK !== curl_errno($ch))
      throw new RuntimeException("Сообщение не было отправлено пользователю {$login}@twitter.com");

    curl_close($ch);
  }

  public static function sendUpdate($status, $requestedFormat = 'xml')
  {
    self::send($status, $requestedFormat);
  }

  public static function hookPostInstall()
  {
  }
}
<?php

require(dirname(__FILE__) .'/lib/bootstrap.php');

$installer = new BebopInstaller();
$installer->run();

class BebopInstaller
{
  public function run()
  {
    switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      $data = $this->onGet();
      break;
    case 'POST':
      $data = $this->onPost();
      break;
    default:
      header('Content-Type: text/plain; charset=utf-8');
      die('Sorry, what?');
    }

    $output = bebop_render_object("system", "installer", "admin", $data);

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: '. strlen($output));
    die($output);
  }

  protected function onGet()
  {
    if ($this->checkInstalled())
      bebop_redirect('/admin/');

    $data = array();

    if (null !== ($data['form'] = $this->checkConfigDir()))
      return $data;

    $form = new Form(array(
      'title' => t('Инсталляция Molinos CMS'),
      'description' => t("Вам нужно последовательно заполнить все поля во всех вкладках."),
      ));

    $tab = new FieldSetControl(array(
      'name' => 'mysql',
      'label' => t('База данных'),
      ));
    $tab->addControl(new TextLineControl(array(
      'value' => 'db[host]',
      'label' => t('MySQL сервер'),
      'description' => t("Обычно здесь ничего менять не надо."),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'db[name]',
      'label' => t('Имя базы данных'),
      'description' => t("В этой базе данных будут созданы таблицы CMS с префиксом <code>node</code>.&nbsp; Другие таблицы затронуты не будут.&nbsp; Если Molinos CMS уже установлена в эту базу данных &mdash; произойдёт обновление, существующие данные потеряны не будут."),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'db[user]',
      'label' => t('Пользователь MySQL'),
      'description' => t("В этой базе данных будут созданы таблицы CMS с префиксом <code>node</code>.&nbsp; Другие таблицы затронуты не будут.&nbsp; Если Molinos CMS уже установлена в эту базу данных &mdash; произойдёт обновление, существующие данные потеряны не будут."),
      )));
    $tab->addControl(new PasswordControl(array(
      'value' => 'db[pass]',
      'label' => t('Пароль этого пользователя'),
      'description' => t("Отображён не будет, только звёздочки."),
      )));
    $form->addControl($tab);

    $tab = new FieldSetControl(array(
      'name' => 'site',
      'label' => t('Сайт'),
      ));
    $tab->addControl(new TextLineControl(array(
      'value' => 'config[basedomain]',
      'label' => t('Основной домен'),
      'description' => t("Административный интерфейс также будет работать в этом домене."),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'config[backtracerecipient]',
      'label' => t('Получатели сообщений об ошибках'),
      'description' => t("Один или несколько почтовых адресов, на которые будет отправляться информация о фатальных ошибках.&nbsp; Если здесь ничего не указывать, ошибки никуда отправляться не будут."),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'config[debuggers]',
      'label' => t('IP-адреса отладчиков'),
      'description' => t("Один или несколько IP адресов, пользователям которых будут доступны дополнительные отладочные функции.&nbsp; Обычто этим пользуются только разработчики сайта."),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'config[mail_from]',
      'label' => t('Обратный почтовый адрес'),
      'description' => t('Этот адрес будет использован в качестве отправителя всех почтовых сообщений, рассылаемых сайтом.'),
      )));
    $tab->addControl(new TextLineControl(array(
      'value' => 'config[mail_server]',
      'label' => t('Почтовый сервер'),
      'description' => t('Через этот SMTP сервер будет отправляться вся почта.'),
      )));
    $form->addControl($tab);

    $tab = new FieldSetControl(array(
      'name' => 'confirm',
      'label' => t('Подтверждение'),
      ));
    $tab->addControl(new BoolControl(array(
      'value' => 'confirm',
      'label' => t('Я подтверждаю свои намерения'),
      )));
    $tab->addControl(new SubmitControl(array(
      'text' => t('Поехали!'),
      )));
    $form->addControl($tab);

    $data = array(
      'db[host]' => 'localhost',
      'config[basedomain]' => $_SERVER['HTTP_HOST'],
      'config[backtracerecipient]' => 'cms-bugs@molinos.ru',
      'config[debuggers]' => $_SERVER['REMOTE_ADDR'] .', 127.0.0.1',
      'config[mail_from]' => "Molinos.CMS <no-reply@{$_SERVER['HTTP_HOST']}>",
      'config[mail_server]' => 'localhost',
      );

    return array(
      'form' => $form->getHTML($data),
      );
  }

  protected function onPost()
  {
    $pdo = null;

    // Очищаем информацию о пользователе, чтобы AuthCore не лез в базу.
    User::authorize('anonymous', null, true);

    $data = array(
      'title' => 'Инсталляция Molinos CMS',
      'form' => '<p>Установка системы завершена.</p>',
      );

    $this->writeConfig($_POST);

    // Теперь можно попробовать запустить транзакцию.
    try {
      $pdo = PDO_Singleton::getInstance();
      $pdo->beginTransaction();
    } catch (Exception $e) {
      $pdo = null;
    }

    $this->runScripts();

    // Всё хорошо, можно сохранять изменниея.
    if (null !== $pdo)
      $pdo->commit();

    // Логинимся в качестве рута.
    AuthCore::getInstance()->userLogIn('root', null, true);

    BebopCache::getInstance()->flush(true);

    $data['form'] .= '<p>'. t("Вы были автоматически идентифицированы как пользователь &laquo;%username&raquo;.&nbsp; Пароль для этого пользователя был сгенерирован случайным образом, поэтому сейчас лучше всего <a href='@editlink'>изменить пароль</a> на какой-нибудь, который Вы знаете, а потом уже продолжить <a href='/admin/'>пользоваться системой</a>.", array(
      '%username' => 'root',
      '@editlink' => '/admin/node/'. AuthCore::getInstance()->getUser()->getUid() .'/edit/?destination=%2Fadmin%2F',
      )) .'</p>';

    return $data;
  }

  private function writeConfig(array $data)
  {
    if (empty($data['confirm']))
      throw new InvalidArgumentException("Вы не подтвердили свои намерения.");

    $config = array(
      'dsn' => "mysql://{$data['db']['user']}:{$data['db']['pass']}@{$data['db']['host']}/{$data['db']['name']}",
      'basedomain' => $data['config']['basedomain'],
      'mail_from' => $data['config']['mail_from'],
      'mail_server' => $data['config']['mail_server'],
      'backtracerecipient' => $data['config']['backtracerecipient'],
      'debuggers' => $data['config']['debuggers'],
      'filestorage' => 'storage',
      'smarty_compile_dir' => 'tmp/compiled_templates',
      'smarty_cache_dir' => 'tmp/smarty_cache',
      'smarty_plugins_dir' => 'lib/smarty',
      'tmpdir' => 'tmp',
      'file_cache_ttl' => 60 * 60,
      );

    $output =
      "; vim: set expandtab tabstop=2 shiftwidth=2 softtabstop=2 ft=dosini\n"
      .";\n"
      ."; This is the configuration file for Molinos CMS.\n"
      ."; It was generated automatically by the installation script.\n"
      ."; Changes you make become effective immediately after saving this file.\n"
      ."\n";

    foreach ($config as $k => $v)
      $output .= "{$k} = {$v}\n";

    if (!file_put_contents($fname = $this->getConfigPath($config['basedomain']), $output))
      throw new InvalidArgumentException("Could not save configuraton to {$fname}.");

    chmod($fname, 0660);

    BebopConfig::getInstance()->reload();
  }

  private function getConfigPath($domain)
  {
    $result = array();

    for ($parts = explode('.', $domain); !empty($parts); $result[] = array_pop($parts));

    return 'conf/'. join('.', $result) .'.ini';
  }

  private function runScripts()
  {
    $um = new UpdateManager();
    $um->runUpdates(array(
      'tables',
      'types',
      'reindex',
      'users',
      'ui',
      'access',
      ));
  }

  private function checkInstalled()
  {
    try {
      $root = Node::load(array('class' => 'user', 'login' => 'root'));
      return true;
    } catch (Exception $e) {
      return false;
    }
  }

  private function checkConfigDir()
  {
    $dirname = realpath(dirname(__FILE__) .'/conf');

    if (!is_writable($dirname))
      return "<p>". t("Каталог с конфигурационными файлами (<code>%path</code>) защищён от записи.&nbsp; Сделайте его доступным для записи сервером, затем обновите эту страницу.", array('%path' => $dirname)) ."</p>";

    $htaccess = dirname(__FILE__) .'/.htaccess';

    if (!file_exists($htaccess) and file_exists($htaccess .'.dist')) {
      if (!@rename($htaccess .'.dist', $htaccess))
        return "<p>". t("Не удалось переименовать файл <code>.htaccess.dist</code> в <code>.htaccess</code>, Вам придётся сделать это вручную, затем обновить эту страницу.&nbsp; Без этого CMS работать не будет.") ."</p>";
    }

    return null;
  }
};
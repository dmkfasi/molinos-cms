<?php

class Context
{
  private $get = array();
  private $post = array();
  private $files = array();
  private $db = array();
  private $config = null;

  public function __construct()
  {
    $this->get = $_GET;

    if ('POST' == $_SERVER['REQUEST_METHOD']) {
      $this->post = $_POST;
      $this->files = $_FILES;
    }
  }

  public function get($key, $default = null)
  {
    return array_key_exists($key, $this->get) ? $this->get[$key] : $default;
  }

  public function post($key, $default = null)
  {
    return array_key_exists($key, $this->post) ? $this->post[$key] : $default;
  }

  public function file($key, $default = null)
  {
    return array_key_exists($key, $this->file) ? $this->file[$key] : $default;
  }

  public function db($name = 'default')
  {
    if (!array_key_exists($name, $this->db)) {
      $dsn = parse_url($this->__get('config')->get('db.default'));

      if (class_exists($driver = 'mcms_db_'. $dsn['scheme']))
        $this->db[$name] = new $driver($dsn);
      else
        throw new NotInstalledException('Драйвер не найден: '. $driver .'.');
    }

    return $this->db[$name];
  }

  public static function session($key, $value = null)
  {
    $s = Session::instance();

    if (null === $value)
      return $s->$key;
    else
      $s->$key = $value;
  }

  public function __get($key)
  {
    switch ($key) {
    case 'config':
      if (null === $this->config)
        $this->config = new Config();
      return $this->config;
    case 'db':
      return $this->db();
    }
  }
}

<?php

class Session extends Yadro
{
  private $id = null;
  private $data = null;
  private $hash = null;

  private static $instance = null;

  public static function instance()
  {
    if (null === self::$instance)
      self::$instance = new Session();
    return self::$instance;
  }

  protected function load()
  {
    $this->data = array();

    if (!empty($_COOKIE['mcmsid'])) {
      $this->id = $_COOKIE['mcmsid'];

      $tmp = mcms::db()->get("SELECT `data` FROM {sessions} "
        ."WHERE `sid` = ?", array($this->id));

      if (!empty($tmp) and is_array($arr = unserialize($tmp)))
        $this->data = $arr;
    }

    $this->hash = sha1(serialize($this->data));
  }

  protected function save()
  {
    if (sha1(serialize($this->data)) != $this->hash) {
      if (null === $this->id)
        $this->id = sha1($_SERVER['REMOTE_ADDR'] . microtime(false) . rand());
      mcms::db()->exec("DELETE FROM {sessions} WHERE `sid` = ?",
        array($this->id));
      mcms::db()->exec("INSERT INTO {sessions} (`sid`, `data`) "
        ."VALUES (?, ?)", array($this->id, serialize($this->data)));
    }
  }

  public function __get($key)
  {
    if (null === $this->data)
      $this->load();

    return array_key_exists($key, $this->data)
      ? $this->data[$key]
      : null;
  }

  public function __set($key, $value)
  {
    if (null === $this->data)
      $this->load();

    if (null !== $value)
      $this->data[$key] = $value;
    elseif (array_key_exists($key, $this->data))
      unset($this->data[$key]);
  }

  protected static function on_mcms_destroy()
  {
    if (null !== self::$session)
      self::$session->save();
  }

  protected static function on_mcms_update_table_sessions(PDO $pdo)
  {
    return TableInfo::update($pdo, 'sessions', array(
      'sid' => array (
        'type' => 'char(40)',
        'required' => true,
        'key' => 'pri',
      ),
      'created' => array (
        'type' => 'datetime',
        'required' => true,
        'key' => 'mul',
      ),
      'data' => array (
        'type' => 'blob',
        'required' => true,
      ),
    ));
  }
}

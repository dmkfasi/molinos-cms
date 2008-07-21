<?php

class mcmsdb extends PDO
{
  protected $prefix = null;
  protected $dbtype = null;

  public function __construct($dsn, $user = null, $pass = null)
  {
    parent::__construct($dsn, $user, $pass);

    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (version_compare(PHP_VERSION, '5.1.3', '>='))
      $this->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
  }

  public static final function connect($name)
  {
    Yadro::dump($name);
  }

  private function rewrite($sql)
  {
    $sql = str_replace(array('{', '}'), array('`'. $this->prefix, '`'), $sql);
    return $sql;
  }

  protected function strip_prefix($table)
  {
    if (substr($table, 0, $len = strlen($this->prefix)) == $this->prefix)
      return substr($table, $len);
    else
      return $table;
  }

  protected function update_table($name)
  {
    return (null !== Yadro::call('mcms_update_table_'. $name, $this));
  }

  public function prepare($sql, array $options = null)
  {
    if (null !== $options)
      return parent::prepare($this->rewrite($sql), $options);
    else
      return parent::prepare($this->rewrite($sql));
  }

  public function exec($sql, array $params = null)
  {
    $sth = $this->prepare($sql);
    $sth->execute($params);
    return $sth->rowCount();
  }

  public function fetch($sql, array $params = null)
  {
    $sth = $this->prepare($sql);
    $sth->execute($params);
    return $sth->fetchAll(PDO::FETCH_ASSOC);
  }

  public function get($sql, array $params = null)
  {
    $tmp = $this->fetch($sql, $params);

    while (is_array($tmp) and count($tmp) == 1)
      $tmp = array_shift($tmp);

    return $tmp;
  }
}

class TableNotFoundException extends Exception
{
  private $table_name;

  public function __construct($table_name)
  {
    $this->table_name = $table_name;
    parent::__construct('Table does not exist: '. $table_name);
  }
}

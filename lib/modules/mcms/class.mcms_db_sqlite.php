<?php

class mcms_db_sqlite extends mcmsdb
{
  public function __construct(array $dsn)
  {
    $this->prefix = empty($dsn['fragment'])
      ? null
      : $dsn['fragment'];

    if (!in_array('sqlite', PDO::getAvailableDrivers()))
      throw new NotInstalledException('The required database driver '
        .'(PDO_SQLite) is not available; please install.');

    parent::__construct('sqlite:'. $dsn['path'], null, null);
  }

  public function prepare($sql, array $options = null)
  {
    try {
      return parent::prepare($sql, $options);
    } catch (PDOException $e) {
      $message = $e->getMessage();

      if (preg_match('@no such table: (.+)$@', $message, $m)) {
        $table = $this->strip_prefix($m[1]);
        if ($this->update_table($table))
          return $this->prepare($sql, $options);
        else
          throw new TableNotFoundException($table);
      }

      Yadro::dump($e);
    }
  }

  public function getTableInfo($name)
  {
    $indexes = array();
    $sql = "SELECT * FROM `sqlite_master` WHERE `tbl_name` = '{{$name}}' AND `type` = 'index'";
    $rows = $this->fetch($sql);

    foreach ($rows as $k => $el) {
      $str = $el['sql'];
      $col = preg_match("/\((.+)\)/", $str, $matches);
      $col = $matches[1];
      $col = str_replace('`', '', $col);
      $indexes[$col] = 1;
    }

    // получим саму таблицу
    $sql = "SELECT * FROM `sqlite_master` WHERE `tbl_name` = '{{$name}}' AND `type` = 'table'";
    $rows = $this->fetch($sql);

    if (empty($rows))
      return false;

    $sql = $rows[0]['sql'];

    $sql = strstr($sql,'(');
    $sql = substr($sql, 1);
    $fields = preg_split("/,(?!\d)/", $sql); // чтобы не было сплитования в случае DECIMAL(10,2)
    $columns = array();

    //Сейчас имеем базг в SQLite - $rows[0]['sql'] содержит на конце непарную ')',
    //что вызывает глюки, если у  нас для последнего поля не указан размер. Надо эту
    //скобку удалить, то только в том случае, если она действительно
    //несимметрична (т.е. число скобок ( и ) не равно) - вдруг этот глюк исправят в последующих версиях SQLite,
    //тогда удаление будет ненужным и вредным

    $lastel = end($fields);
    if (substr_count($lastel,'(') != substr_count($lastel,')')) {
      $lastel = trim($lastel,')');
      $fields[count($fields)-1] = $lastel;
    }

    foreach ($fields as $v)    {
      // получим тип
      $p = strpos($v, ")"); // для int(10) и пр. вариантов с размерами

      if (!$p) {
        // тип datetime или какой-либо другой без указания размера
        $arr = preg_split("/\s/", $v, -1, PREG_SPLIT_NO_EMPTY);
      } else {
        $f = substr($v, 0, $p + 1);
        $arr = preg_split("/\s/", $f, 2, PREG_SPLIT_NO_EMPTY);
      }

      $name = $arr[0];
      $name = str_replace('`', '', $name);

      $c = array();
      $c['type'] = $arr[1];
      $c['required'] = false;
      $c['key'] = false;
      $c['default'] = null;
      $c['autoincrement'] = false;

      $v =  substr($v,$p+1);

      // проверим на NOT NULL
      if (preg_match("/NOT\s+NULL/i", $v))
        $c['required'] = true;

      // найдём дефолтное значение
      if (preg_match("/DEFAULT\s+(\S+)\s*/i", $v, $matches))
        $c['default'] = str_replace('\'', '', $matches[1]);

      // определим, является ли это первичным ключём или нет
      if (preg_match("/primary/i", $v)) {
        $c['key'] = 'pri';
        $c['autoincrement'] = true;
      }

      if ($indexes[$name])
        $c['key'] = 'mul';

      $columns[$name] = $c;
    }

    return $columns;
  }

  public function addSql($name, array $spec, $modify, $isnew)
  {
    $sql = '';
    $index = '';

    if (!$isnew) {
      if ($modify)
        //$sql .= "MODIFY COLUMN "; //Вся модификация происходит в recreateTable
        return array('', '');//SQLite не поддерживает MODIFY COLUMN
      else
        $sql .= "ADD COLUMN ";
    }

    $sql .= "`{$name}` ";
    $sql .= $spec['type'];

    if ($spec['required'])
      $sql .= ' NOT NULL';
    else
      $sql .= ' NULL';

    if (null !== $spec['default'])
      $sql .= ' DEFAULT \''. sqlite_escape_string($spec['default']).'\'';

    if ('pri' == $spec['key']) {
      if (!$modify)
        $sql .= ' PRIMARY KEY';
    } elseif (!empty($spec['key'])) {
      $index = $name;
    }

    return array($sql, $index);
   }

   public function getSql($name, array $alter, $isnew)
   {
     if (empty($alter) or empty($alter[0])) return null;
     if ($isnew)
       $sql = "CREATE TABLE {{$name}} (";
     else
       $sql = "ALTER TABLE {{$name}} ";

     $sql .= join(', ', $alter);

     if ($isnew)
       $sql .= ') ';

     return $sql;
  }
}

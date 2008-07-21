<?php

class Config
{
  private $host;
  private $path;
  private $name;
  private $data = null;

  public function __construct($hostname = null, $workdir = null)
  {
    $this->host = $hostname;

    if (null === $workdir)
      $this->path = getcwd();
    else
      $this->path = $workdir;
  }

  private function open()
  {
    $this->filename = $this->path .'/conf/default.ini';

    if (!is_readable($this->filename))
      throw new NotInstalledException('Configuration file '
        .'not found, perhaps the system was not installed.');

    $this->data = parse_ini_file($this->filename, true);

    Yadro::log('config: '. $this->filename);
  }

  public function get($key, $default = null)
  {
    if (null === $this->data)
      $this->open();

    switch (count($parts = explode('.', $key, 2))) {
    case 1:
      if (!array_key_exists($parts[0], $this->data))
        return $default;
      return $this->data[$parts[0]];
    case 2:
      if (!array_key_exists($parts[0], $this->data))
        return $default;
      if (!array_key_exists($parts[1], $this->data[$parts[0]]))
        return $default;
      return $this->data[$parts[0]][$parts[1]];
    }

    return $default;
  }

  public function set($key, $value)
  {
    if (null === $this->data)
      $this->open();

    switch (count($parts = explode('.', $key, 2))) {
    case 1:
      $this->data[$parts[0]] = $value;
      break;
    case 2:
      $this->data[$parts[0]][$parts[1]] = $value;
      break;
    }
  }

  public function save()
  {
    if (null === $this->data)
      $this->open();

    $output = "; vim: set et ts=2 sw=2 sts=2 ft=dosini:\n"
      ."; Written by Molinos CMS\n"
      ."\n" . $this->save_section($this->data);

    foreach ($this->data as $k => $v)
      if ($tmp = $this->save_section($v))
        $output .= "\n[{$k}]\n". $tmp;

    if (!is_writable($this->filename))
      die('unable to write config');

    file_put_contents($this->filename, $output . $sections);
  }

  private function save_section(array $data)
  {
    $output = '';

    foreach ($data as $k => $v)
      if (!empty($v) and !is_array($v))
        $output .= $k .' = '. $v ."\n";

    return $output;
  }
}

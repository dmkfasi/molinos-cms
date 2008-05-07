<?php
// vim: expandtab tabstop=4 shiftwidth=4 softtabstop=4:

require_once('Smarty.class.php');

class SmartyException extends LogicException {}

class BebopSmarty extends Smarty
{
    public function __construct($with_debug = false)
    {
        $this->Smarty();

        if (is_dir($tmp = dirname(__FILE__) .'/plugins')) {
          $plugins = $this->plugins_dir;
          $plugins[] = $tmp;
          $this->plugins_dir = $plugins;
        }

        $tmpdir = mcms::config('tmpdir');

        if (!is_dir($this->compile_dir = $tmpdir .'/smarty_compile_dir'))
            if (!mkdir($this->compile_dir))
                throw new Exception(t('Не удалось создать временный каталог для компиляции шаблонов Smarty.'));

        if (!is_dir($this->cache_dir = $tmpdir .'/smarty_cache_dir'))
            if (!mkdir($this->cache_dir))
                throw new Exception(t('Не удалось создать временный каталог для кэширования шаблонов Smarty.'));

        $this->caching = false;

        if (null !== ($tmp = mcms::config('smarty_cache_lifetime')))
            $this->cache_lifetime = $tmp;

        if ($with_debug and !empty($_GET['smarty_debug']) and (bebop_is_debugger() or in_array('Developers', (array)@$_SESSION['user']['groups'])))
            $this->debugging = true;
        else
            $this->debugging = false;
    }

    public function trigger_error($error_msg, $error_type = E_USER_WARNING)
    {
        throw new SmartyException($error_msg, $error_type);
    }

    // ioncube support
    public function _read_file($filename)
    {
        if (!file_exists($filename) or !is_readable($filename)) {
            return false;
        }

        if (function_exists('ioncube_read_file') and ioncube_file_is_encoded()) {
            $res = ioncube_read_file($filename);

            if (is_int($res)) {
                return false;
            }

            return $res;
        } else {
            return file_get_contents($filename);
        }
    }
}
<?php

class UrlTests extends PHPUnit_Framework_TestCase
{
  public function testBulk()
  {
    $urls = array(
      'http://www.google.com/',
      'https://user:password@gmail.com/inbox/#label',
      '?fid=123&q=attachment.rpc',
      '?action=stop&q=nodeapi.rpc',
      );

    foreach ($urls as $k => $v) {
      if (is_numeric($k))
        $link = strval(new url($v));
      else
        $link = strval(new url($k));
      $this->assertEquals($v, $link);
    }
  }

  public function testCurrent()
  {
    $url1 = '?q=test.rpc&destination=CURRENT';
    $url2 = l($url1);

    $this->assertNotEquals($url1, $url2);
  }

  public function testRedirect()
  {
    url::__setclean(false);
    $urls = array(
      // 'admin?n=1' => mcms::path() .'/'. 'admin?n=1&q=admin',
      'http://ya.ru' => 'http://ya.ru/',
      // mcms::path() => mcms::path().'/'
      );

    foreach ($urls as $k => $v) {
      $url = new url($k);
      $link = $url->getAbsolute();
      $this->assertEquals($v, $link);
    }

    url::__setclean(true);
    $urls = array(
      'admin' => mcms::path().'/admin',
      'http://ya.ru' => 'http://ya.ru/',
      'admin?n=1' => mcms::path().'/admin?n=1'
      );

    foreach ($urls as $k => $v) {
      $tmp = new url($k);
      $link = $tmp->getAbsolute();
      $this->assertEquals($v, $link);
    }
  }
}

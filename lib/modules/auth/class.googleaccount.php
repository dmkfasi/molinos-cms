<?php

/**
 * ---------------------------------------------------------------------
 * Google Account Authentication APIs class
 * ---------------------------------------------------------------------
 * PHP versions 4 and 5
 * ---------------------------------------------------------------------
 * LICENSE: This source file is subject to the GNU Lesser General Public
 * License as published by the Free Software Foundation;
 * either version 2.1 of the License, or any later version
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/licenses/lgpl.html
 * If you did not have a copy of the GNU Lesser General Public License
 * and are unable to obtain it through the web, please write to
 * the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 * ---------------------------------------------------------------------
 *
 * This software has been modified by dmkfasi@gmail.com to fit MCMS project.
 * See http://code.google.com/p/molinos-cms/
 */


/**
 * Google Account Authentication APIs class
 *
 * @version    0.2 (alpha) released 2006/10/12
 * @author     ucb.rcdtokyo http://www.rcdtokyo.com/ucb/
 * @license    GNU LGPL v2.1+ http://www.gnu.org/licenses/lgpl.html
 * @see        http://code.google.com/apis/accounts/Authentication.html
 */
class GoogleAccount
{
  const CLIENTLOGIN_SOURCE = 'mcms';
  public static $accountType = 'HOSTED_OR_GOOGLE';

  /**
   * Current token.
   *
   * @private string
   * @access public
   */
  public $token = null;

  /**
   * Name of the authentication API to acquire current token.
   * The value is either "clientlogin" or "authsub".
   *
   * @private string
   * @access public
   */
  public $authType = null;

  /**
   * Hashed structure available if the response body
   * of the last request is key=value format plain text.
   *
   * @private array
   * @access public
   */
  protected $keyValuePairs = array();

  /**
   * The service name for the ClientLogin authentication.
   *
   * @private string
   * @access protected
   */
  protected $serviceName = 'xapi';

  /**
   * The scope URL for the AuthSub authentication.
   *
   * @private string
   * @access protected
   */
  protected $scopeUrl = null;

  /**
   * __construct instance.
   *
   * @private object
   * @access protected
   */
  protected $request;

  /**
   * An array contains the default parameters of __construct.
   * The allowRedirects shall be always TRUE.
   *
   * @private array
   * @access protected
   */
  protected $requestParams = array('redirects' => 5, 'unrestrictedauth' => true);

  /**
   * Constructor.
   * The first parameter is the service name for the ClientLogin.
   * The second parameter is the scope URL for the AuthSub.
   *
   * @access public
   */
  public function __construct($service_name = null, $scope_url = null)
  {
    if (!empty($service_name)) {
      $this->serviceName = $service_name;
    }
    if (!empty($scope_url)) {
      $this->scopeUrl = $scope_url;
    }
    $this->request = new HttpRequest();
  }

  /**
   * Request ClientLogin token.
   *
   * @param  string  $username
   * @param  string  $password
   * @param  string  $captcha_token
   * @param  string  $captcha_answer
   * @param  string  $account_type
   * @return boolean
   * @access public
   */
  public function requestClientLogin($username, $password, $captcha_token = null, $captcha_answer = null, $account_type = null)
  {
    $this->request->__construct(
  		'https://www.google.com/accounts/ClientLogin',
      HttpRequest::METH_POST,
      $this->requestParams
    );

    $addPostFields = array(
      'Email' => $username,
      'Passwd' => $password,
      'source' => GoogleAccount::CLIENTLOGIN_SOURCE,
      'service' => $this->serviceName
    );

    if (null != $captcha_token) {
      $addPostFields['logintoken'] = $captcha_token;
    }

    if (null != $captcha_answer) {
      $addPostFields['logincaptcha'] = $captcha_answer;
    }

    if (null != $account_type) {
      $addPostFields['accountType'] = $account_type;
    } else {
      $addPostFields['accountType'] = self::$accountType;
    }

    $this->request->addPostFields($addPostFields);
    $this->request->send();
    $this->keyValuePairs = $this->findKeyValuePairs($this->request->getResponseBody());

    switch ($this->request->getResponseCode()) {
      case 200:
        if (!isset($this->keyValuePairs['auth'])) {
          return false;
        }
        $this->token = $this->keyValuePairs['auth'];
        $this->authType = 'clientlogin';
        return true;
        break;
      default:
        return false;
    }
  }

  /**
   * Return the URL of the Google Accounts "Access Request" webpage.
   *
   * @param  string  $next
   * @param  string  $scope_url
   * @param  boolean $session
   * @return string
   * @access public
   */
  public function getAuthSubRequestUrl($next, $scope_url = null, $session = true)
  {
    $url = new url('https://www.google.com/accounts/AuthSubRequest');
    $url->setarg('next', $next);
    $url->setarg('scope', (!empty($scope_url) ? $scope_url : $this->scopeUrl));
    $url->setarg('session', $session ? 1 : 0);

    return strval($url);
  }

  /**
   * Exchange one-time/single-use AuthSub token
   * with multi-use session token.
   *
   * @param  string  $token
   * @return boolean
   * @access public
   */
  public function requestAuthSubSessionToken($token)
  {
    $this->request->__construct(
            'https://www.google.com/accounts/AuthSubSessionToken',
    HttpRequest::METH_GET,
    $this->requestParams
    );
    $this->request->addHeaders(array('Authorization' => "AuthSub token=\"$token\""));
    $this->request->send();
    $this->keyValuePairs = $this->findKeyValuePairs($this->request->getResponseBody());
    switch ($this->request->getResponseCode()) {
      case 200:
        if (!isset($this->keyValuePairs['token'])) {
          return false;
        }
        $this->token = $this->keyValuePairs['token'];
        $this->authType = 'authsub';
        return true;
        break;
      default:
        return false;
    }
  }

  /**
   * Validate AuthSub token.
   *
   * @param  string  $token
   * @return boolean
   * @access public
   */
  public function requestAuthSubTokenInfo($token)
  {
    $this->request->__construct(
            'https://www.google.com/accounts/AuthSubTokenInfo',
    $this->requestParams
    );
    $this->request->addHeader('Authorization', "AuthSub token=\"$token\"");
    $this->request->sendRequest();
    $this->keyValuePairs = $this->findKeyValuePairs($this->request->getResponseBody());
    switch ($this->request->getResponseCode()) {
      case 200:
        return true;
        break;
      default:
        return false;
    }
  }

  /**
   * Revoke AuthSub token.
   *
   * @param  string  $token
   * @return boolean
   * @access public
   */
  public function requestAuthSubRevokeToken($token)
  {
    $this->request->__construct(
            'https://www.google.com/accounts/AuthSubRevokeToken',
    HttpRequest::METH_GET,
    $this->requestParams
    );

    $this->request->addHeaders(array('Authorization' => "AuthSub token=\"{$token}\""));
    $this->request->send();

    switch ($this->request->getResponseCode()) {
      case 200:
        return true;
        break;
      default:
        return false;
    }
  }

  /**
   * Alias to HttpRequest::getResponseCode().
   *
   * @return mixed
   * @access public
   */
  public function getResponseCode()
  {
    return $this->request->getResponseCode();
  }

  /**
   * Alias to HttpRequest::getResponseHeader().
   *
   * @param  string
   * @return mixed
   * @access public
   */
  public function getResponseHeader($name = null)
  {
    return $this->request->getResponseHeader($name);
  }

  /**
   * Alias to HttpRequest::getResponseBody().
   *
   * @return mixed
   * @access public
   */
  public function getResponseBody()
  {
    return $this->request->getResponseBody();
  }

  /**
   * @param  string  $data
   * @return array
   * @access protected
   */
  protected function findKeyValuePairs($data)
  {
    $pairs = array();
    $lines = preg_split('/(?:\r\n|\n|\r)/', $data);
    foreach ($lines as $line) {
      if (preg_match('/^\s*([^\s=]+)\s*=\s*(.+)$/', $line, $matches)) {
        $pairs[strtolower($matches[1])] = trim($matches[2]);
      }
    }
    return $pairs;
  }
}

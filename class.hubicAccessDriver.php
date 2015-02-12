<?php
/*
 * Copyright 2015 Cyril Aknine
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 *
 */
defined('AJXP_EXEC') or die('Access not allowed');
require_once(AJXP_INSTALL_PATH .'/plugins/access.swift/class.swiftAccessDriver.php');

/**
 * AJXP_Plugin to access hubiC servers
 * @package AjaXplorer_Plugins
 * @subpackage Access
 */
class hubicAccessDriver extends swiftAccessDriver
{
    protected $hubicRegisterUri = 'https://api.hubic.com/oauth/auth/';
    protected $hubicCallbackUri = 'https://api.hubic.com/oauth/token/';
    protected $hubicApiUri      = 'https://api.hubic.com/1.0/';

    protected $clientUa = 'DaryL_pydio_';

    public function performChecks()
    {
        // Check CURL, OPENSSL & OPENSTACK LIBRARY & PHP5.3
        if (version_compare(phpversion(), '5.3.0') < 0) {
            throw new Exception('Php version 5.3+ is required for this plugin (must support namespaces)');
        }
        if (!file_exists($this->getBaseDir().'/openstack-sdk-php/vendor/autoload.php')
            && !file_exists($this->getBaseDir().'../access.swift/openstack-sdk-php/vendor/autoload.php')) {
            throw new Exception('You must download the openstack-sdk-php and install it with Composer for this plugin');
        }
        if (!function_exists('curl_version')) {
            throw new Exception('php5-curl extension is required for this plugin');
        }
        if (!OPENSSL_VERSION_TEXT) {
            throw new Exception('PHP OpenSSL extension is required for this plugin');
        }
    }

    public function tokenAction($action, $httpVars, $fileVars)
    {
        if ($action === 'token') {
            if (!empty($httpVars['return'])) {
                if (strpos($httpVars['return'], '?') === 0) {
                    $httpVars['return'] = substr($httpVars['return'], 1);
                }
                $unknownVar = explode('=', $httpVars['return']);
                $httpVars[$unknownVar[0]] = $unknownVar[1];
                unset($unknownVar, $httpVars['return']);
            }
            if (!empty($httpVars['error'])) {
                throw new Exception($httpVars['error'] .' : '. $httpVars['error_description']);
            }
            $internalCredential = $this->clientUa . md5($_SESSION['AJXP_USER']->id);
            if ($httpVars['state'] !== $internalCredential) {
                throw new Exception('Internal credential check error');
            }
            $this->getOAuthToken($httpVars, false);

            if ($this->repository->getOption('CREATE') === true) {
                $this->getAccountProperties('account/credentials', true);

                $token = $_SESSION['PROP_HUBIC_account/credentials']['token'];
                $endpoint = $_SESSION['PROP_HUBIC_account/credentials']['endpoint'];
                $client = \OpenStack\Common\Transport\Guzzle\GuzzleAdapter::create();

                if (!empty($token) && !empty($endpoint)) {
                    $store = new \OpenStack\ObjectStore\v1\ObjectStorage($token, $endpoint, $client);
                } else {
                    throw new \OpenStack\Common\Exception('Missing Token or Endpoint.');
                }

                $store->createContainer($this->repository->getOption('CONTAINER'));
            }

            header('Location: '. $this->siteBaseUrl() .'ws-'. $this->repository->display);
        }
    }

    public function initRepository()
    {
        if (is_array($this->pluginConf)) {
            $this->driverConf = $this->pluginConf;
        } else {
            $this->driverConf = array();
        }

        if (!empty($_SESSION['OAUTH_HUBIC_TOKENS'])) {
            if ($_SESSION['OAUTH_HUBIC_TOKENS']['expires_in'] <= time()) {
                $this->getOAuthToken($this->getAccountProperties('account/credentials'), true);
                $this->getAccountProperties('account/credentials', true);
            }
            return $this->followInitRepository();
        }

        // Loads tokens from file
        $tokens = $this->getTokens();
        if (!empty($tokens)) {
            $_SESSION['OAUTH_HUBIC_TOKENS'] = $tokens;
            if ($_SESSION['OAUTH_HUBIC_TOKENS']['expires_in'] <= time()) {
                $this->getOAuthToken($this->getAccountProperties('account/credentials'), true);
                $this->getAccountProperties('account/credentials', true);
            }
            return $this->followInitRepository();
        }

        if (!empty($_GET['get_action']) && $_GET['get_action'] === 'token') {
            return;
        }

        $hubicAuthUri = $this->hubicRegisterUri
            .'?client_id='. $this->repository->getOption('CLIENT_ID')
            .'&redirect_uri='. urlencode(
                $this->siteBaseUrl() .
                'index.php?secure_token='. $_SESSION['SECURE_TOKEN'] .
                '&get_action=token&tmp_repository_id='. $this->repository->getId() .'&return='
            )
            .'&scope=usage.r,account.r,getAllLinks.r,credentials.r,sponsorCode.r,activate.w,sponsored.r,links.drw'
            .'&response_type=code'
            .'&state='. $this->clientUa . md5($_SESSION['AJXP_USER']->id);

        throw new Exception(
            'Please go to <a style="text-decoration:underline;" href="'
            . $hubicAuthUri
            .'">HubiC Authentication Service</a> to authorize the access to your HubiC. '
            .'Then try again to switch to this repository.'
        );
    }

    private function followInitRepository()
    {
        $this->getAccountProperties('account/credentials');
        $autoload = $this->getBaseDir().'/openstack-sdk-php/vendor/autoload.php';
        if (!file_exists($autoload)) {
            $autoload = $this->getBaseDir().'../access.swift/openstack-sdk-php/vendor/autoload.php';
            if (!file_exists($autoload)) {
                throw new Exception(
                    'You must download the openstack-sdk-php and install it with Composer for this plugin'
                );
            }
        }
        require_once($autoload);
        require_once($this->getBaseDir() .'/HubicBootstrap.php');

        \OpenStack\HubicBootstrap::useStreamWrappers();

        \OpenStack\HubicBootstrap::setConfiguration(array(
            'token' => $_SESSION['PROP_HUBIC_account/credentials']['token'],
            'swift_endpoint' => $_SESSION['PROP_HUBIC_account/credentials']['endpoint'],
        ));

//        $path = $this->repository->getOption('PATH');
        $recycle = $this->repository->getOption('RECYCLE_BIN');
        ConfService::setConf('PROBE_REAL_SIZE', false);
        $wrapperData = $this->detectStreamWrapper(true);
        $this->wrapperClassName = $wrapperData['classname'];
        $this->urlBase = $wrapperData['protocol'] .'://'. $this->repository->getId();

        if ($recycle != '') {
            RecycleBinManager::init($this->urlBase, '/'. $recycle);
        }

        return true;
    }

    public function getTokens()
    {
        if ($_SESSION['OAUTH_HUBIC_TOKENS'] !== null
            && is_array($_SESSION['OAUTH_HUBIC_TOKENS'])) {
            return $_SESSION['OAUTH_HUBIC_TOKENS'];
        }

        $_SESSION['OAUTH_HUBIC_TOKENS'] = AJXP_Utils::loadSerialFile(
            AJXP_DATA_PATH .'/plugins/access.hubic/'. $this->repository->getId() .'_tokens'
        );

        return $_SESSION['OAUTH_HUBIC_TOKENS'];
    }

    public function setTokens($oauthTokens)
    {
        $tokensCfgFile = AJXP_DATA_PATH .'/plugins/access.hubic/'. $this->repository->getId() .'_tokens';

        $oldTokens = AJXP_Utils::loadSerialFile($tokensCfgFile);

        $_SESSION['OAUTH_HUBIC_TOKENS'] = array_merge($oldTokens, $oauthTokens);

        AJXP_Utils::saveSerialFile($tokensCfgFile, $_SESSION['OAUTH_HUBIC_TOKENS'], true);
    }

    private function getOAuthToken($vars, $renew = false)
    {
        $curl = curl_init($this->hubicCallbackUri);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Basic '. base64_encode(
                $this->repository->getOption('CLIENT_ID') .':'. $this->repository->getOption('CLIENT_SECRET')
            )
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        if ($renew === false) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, array(
                'code' => $vars['code'],
                'redirect_uri' => $this->siteBaseUrl() .'ws-'. $this->repository->display,
                'grant_type' => 'authorization_code'
            ));
        } else {
            if (empty($_SESSION['OAUTH_HUBIC_TOKENS']['refresh_token'])) {
                throw new Exception('Unknown Refresh Token');
            }
            curl_setopt($curl, CURLOPT_POSTFIELDS, array(
                'refresh_token' => $_SESSION['OAUTH_HUBIC_TOKENS']['refresh_token'],
                'grant_type' => 'refresh_token'
            ));
        }
        //Header not accepted by hubiC. Bad documentation guys !
//        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 40);

        $result = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseCode !== 200) {
            throw new Exception('Error ('. $responseCode .') : '. $error .' / '. $result);
        }

        $result = \json_decode($result, true);

        if ($result['token_type'] !== 'Bearer') {
            throw new Exception('Invalid Token Type: '. $result['token_type']);
        }

        $result['expires_in'] = $result['expires_in']+time();

        $this->setTokens($result);
    }

    private function retrieveAccountProperties($object = 'account')
    {
        $curl = curl_init($this->hubicApiUri . $object);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer '. $_SESSION['OAUTH_HUBIC_TOKENS']['access_token']
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 40);

        $result = curl_exec($curl);
        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseCode !== 200) {
            throw new Exception('Error ('. $responseCode .') : '. $error .' / '. $result);
        }

        $result = \json_decode($result, true);

        $this->setAccountProperties($result, $object);

        return $result;
    }

    public function setAccountProperties($properties, $object)
    {
        $_SESSION['PROP_HUBIC_'. $object] = $properties;
        AJXP_Utils::saveSerialFile(
            AJXP_DATA_PATH .'/plugins/access.hubic/'. $this->repository->getId() .'_'. str_replace('/', '_', $object),
            $properties,
            true
        );
    }

    public function getAccountProperties($object, $refresh = false)
    {
        if ($_SESSION['PROP_HUBIC_'. $object] !== null
            && is_array($_SESSION['PROP_HUBIC_'. $object])
            && $refresh !== true) {
            return $this->repository->getOption('PROP_HUBIC_'. $object);
        }

        $fileObject = AJXP_DATA_PATH .'/plugins/access.hubic/'. $this->repository->getId() .'_'.
            str_replace('/', '_', $object);
        if (!file_exists($fileObject) || $refresh === true) {
            $_SESSION['PROP_HUBIC_'. $object] = $this->retrieveAccountProperties($object);
        } else {
            $_SESSION['PROP_HUBIC_'. $object] = AJXP_Utils::loadSerialFile($fileObject);
        }

        return $_SESSION['PROP_HUBIC_'. $object];
    }

    private function siteBaseUrl()
    {
        $protocol = 'http';
        $port = '';
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
            $protocol = 'https';
        }
        if (!empty($_SERVER['SERVER_PORT'])
            && (($protocol === 'http' && $_SERVER['SERVER_PORT'] != '80')
            || ($protocol === 'https' && $_SERVER['SERVER_PORT'] != '443'))
            && empty($_SERVER['HTTP_X_FORWARDED_PROTO']) //no way to find port behind reverse proxy
            && strpos($_SERVER['HTTP_HOST'], ':'. $_SERVER['SERVER_PORT']) === false) {
            $port = ':'. $_SERVER['SERVER_PORT'];
        }

        return $protocol .'://'. $_SERVER['HTTP_HOST'] . $port .'/';
    }
}

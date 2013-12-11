<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Authentication.openid
 *
 * @copyright   Copyright (C) 2005 - 2010 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * OpenID Authentication Plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  Authentication.openid
 * @since       1.5
 */
class PlgAuthenticationOpenID extends JPlugin
{
	/**
	 * This method should handle any authentication and report back to the subject
	 *
	 * @param   array   $credentials  Array holding the user credentials
	 * @param   array   $options      Array of extra options
	 * @param   object  &$response    Authentication response object
	 *
	 * @return  boolean
	 *
	 * @since   1.5
	 */
	public function onUserAuthenticate($credentials, $options, &$response)
	{
		$mainframe =& JFactory::getApplication();

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			define('Auth_OpenID_RAND_SOURCE', null);
		} else {
			$f = @fopen('/dev/urandom', 'r');
			if ($f !== false) {
				define('Auth_OpenID_RAND_SOURCE', '/dev/urandom');
				fclose($f);
			} else {
				$f = @fopen('/dev/random', 'r');
				if ($f !== false) {
					define('Auth_OpenID_RAND_SOURCE', '/dev/urandom');
					fclose($f);
				} else {
					define('Auth_OpenID_RAND_SOURCE', null);
				}
			}
		}

		jimport('joomla.filesystem.folder');

		require_once 'common.php';

		// Access the session data
		$session = & JFactory :: getSession();

		// Create and/or start using the data store
		$store_path = JPATH_ROOT . '/tmp/_joomla_openid_store';
		if (!JFolder :: exists($store_path) && !JFolder :: create($store_path)) {
			$response->status = JAuthentication::STATUS_FAILURE;
			$response->error_message = "Could not create the FileStore directory '$store_path'. " . " Please check the effective permissions.";
			return false;
		}

		// Create store object
		$store = new Auth_OpenID_FileStore($store_path);

		// Create a consumer object
		$consumer = new Auth_OpenID_Consumer($store);

		if (!isset ($_SESSION['_openid_consumer_last_token'])) {
			// Begin the OpenID authentication process.
			if (!$auth_request = $consumer->begin($credentials['username'])) {
				$response->status = JAuthentication::STATUS_FAILURE;
				$response->error_message = 'Authentication error : could not connect to the openid server';
				return false;
			}

			$sreg_request = Auth_OpenID_SRegRequest::build(
				array ('email'),
				array ('fullname','language','timezone')
			);

			if ($sreg_request) {
				$auth_request->addExtension($sreg_request);
			}

			$policy_uris = array();

			$pape_request = new Auth_OpenID_PAPE_Request($policy_uris);
			if ($pape_request) {
				$auth_request->addExtension($pape_request);
			}

			//Create the entry url
			$entry_url = isset($options['entry_url']) ? $options['entry_url'] : JURI :: base();
			$entry_url = JURI :: getInstance($entry_url);

			unset ($options['entry_url']); //We don't need this anymore

			//Create the url query information
			$options['return'] = isset($options['return']) ? base64_encode($options['return']) : base64_encode(JURI::base());
			$options[JSession::getFormToken()] = 1;

			$process_url  = sprintf($entry_url->toString()."&username=%s", $credentials['username']);
			$process_url .= '&'.JURI::buildQuery($options);

			$session->set('return_url', $process_url);

			$trust_url = $entry_url->toString(array (
				'path',
				'host',
				'port',
				'scheme'
			));
			$session->set('trust_url', $trust_url);
			// For OpenID 1, send a redirect.  For OpenID 2, use a Javascript
			// form to send a POST request to the server.
			if ($auth_request->shouldSendRedirect()) {
				$redirect_url = $auth_request->redirectURL($trust_url, $process_url);

				// If the redirect URL can't be built, display an error
				// message.
				if (Auth_OpenID :: isFailure($redirect_url)) {
					$response->status = JAuthentication::STATUS_FAILURE;
					$response->error_message = 'Authentication error : could not connect to the openid server';
					return false;
				} else {
					// Send redirect.
					$mainframe->redirect($redirect_url);
					return false;
				}
			} else {
				// Generate form markup and render it.
				$form_id = 'openid_message';
				$form_html = $auth_request->htmlMarkup($trust_url, $process_url, false, array (
					'id' => $form_id
				));
				// Display an error if the form markup couldn't be generated;
				// otherwise, render the HTML.
				if (Auth_OpenID :: isFailure($form_html)) {
					$response->status = JAuthentication::STATUS_FAILURE;
					$response->error_message = 'Authentication error : Could not redirect to server: ' . $form_html->message;
				} else {
					JResponse :: setBody($form_html);
					echo JResponse :: toString($mainframe->getCfg('gzip'));
					$mainframe->close();
					return false;
				}
			}
		}

		$result = $consumer->complete($session->get('return_url'));
		switch ($result->status) {
			case Auth_OpenID_SUCCESS :
				{
			        $sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($result);

    				$sreg = $sreg_resp->contents();
					$usermode = $this->params->get('usermode', 2);
					/* in the following code, we deal with the transition from the old openid version to the new openid version
					   In the old version, the username was always taken straight from the login form.  In the new version, we get a
					   username back from the openid provider.  This is necessary for a number of reasons.  First, providers such as
					   yahoo.com allow you to enter only the provider name in the username field (i.e. yahoo.com or flickr.com).  Taking
					   this as the username would obviously cause problems because everybody who had an id from yahoo.com would have username
					   yahoo.com.  Second, it is necessary because with the old way, we rely on the user entering the id the same every time.
					   This is bad because if the user enters the http:// one time and not the second time, they end up as two different users.
					   There are two possible settings here - the first setting, is to always use the new way, which is to get the username from
					   the provider after authentication.  The second setting is to check if the username exists that we got from the provider.  If it
					   doesn't, then we check if the entered username exists.  If it does, then we update the database with the username from the provider
					   and continue happily along with the new username.
					   We had talked about a third option, which would be to always used the old way, but that seems insecure in the case of somebody using
					   a yahoo.com ID.
					*/
					if ($usermode && $usermode == 1) {
						$response->username = $result->getDisplayIdentifier();
					} else {
						// first, check if the provider provided username exists in the database
						$db = &JFactory::getDBO();
						$query = 'SELECT username FROM #__users'.
							' WHERE username='.$db->Quote($result->getDisplayIdentifier()).
							' AND password=\'\'';
						$db->setQuery($query);
						$dbresult = $db->loadObject();
						if ($dbresult) {
							// if so, we set our username value to the provided value
							$response->username = $result->getDisplayIdentifier();
						} else {
							// if it doesn't, we check if the username from the from exists in the database
							$query = 'SELECT username FROM #__users'.
								' WHERE username='.$db->Quote( $credentials['username'] ).
								' AND password=\'\'';
							$db->setQuery($query);
							$dbresult = $db->loadObject();
							if ($dbresult) {
								// if it does, we update the database
								$query = 'UPDATE #__users SET username='.$db->Quote($result->getDisplayIdentifier()).
									' WHERE username='.$db->Quote($credentials['username']);
								$db->setQuery($query);
								$db->query();
								if (!$db->query()) {
									$response->status = JAuthentication::STATUS_FAILURE;
									$response->error_message = $db->getErrorMsg();
									//break out of the switch if we hit an error with our query
									break;
								}
							}
							$response->username = $result->getDisplayIdentifier();
							// we return the username provided by the openid provider
						}
					}
					$response->status = JAuthentication::STATUS_SUCCESS;
					$response->error_message = '';
					if (!isset($sreg['email'])) {
						$response->email = str_replace( array('http://', 'https://'), '', $response->username );
						$response->email = str_replace( '/', '-', $response->email );
						$response->email .= '@openid.';
					} else {
						$response->email = $sreg['email'];
					}
					$response->fullname = isset ($sreg['fullname']) ? $sreg['fullname'] : $response->username;
					$response->language = isset ($sreg['language']) ? $sreg['language'] : '';
					$response->timezone = isset ($sreg['timezone']) ? $sreg['timezone'] : '';
				}
				break;

			case Auth_OpenID_CANCEL :
				{
					$response->status = JAuthentication::STATUS_CANCEL;
					$response->error_message = 'Authentication cancelled';
				}
				break;

			case Auth_OpenID_FAILURE :
				{
					$response->status = JAuthentication::STATUS_FAILURE;
					$response->error_message = 'Authentication failed';
				}
				break;
		}
	}
}

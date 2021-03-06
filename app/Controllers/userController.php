<?php

/**
 * Controller to handle user actions.
 */
class FreshRSS_user_Controller extends Minz_ActionController {
	// Will also have to be computed client side on mobile devices,
	// so do not use a too high cost
	const BCRYPT_COST = 9;

	/**
	 * This action is called before every other action in that class. It is
	 * the common boiler plate for every action. It is triggered by the
	 * underlying framework.
	 *
	 * @todo clean up the access condition.
	 */
	public function firstAction() {
		if (!FreshRSS_Auth::hasAccess() && !(
				Minz_Request::actionName() === 'create' &&
				!max_registrations_reached()
		)) {
			Minz_Error::error(403);
		}
	}

	public static function hashPassword($passwordPlain) {
		if (!function_exists('password_hash')) {
			include_once(LIB_PATH . '/password_compat.php');
		}
		$passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT, array('cost' => self::BCRYPT_COST));
		$passwordPlain = '';
		$passwordHash = preg_replace('/^\$2[xy]\$/', '\$2a\$', $passwordHash);	//Compatibility with bcrypt.js
		return $passwordHash == '' ? '' : $passwordHash;
	}

	/**
	 * The username is also used as folder name, file name, and part of SQL table name.
	 * '_' is a reserved internal username.
	 */
	const USERNAME_PATTERN = '[0-9a-zA-Z]|[0-9a-zA-Z_]{2,38}';

	public static function checkUsername($username) {
		return preg_match('/^' . self::USERNAME_PATTERN . '$/', $username) === 1;
	}

	/**
	 * This action displays the user profile page.
	 */
	public function profileAction() {
		Minz_View::prependTitle(_t('conf.profile.title') . ' · ');

		Minz_View::appendScript(Minz_Url::display(
			'/scripts/bcrypt.min.js?' . @filemtime(PUBLIC_PATH . '/scripts/bcrypt.min.js')
		));

		if (Minz_Request::isPost()) {
			$ok = true;

			$passwordPlain = Minz_Request::param('newPasswordPlain', '', true);
			if ($passwordPlain != '') {
				Minz_Request::_param('newPasswordPlain');	//Discard plain-text password ASAP
				$_POST['newPasswordPlain'] = '';
				$passwordHash = self::hashPassword($passwordPlain);
				$ok &= ($passwordHash != '');
				FreshRSS_Context::$user_conf->passwordHash = $passwordHash;
			}
			Minz_Session::_param('passwordHash', FreshRSS_Context::$user_conf->passwordHash);

			$passwordPlain = Minz_Request::param('apiPasswordPlain', '', true);
			if ($passwordPlain != '') {
				$passwordHash = self::hashPassword($passwordPlain);
				$ok &= ($passwordHash != '');
				FreshRSS_Context::$user_conf->apiPasswordHash = $passwordHash;
			}

			$ok &= FreshRSS_Context::$user_conf->save();

			if ($ok) {
				Minz_Request::good(_t('feedback.profile.updated'),
				                   array('c' => 'user', 'a' => 'profile'));
			} else {
				Minz_Request::bad(_t('feedback.profile.error'),
				                  array('c' => 'user', 'a' => 'profile'));
			}
		}
	}

	/**
	 * This action displays the user management page.
	 */
	public function manageAction() {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
		}

		Minz_View::prependTitle(_t('admin.user.title') . ' · ');

		// Get the correct current user.
		$username = Minz_Request::param('u', Minz_Session::param('currentUser'));
		if (!FreshRSS_UserDAO::exist($username)) {
			$username = Minz_Session::param('currentUser');
		}
		$this->view->current_user = $username;

		// Get information about the current user.
		$entryDAO = FreshRSS_Factory::createEntryDao($this->view->current_user);
		$this->view->nb_articles = $entryDAO->count();
		$this->view->size_user = $entryDAO->size();
	}

	public static function createUser($new_user_name, $passwordPlain, $apiPasswordPlain, $userConfig = array(), $insertDefaultFeeds = true) {
		if (!is_array($userConfig)) {
			$userConfig = array();
		}

		$ok = self::checkUsername($new_user_name);
		$homeDir = join_path(DATA_PATH, 'users', $new_user_name);

		if ($ok) {
			$languages = Minz_Translate::availableLanguages();
			if (empty($userConfig['language']) || !in_array($userConfig['language'], $languages)) {
				$userConfig['language'] = 'en';
			}

			$ok &= !in_array(strtoupper($new_user_name), array_map('strtoupper', listUsers()));	//Not an existing user, case-insensitive

			$configPath = join_path($homeDir, 'config.php');
			$ok &= !file_exists($configPath);
		}
		if ($ok) {
			$passwordHash = '';
			if ($passwordPlain != '') {
				$passwordHash = self::hashPassword($passwordPlain);
				$ok &= ($passwordHash != '');
			}

			$apiPasswordHash = '';
			if ($apiPasswordPlain != '') {
				$apiPasswordHash = self::hashPassword($apiPasswordPlain);
				$ok &= ($apiPasswordHash != '');
			}
		}
		if ($ok) {
			if (!is_dir($homeDir)) {
				mkdir($homeDir);
			}
			$userConfig['passwordHash'] = $passwordHash;
			$userConfig['apiPasswordHash'] = $apiPasswordHash;
			$ok &= (file_put_contents($configPath, "<?php\n return " . var_export($userConfig, true) . ';') !== false);
		}
		if ($ok) {
			$userDAO = new FreshRSS_UserDAO();
			$ok &= $userDAO->createUser($new_user_name, $userConfig['language'], $insertDefaultFeeds);
		}
		return $ok;
	}

	/**
	 * This action creates a new user.
	 *
	 * Request parameters are:
	 *   - new_user_language
	 *   - new_user_name
	 *   - new_user_passwordPlain
	 *   - r (i.e. a redirection url, optional)
	 *
	 * @todo clean up this method. Idea: write a method to init a user with basic information.
	 * @todo handle r redirection in Minz_Request::forward directly?
	 */
	public function createAction() {
		if (Minz_Request::isPost() && (
				FreshRSS_Auth::hasAccess('admin') ||
				!max_registrations_reached()
		)) {
			$new_user_name = Minz_Request::param('new_user_name');
			$passwordPlain = Minz_Request::param('new_user_passwordPlain', '', true);
			$new_user_language = Minz_Request::param('new_user_language', FreshRSS_Context::$user_conf->language);

			$ok = self::createUser($new_user_name, $passwordPlain, '', array('language' => $new_user_language));
			Minz_Request::_param('new_user_passwordPlain');	//Discard plain-text password ASAP
			$_POST['new_user_passwordPlain'] = '';
			invalidateHttpCache();

			$notif = array(
				'type' => $ok ? 'good' : 'bad',
				'content' => _t('feedback.user.created' . (!$ok ? '.error' : ''), $new_user_name)
			);
			Minz_Session::_param('notification', $notif);
		}

		$redirect_url = urldecode(Minz_Request::param('r', false, true));
		if (!$redirect_url) {
			$redirect_url = array('c' => 'user', 'a' => 'manage');
		}
		Minz_Request::forward($redirect_url, true);
	}

	public static function deleteUser($username) {
		$db = FreshRSS_Context::$system_conf->db;
		require_once(APP_PATH . '/SQL/install.sql.' . $db['type'] . '.php');

		$ok = self::checkUsername($username);
		if ($ok) {
			$default_user = FreshRSS_Context::$system_conf->default_user;
			$ok &= (strcasecmp($username, $default_user) !== 0);	//It is forbidden to delete the default user
		}
		$user_data = join_path(DATA_PATH, 'users', $username);
		if ($ok) {
			$ok &= is_dir($user_data);
		}
		if ($ok) {
			$userDAO = new FreshRSS_UserDAO();
			$ok &= $userDAO->deleteUser($username);
			$ok &= recursive_unlink($user_data);
		}
		return $ok;
	}

	/**
	 * This action delete an existing user.
	 *
	 * Request parameter is:
	 *   - username
	 *
	 * @todo clean up this method. Idea: create a User->clean() method.
	 */
	public function deleteAction() {
		$username = Minz_Request::param('username');
		$redirect_url = urldecode(Minz_Request::param('r', false, true));
		if (!$redirect_url) {
			$redirect_url = array('c' => 'user', 'a' => 'manage');
		}

		$self_deletion = Minz_Session::param('currentUser', '_') === $username;

		if (Minz_Request::isPost() && (
				FreshRSS_Auth::hasAccess('admin') ||
				$self_deletion
		)) {
			$ok = true;
			if ($ok && $self_deletion) {
				// We check the password if it's a self-destruction
				$nonce = Minz_Session::param('nonce');
				$challenge = Minz_Request::param('challenge', '');

				$ok &= FreshRSS_FormAuth::checkCredentials(
					$username, FreshRSS_Context::$user_conf->passwordHash,
					$nonce, $challenge
				);
			}
			if ($ok) {
				$ok &= self::deleteUser($username);
			}
			if ($ok && $self_deletion) {
				FreshRSS_Auth::removeAccess();
				$redirect_url = array('c' => 'index', 'a' => 'index');
			}
			invalidateHttpCache();

			$notif = array(
				'type' => $ok ? 'good' : 'bad',
				'content' => _t('feedback.user.deleted' . (!$ok ? '.error' : ''), $username)
			);
			Minz_Session::_param('notification', $notif);
		}

		Minz_Request::forward($redirect_url, true);
	}
}

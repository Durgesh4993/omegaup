<?php

 namespace OmegaUp\Controllers;

/**
 * Description:
 *     Session controller handles sessions.
 *
 * Author:
 *     Alan Gonzalez alanboy@alanboy.net
 *
 */
class Session extends \OmegaUp\Controllers\Controller {
    const AUTH_TOKEN_ENTROPY_SIZE = 15;
    /** @var null|array{valid: bool, email: string|null, user: \OmegaUp\DAO\VO\Users|null, identity: \OmegaUp\DAO\VO\Identities|null, auth_token: string|null, is_admin: bool} */
    private static $_currentSession = null;
    private static $_facebook;
    /** @var null|\OmegaUp\SessionManager */
    private static $_sessionManager = null;
    /** @var bool */
    private static $_setCookieOnRegisterSession = true;

    public static function getSessionManagerInstance() : \OmegaUp\SessionManager {
        if (is_null(self::$_sessionManager)) {
            self::$_sessionManager = new \OmegaUp\SessionManager();
        }
        return self::$_sessionManager;
    }

    private static function getFacebookInstance() {
        if (is_null(self::$_facebook)) {
            require_once 'libs/third_party/facebook-php-graph-sdk/src/Facebook/autoload.php';

            self::$_facebook = new \Facebook\Facebook([
                'app_id' => OMEGAUP_FB_APPID,
                'app_secret' => OMEGAUP_FB_SECRET,
                'default_graph_version' => 'v2.5',
            ]);
        }
        return self::$_facebook;
    }

    public static function getFacebookLoginUrl() {
        $facebook = self::getFacebookInstance();

        $helper = $facebook->getRedirectLoginHelper();
        return $helper->getLoginUrl(OMEGAUP_URL.'/login?fb', ['email']);
    }

    private static function isAuthTokenValid($authToken) {
        //do some other basic testing on authToken
        return true;
    }

    public static function currentSessionAvailable() : bool {
        return self::apiCurrentSession()['session']['valid'];
    }

    /**
     * Returns information about current session. In order to avoid one full
     * server roundtrip (about ~100msec on each pageload), it also returns the
     * current time to be able to calculate the time delta between the
     * contestant's machine and the server.
     *
     * @return array
     * @psalm-return array{status: string, session: null|array{valid: bool, email: string|null, user: \OmegaUp\DAO\VO\Users|null, identity: \OmegaUp\DAO\VO\Identities|null, auth_token: string|null, is_admin: bool}, time: int}
     */
    public static function apiCurrentSession(?\OmegaUp\Request $r = null) : array {
        if (defined('OMEGAUP_SESSION_CACHE_ENABLED') &&
            OMEGAUP_SESSION_CACHE_ENABLED === true &&
            !is_null(self::$_currentSession)
        ) {
            return [
                'status' => 'ok',
                'session' => self::$_currentSession,
                'time' => \OmegaUp\Time::get(),
            ];
        }
        if (is_null($r)) {
            $r = new \OmegaUp\Request();
        }
        if (is_null($r['auth_token'])) {
            $authToken = self::getAuthToken($r);
            $r['auth_token'] = $authToken;
        } else {
            $authToken = strval($r['auth_token']);
        }
        if (defined('OMEGAUP_SESSION_CACHE_ENABLED') &&
            OMEGAUP_SESSION_CACHE_ENABLED === true &&
            !is_null($authToken)
        ) {
            /** @var array{valid: bool, email: string|null, user: \OmegaUp\DAO\VO\Users|null, identity: \OmegaUp\DAO\VO\Identities|null, auth_token: string|null, is_admin: bool} */
            self::$_currentSession = \OmegaUp\Cache::getFromCacheOrSet(
                \OmegaUp\Cache::SESSION_PREFIX,
                $authToken,
                function () use ($r) {
                    return self::getCurrentSession($r);
                },
                APC_USER_CACHE_SESSION_TIMEOUT
            );
        } else {
            self::$_currentSession = self::getCurrentSession($r);
        }
        return [
            'status' => 'ok',
            'session' => self::$_currentSession,
            'time' => \OmegaUp\Time::get(),
        ];
    }

    private static function getAuthToken(\OmegaUp\Request $r) : ?string {
        $SessionM = self::getSessionManagerInstance();
        $SessionM->sessionStart();
        $authToken = null;
        if (!is_null($r['auth_token'])) {
            $authToken = strval($r['auth_token']);
        } else {
            $authToken = $SessionM->getCookie(OMEGAUP_AUTH_TOKEN_COOKIE_NAME);
        }
        if (!is_null($authToken) && self::isAuthTokenValid($authToken)) {
            return $authToken;
        }
        if (isset($_REQUEST[OMEGAUP_AUTH_TOKEN_COOKIE_NAME])
                && self::isAuthTokenValid($_REQUEST[OMEGAUP_AUTH_TOKEN_COOKIE_NAME])) {
            return strval($_REQUEST[OMEGAUP_AUTH_TOKEN_COOKIE_NAME]);
        }
        return null;
    }

    /**
     * @return array
     * @psalm-return array{valid: bool, email: string|null, user: \OmegaUp\DAO\VO\Users|null, identity: \OmegaUp\DAO\VO\Identities|null, auth_token: string|null, is_admin: bool}
     */
    public static function getCurrentSession(\OmegaUp\Request $r) : array {
        if (empty($r['auth_token'])) {
            return [
                'valid' => false,
                'email' => null,
                'user' => null,
                'identity' => null,
                'auth_token' => null,
                'is_admin' => false,
            ];
        }
        $authToken = strval($r['auth_token']);

        $currentIdentity = \OmegaUp\DAO\AuthTokens::getIdentityByToken($authToken);
        if (is_null($currentIdentity)) {
            // Means user has auth token, but does not exist in DB
            return [
                'valid' => false,
                'email' => null,
                'user' => null,
                'identity' => null,
                'auth_token' => null,
                'is_admin' => false,
            ];
        }

        if (is_null($currentIdentity->user_id)) {
            $currentUser = null;
            $email = null;
        } else {
            $currentUser = \OmegaUp\DAO\Users::getByPK($currentIdentity->user_id);
            if (is_null($currentUser)) {
                throw new \OmegaUp\Exceptions\NotFoundException('userNotFound');
            }
            $email = !is_null($currentUser->main_email_id) ?
                \OmegaUp\DAO\Emails::getByPK($currentUser->main_email_id) :
                null;
        }

        return [
            'valid' => true,
            'email' => !empty($email) ? $email->email : '',
            'user' => $currentUser,
            'identity' => $currentIdentity,
            'auth_token' => $authToken,
            'is_admin' => \OmegaUp\Authorization::isSystemAdmin($currentIdentity),
        ];
    }

    /**
     * Invalidates the current user's session cache.
     */
    public static function invalidateCache() : void {
        $currentSession = self::apiCurrentSession()['session'];
        if (is_null($currentSession['auth_token'])) {
            return;
        }
        \OmegaUp\Cache::deleteFromCache(\OmegaUp\Cache::SESSION_PREFIX, $currentSession['auth_token']);
    }

    /**
     * Invalidates the current request's session cache.
     */
    public static function invalidateLocalCache() : void {
        self::$_currentSession = null;
    }

    public function UnRegisterSession() {
        self::invalidateCache();

        $currentSession = self::apiCurrentSession()['session'];
        $authToken = new \OmegaUp\DAO\VO\AuthTokens(['token' => $currentSession['auth_token']]);

        self::invalidateLocalCache();

        try {
            \OmegaUp\DAO\AuthTokens::delete($authToken);
        } catch (\Exception $e) {
            // Best effort
            self::$log->error("Failed to delete expired tokens: {$e->getMessage()}");
        }

        setcookie(OMEGAUP_AUTH_TOKEN_COOKIE_NAME, 'deleted', 1, '/');
    }

    private function registerSession(\OmegaUp\DAO\VO\Identities $identity) : string {
        // Log the login.
        \OmegaUp\DAO\IdentityLoginLog::create(new \OmegaUp\DAO\VO\IdentityLoginLog([
            'identity_id' => $identity->identity_id,
            'ip' => ip2long($_SERVER['REMOTE_ADDR']),
        ]));

        self::invalidateLocalCache();

        //erase expired tokens
        try {
            \OmegaUp\DAO\AuthTokens::expireAuthTokens($identity->identity_id);
        } catch (\Exception $e) {
            // Best effort
            self::$log->error("Failed to delete expired tokens: {$e->getMessage()}");
        }

        // Create the new token
        $entropy = bin2hex(random_bytes(self::AUTH_TOKEN_ENTROPY_SIZE));
        $hash = hash('sha256', OMEGAUP_MD5_SALT . $identity->identity_id . $entropy);
        $token = "{$entropy}-{$identity->identity_id}-{$hash}";

        \OmegaUp\DAO\AuthTokens::replace(new \OmegaUp\DAO\VO\AuthTokens([
            'user_id' => $identity->user_id,
            'identity_id' => $identity->identity_id,
            'token' => $token,
        ]));

        if (self::$_setCookieOnRegisterSession) {
            $this->getSessionManagerInstance()->setCookie(OMEGAUP_AUTH_TOKEN_COOKIE_NAME, $token, 0, '/');
        }

        \OmegaUp\Cache::deleteFromCache(\OmegaUp\Cache::SESSION_PREFIX, $token);
        return $token;
    }

    private static function getUniqueUsernameFromEmail($s_Email) {
        $idx = strpos($s_Email, '@');
        $username = substr($s_Email, 0, $idx);

        try {
            \OmegaUp\Validators::validateValidUsername($username, 'username');
        } catch (\OmegaUp\Exceptions\InvalidParameterException $e) {
            // How can we know whats wrong with the username?
            // Things that could go wrong:
            //      generated email is too short
            $username = 'OmegaupUser';
        }

        $suffix = '';
        for (;;) {
            // Maybe we can bring all records from db
            // with prefix $username, beacuse this:
            $userexists = \OmegaUp\DAO\Users::FindByUsername($username . $suffix);
            // will query db every single time probably.

            if (empty($userexists)) {
                break;
            }

            if (empty($suffix)) {
                $suffix = 1;
            } else {
                $suffix++;
            }
        }
        return $username . $suffix;
    }

    public static function apiGoogleLogin(\OmegaUp\Request $r = null) {
        if (is_null($r['storeToken'])) {
            throw new \OmegaUp\Exceptions\InvalidParameterException('parameterNotFound', 'storeToken');
        }

        require_once 'libs/third_party/google-api-php-client/src/Google/autoload.php';

        $client = new Google_Client();
        $client->setClientId(OMEGAUP_GOOGLE_CLIENTID);
        $client->setClientSecret(OMEGAUP_GOOGLE_SECRET);

        try {
            $loginTicket = $client->verifyIdToken($r['storeToken']);
        } catch (Google_Auth_Exception $ge) {
            throw new \OmegaUp\Exceptions\UnauthorizedException('loginRequired', $ge);
        }

        $payload = $loginTicket->getAttributes()['payload'];

        // payload will have a superset of:
        //    [email] => johndoe@gmail.com
        //    [email_verified] => 1
        //    [name] => Alan Gonzalez
        //    [picture] => https://lh3.googleusercontent.com/-zrLvBe-AU/AAAAAAAAAAI/AAAAAAAAATU/hh0yUXEisCI/photo.jpg
        //    [locale] => en

        $controller = new \OmegaUp\Controllers\Session();
        $controller->LoginViaGoogle(
            $payload['email'],
            (isset($payload['name']) ? $payload['name'] : null)
        );

        return ['status' => 'ok'];
    }

    public function LoginViaGoogle($email, $name = null) {
        return $this->ThirdPartyLogin('Google', $email, $name);
    }

    /**
     * Logs in via Facebook API.
     *
     * @return array An associative array with a 'status' field that has 'ok'
     *               on success or 'error' on error. An 'error' field with an
     *               i18n string may also appear on the response.
     */
    public function LoginViaFacebook() {
        // Mostly taken from
        // https://developers.facebook.com/docs/php/howto/example_facebook_login
        $facebook = self::getFacebookInstance();

        $helper = $facebook->getRedirectLoginHelper();
        try {
            $access_token = $helper->getAccessToken();
        } catch (\Facebook\Exceptions\FacebookResponseException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Facebook\Exceptions\FacebookSDKException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        if (!isset($access_token)) {
            $response = ['status' => 'error'];
            if ($helper->getError()) {
                $response['error'] = $helper->getError() . ' ' . $helper->getErrorDescription();
            }
        }

        try {
            $fb_response = $facebook->get('/me?fields=name,email', $access_token);
        } catch (\Facebook\Exceptions\FacebookResponseException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Facebook\Exceptions\FacebookSDKException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $fb_user_profile = $fb_response->getGraphUser();
        self::$log->info('User is logged in via facebook !!');
        if (!isset($fb_user_profile['email'])) {
            self::$log->error('Facebook email empty');
            return [
                'status' => 'error',
                'error' => \OmegaUp\Translations::getInstance()->get(
                    'loginFacebookEmptyEmailError'
                ),
            ];
        }

        return $this->ThirdPartyLogin(
            'Facebook',
            $fb_user_profile['email'],
            $fb_user_profile['name']
        );
    }

    /**
     * Does login for a user given username or email and password.
     * Expects in request:
     * usernameOrEmail
     * password
     */
    public function nativeLogin(\OmegaUp\Request $r) : string {
        \OmegaUp\Validators::validateStringNonEmpty($r['password'], 'password');

        try {
            $identity = \OmegaUp\Controllers\Identity::resolveIdentity($r['usernameOrEmail']);
        } catch (\OmegaUp\Exceptions\ApiException $e) {
            self::$log->warn("Identity {$r['usernameOrEmail']} not found.");
            throw new \OmegaUp\Exceptions\InvalidCredentialsException();
        }

        if (!\OmegaUp\Controllers\Identity::testPassword($identity, $r['password'])) {
            self::$log->warn("Identity {$identity->username} has introduced invalid credentials.");
            throw new \OmegaUp\Exceptions\InvalidCredentialsException();
        }
        if (\OmegaUp\SecurityTools::isOldHash($identity->password)) {
            // Update the password using the new Argon2i algorithm.
            self::$log->warn("Identity {$identity->username}'s password hash is being upgraded.");
            try {
                \OmegaUp\DAO\DAO::transBegin();
                $identity->password = \OmegaUp\SecurityTools::hashString($r['password']);
                \OmegaUp\DAO\Identities::update($identity);
                if (!is_null($identity->user_id)) {
                    $user = \OmegaUp\DAO\Users::getByPK($identity->user_id);
                    $user->password = $identity->password;
                    \OmegaUp\DAO\Users::update($user);
                }
                \OmegaUp\DAO\DAO::transEnd();
            } catch (\Exception $e) {
                \OmegaUp\DAO\DAO::transRollback();
                throw $e;
            }
        }

        self::$log->info("Identity {$identity->username} has logged in natively.");

        if (!is_null($identity->user_id)) {
            $user = \OmegaUp\DAO\Users::getByPK($identity->user_id);
            \OmegaUp\Controllers\User::checkEmailVerification($user);
        }

        try {
            return $this->registerSession($identity);
        } catch (\Exception $e) {
            self::$log->error($e);
            throw new \OmegaUp\Exceptions\InvalidCredentialsException();
        }
    }

    public static function getLinkedInInstance() : \OmegaUp\LinkedIn {
        return new \OmegaUp\LinkedIn(
            OMEGAUP_LINKEDIN_CLIENTID,
            OMEGAUP_LINKEDIN_SECRET,
            OMEGAUP_URL.'/login?linkedin',
            isset($_GET['redirect']) ? $_GET['redirect'] : null
        );
    }
    public static function getLinkedInLoginUrl() {
        return self::getLinkedInInstance()->getLoginUrl();
    }

    public function LoginViaLinkedIn() {
        if (empty($_GET['code']) || empty($_GET['state'])) {
            return ['status' => 'error'];
        }

        try {
            $li = self::getLinkedInInstance();
            $authToken = $li->getAuthToken($_GET['code'], $_GET['state']);
            $profile = $li->getProfileInfo($authToken);
            $redirect = $li->extractRedirect($_GET['state']);
            if (!is_null($redirect)) {
                $_GET['redirect'] = $redirect;
            }

            return $this->ThirdPartyLogin(
                'LinkedIn',
                $profile['emailAddress'],
                $profile['firstName'] . ' ' . $profile['lastName']
            );
        } catch (\OmegaUp\Exceptions\ApiException $e) {
            self::$log->error("Unable to login via LinkedIn: $e");
            return $e->asResponseArray();
        }
    }

    private function ThirdPartyLogin($provider, $email, $name = null) {
        // We trust this user's identity
        self::$log->info("User is logged in via $provider");
        $results = \OmegaUp\DAO\Identities::FindByEmail($email);

        if (!is_null($results)) {
            self::$log->info("User has been here before with $provider");
            $identity = $results;
        } else {
            // The user has never been here before, let's register them
            self::$log->info("LoginVia$provider: Creating new user for $email");

            // I have a problem with this:
            $username = self::getUniqueUsernameFromEmail($email);
            // Even if the user gave us their email, we should not
            // just go ahead and assume its ok to share with the world
            // maybe we could do:
            // $username = str_replace(" ", "_", $fb_user_profile["name"] ),
            \OmegaUp\Controllers\User::$permissionKey = uniqid();

            $r = new \OmegaUp\Request([
                'name' => (!is_null($name) ? $name : $username),
                'username' => $username,
                'email' => $email,
                'password' => null,
                'permission_key' => \OmegaUp\Controllers\User::$permissionKey,
                'ignore_password' => true
            ]);

            try {
                $res = \OmegaUp\Controllers\User::apiCreate($r);
            } catch (\OmegaUp\Exceptions\ApiException $e) {
                self::$log->error("Unable to login via $provider: $e");
                return $e->asResponseArray();
            }
            $identity = \OmegaUp\DAO\Identities::findByUsername($res['username']);
        }

        $this->registerSession($identity);
        return ['status' => 'ok'];
    }

    public static function setSessionManagerForTesting(
        \OmegaUp\SessionManager $sessionManager
    ) : void {
        self::$_sessionManager = $sessionManager;
    }

    public static function setCookieOnRegisterSessionForTesting(bool $newValue) : bool {
        $oldValue = self::$_setCookieOnRegisterSession;
        self::$_setCookieOnRegisterSession = $newValue;
        return $oldValue;
    }
}
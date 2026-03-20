<?php

declare (strict_types=1);
namespace Guzzle_Http\Cookie;

/**
 * Persists cookies in the client session
 */
class Session_Cookie_Jar extends Cookie_Jar
{
    /**
     * @var string session key
     */
    private $session_key;
    /**
     * @var bool Control whether to persist session cookies or not.
     */
    private $store_session_cookies;
    /**
     * Create a new SessionCookieJar object
     *
     * @param string $sessionKey          Session key name to store the cookie
     *                                    data in session
     * @param bool   $storeSessionCookies Set to true to store session cookies
     *                                    in the cookie jar.
     */
    public function __construct(string $session_key, bool $store_session_cookies = false)
    {
        parent::__construct();
        $this->session_key = $session_key;
        $this->store_session_cookies = $store_session_cookies;
        $this->load();
    }
    /**
     * Saves cookies to session when shutting down
     */
    public function __destruct()
    {
        $this->save();
    }
    /**
     * Save cookies to the client session
     */
    public function save(): void
    {
        $json = [];
        /** @var SetCookie $cookie */
        foreach ($this as $cookie) {
            if (Cookie_Jar::should_persist($cookie, $this->store_session_cookies)) {
                $json[] = $cookie->to_array();
            }
        }
        $_SESSION[$this->session_key] = \json_encode($json);
    }
    /**
     * Load the contents of the client session into the data array
     */
    protected function load(): void
    {
        if (!isset($_SESSION[$this->session_key])) {
            return;
        }
        $data = \json_decode($_SESSION[$this->session_key], true);
        if (\is_array($data)) {
            foreach ($data as $cookie) {
                $this->set_cookie(new Set_Cookie($cookie));
            }
        } elseif (\strlen($data)) {
            throw new \RuntimeException('Invalid cookie data');
        }
    }
}
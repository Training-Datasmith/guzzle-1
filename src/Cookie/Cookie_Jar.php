<?php

declare (strict_types=1);
namespace Guzzle_Http\Cookie;

use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Cookie jar that stores cookies as an array
 */
class Cookie_Jar implements Cookie_Jar_Interface
{
    /**
     * @var SetCookie[] Loaded cookie data
     */
    private $cookies = [];
    /**
     * @var bool
     */
    private $strict_mode;
    /**
     * @param bool  $strictMode  Set to true to throw exceptions when invalid
     *                           cookies are added to the cookie jar.
     * @param array $cookieArray Array of SetCookie objects or a hash of
     *                           arrays that can be used with the SetCookie
     *                           constructor
     */
    public function __construct(bool $strict_mode = false, array $cookie_array = [])
    {
        $this->strict_mode = $strict_mode;
        foreach ($cookie_array as $cookie) {
            if (!$cookie instanceof Set_Cookie) {
                $cookie = new Set_Cookie($cookie);
            }
            $this->set_cookie($cookie);
        }
    }
    /**
     * Create a new Cookie jar from an associative array and domain.
     *
     * @param array  $cookies Cookies to create the jar from
     * @param string $domain  Domain to set the cookies to
     */
    public static function from_array(array $cookies, string $domain): self
    {
        $cookie_jar = new self();
        foreach ($cookies as $name => $value) {
            $cookie_jar->set_cookie(new Set_Cookie(['Domain' => $domain, 'Name' => $name, 'Value' => $value, 'Discard' => true]));
        }
        return $cookie_jar;
    }
    /**
     * Evaluate if this cookie should be persisted to storage
     * that survives between requests.
     *
     * @param SetCookie $cookie              Being evaluated.
     * @param bool      $allowSessionCookies If we should persist session cookies
     */
    public static function should_persist(Set_Cookie $cookie, bool $allow_session_cookies = false): bool
    {
        if (!($cookie->get_expires() || $allow_session_cookies)) {
            return false;
        }
        if (!$cookie->get_discard()) {
            return true;
        }
        return false;
    }
    /**
     * Finds and returns the cookie based on the name
     *
     * @param string $name cookie name to search for
     *
     * @return SetCookie|null cookie that was found or null if not found
     */
    public function get_cookie_by_name(string $name): ?Set_Cookie
    {
        foreach ($this->cookies as $cookie) {
            if ($cookie->get_name() !== null && \strcasecmp($cookie->get_name(), $name) === 0) {
                return $cookie;
            }
        }
        return null;
    }
    public function to_array(): array
    {
        return \array_map(static function (Set_Cookie $cookie): array {
            return $cookie->to_array();
        }, $this->getIterator()->get_array_copy());
    }
    public function clear(?string $domain = null, ?string $path = null, ?string $name = null): void
    {
        if (!$domain) {
            $this->cookies = [];
            return;
        }
        if (!$path) {
            $this->cookies = \array_filter($this->cookies, static function (Set_Cookie $cookie) use ($domain): bool {
                return !$cookie->matches_domain($domain);
            });
        } elseif (!$name) {
            $this->cookies = \array_filter($this->cookies, static function (Set_Cookie $cookie) use ($path, $domain): bool {
                return !($cookie->matches_path($path) && $cookie->matches_domain($domain));
            });
        } else {
            $this->cookies = \array_filter($this->cookies, static function (Set_Cookie $cookie) use ($path, $domain, $name): bool {
                return !($cookie->get_name() == $name && $cookie->matches_path($path) && $cookie->matches_domain($domain));
            });
        }
    }
    public function clear_session_cookies(): void
    {
        $this->cookies = \array_filter($this->cookies, static function (Set_Cookie $cookie): bool {
            return !$cookie->get_discard() && $cookie->get_expires();
        });
    }
    public function set_cookie(Set_Cookie $cookie): bool
    {
        // If the name string is empty (but not 0), ignore the set-cookie
        // string entirely.
        $name = $cookie->get_name();
        if (!$name && $name !== '0') {
            return false;
        }
        // Only allow cookies with set and valid domain, name, value
        $result = $cookie->validate();
        if ($result !== true) {
            if ($this->strict_mode) {
                throw new \RuntimeException('Invalid cookie: ' . $result);
            }
            $this->remove_cookie_if_empty($cookie);
            return false;
        }
        // Resolve conflicts with previously set cookies
        foreach ($this->cookies as $i => $c) {
            // Two cookies are identical, when their path, and domain are
            // identical.
            if ($c->get_path() != $cookie->get_path()) {
                continue;
            }
            if ($c->get_domain() != $cookie->get_domain()) {
                continue;
            }
            if ($c->get_name() != $cookie->get_name()) {
                continue;
            }
            // The previously set cookie is a discard cookie and this one is
            // not so allow the new cookie to be set
            if (!$cookie->get_discard() && $c->get_discard()) {
                unset($this->cookies[$i]);
                continue;
            }
            // If the new cookie's expiration is further into the future, then
            // replace the old cookie
            if ($cookie->get_expires() > $c->get_expires()) {
                unset($this->cookies[$i]);
                continue;
            }
            // If the value has changed, we better change it
            if ($cookie->get_value() !== $c->get_value()) {
                unset($this->cookies[$i]);
                continue;
            }
            // The cookie exists, so no need to continue
            return false;
        }
        $this->cookies[] = $cookie;
        return true;
    }
    public function count(): int
    {
        return \count($this->cookies);
    }
    /**
     * @return \ArrayIterator<int, SetCookie>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(\array_values($this->cookies));
    }
    public function extract_cookies(Request_Interface $request, Response_Interface $response): void
    {
        if ($cookie_header = $response->get_header('Set-Cookie')) {
            foreach ($cookie_header as $cookie) {
                $sc = Set_Cookie::from_string($cookie);
                if (!$sc->get_domain()) {
                    $sc->set_domain($request->get_uri()->get_host());
                }
                if (0 !== \strpos($sc->get_path(), '/')) {
                    $sc->set_path($this->get_cookie_path_from_request($request));
                }
                if (!$sc->matches_domain($request->get_uri()->get_host())) {
                    continue;
                }
                // Note: At this point `$sc->getDomain()` being a public suffix should
                // be rejected, but we don't want to pull in the full PSL dependency.
                $this->set_cookie($sc);
            }
        }
    }
    /**
     * Computes cookie path following RFC 6265 section 5.1.4
     *
     * @see https://datatracker.ietf.org/doc/html/rfc6265#section-5.1.4
     */
    private function get_cookie_path_from_request(Request_Interface $request): string
    {
        $uri_path = $request->get_uri()->get_path();
        if ('' === $uri_path) {
            return '/';
        }
        if (0 !== \strpos($uri_path, '/')) {
            return '/';
        }
        if ('/' === $uri_path) {
            return '/';
        }
        $last_slash_pos = \strrpos($uri_path, '/');
        if (0 === $last_slash_pos || false === $last_slash_pos) {
            return '/';
        }
        return \substr($uri_path, 0, $last_slash_pos);
    }
    public function with_cookie_header(Request_Interface $request): Request_Interface
    {
        $values = [];
        $uri = $request->get_uri();
        $scheme = $uri->get_scheme();
        $host = $uri->get_host();
        $path = $uri->get_path() ?: '/';
        foreach ($this->cookies as $cookie) {
            if ($cookie->matches_path($path) && $cookie->matches_domain($host) && !$cookie->is_expired() && (!$cookie->get_secure() || $scheme === 'https')) {
                $values[] = $cookie->get_name() . '=' . $cookie->get_value();
            }
        }
        return $values ? $request->with_header('Cookie', \implode('; ', $values)) : $request;
    }
    /**
     * If a cookie already exists and the server asks to set it again with a
     * null value, the cookie must be deleted.
     */
    private function remove_cookie_if_empty(Set_Cookie $cookie): void
    {
        $cookie_value = $cookie->get_value();
        if ($cookie_value === null || $cookie_value === '') {
            $this->clear($cookie->get_domain(), $cookie->get_path(), $cookie->get_name());
        }
    }
}
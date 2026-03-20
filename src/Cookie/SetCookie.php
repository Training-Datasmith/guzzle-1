<?php

declare (strict_types=1);
namespace Guzzle_Http\Cookie;

/**
 * Set-Cookie object
 */
class Set_Cookie
{
    /**
     * @var array
     */
    private static $defaults = ['Name' => null, 'Value' => null, 'Domain' => null, 'Path' => '/', 'Max-Age' => null, 'Expires' => null, 'Secure' => false, 'Discard' => false, 'HttpOnly' => false];
    /**
     * @var array Cookie data
     */
    private $data;
    /**
     * Create a new SetCookie object from a string.
     *
     * @param string $cookie Set-Cookie header string
     */
    public static function from_string(string $cookie): self
    {
        // Create the default return array
        $data = self::$defaults;
        // Explode the cookie string using a series of semicolons
        $pieces = \array_filter(\array_map('trim', \explode(';', $cookie)));
        // The name of the cookie (first kvp) must exist and include an equal sign.
        if (!isset($pieces[0]) || \strpos($pieces[0], '=') === false) {
            return new self($data);
        }
        // Add the cookie pieces into the parsed data array
        foreach ($pieces as $part) {
            $cookie_parts = \explode('=', $part, 2);
            $key = \trim($cookie_parts[0]);
            $value = isset($cookie_parts[1]) ? \trim($cookie_parts[1], " \n\r\t\x00\v") : true;
            // Only check for non-cookies when cookies have been found
            if (!isset($data['Name'])) {
                $data['Name'] = $key;
                $data['Value'] = $value;
            } else {
                foreach (\array_keys(self::$defaults) as $search) {
                    if (!\strcasecmp($search, $key)) {
                        if ($search === 'Max-Age') {
                            if (is_numeric($value)) {
                                $data[$search] = (int) $value;
                            }
                        } elseif ($search === 'Secure' || $search === 'Discard' || $search === 'HttpOnly') {
                            if ($value) {
                                $data[$search] = true;
                            }
                        } else {
                            $data[$search] = $value;
                        }
                        continue 2;
                    }
                }
                $data[$key] = $value;
            }
        }
        return new self($data);
    }
    /**
     * @param array $data Array of cookie data provided by a Cookie parser
     */
    public function __construct(array $data = [])
    {
        $this->data = self::$defaults;
        if (isset($data['Name'])) {
            $this->set_name($data['Name']);
        }
        if (isset($data['Value'])) {
            $this->set_value($data['Value']);
        }
        if (isset($data['Domain'])) {
            $this->set_domain($data['Domain']);
        }
        if (isset($data['Path'])) {
            $this->set_path($data['Path']);
        }
        if (isset($data['Max-Age'])) {
            $this->set_max_age($data['Max-Age']);
        }
        if (isset($data['Expires'])) {
            $this->set_expires($data['Expires']);
        }
        if (isset($data['Secure'])) {
            $this->set_secure($data['Secure']);
        }
        if (isset($data['Discard'])) {
            $this->set_discard($data['Discard']);
        }
        if (isset($data['HttpOnly'])) {
            $this->set_http_only($data['HttpOnly']);
        }
        // Set the remaining values that don't have extra validation logic
        foreach (array_diff(array_keys($data), array_keys(self::$defaults)) as $key) {
            $this->data[$key] = $data[$key];
        }
        // Extract the Expires value and turn it into a UNIX timestamp if needed
        if (!$this->get_expires() && $this->get_max_age()) {
            // Calculate the Expires date
            $this->set_expires(\time() + $this->get_max_age());
        } elseif (null !== ($expires = $this->get_expires()) && !\is_numeric($expires)) {
            $this->set_expires($expires);
        }
    }
    public function __toString(): string
    {
        $str = $this->data['Name'] . '=' . ($this->data['Value'] ?? '') . '; ';
        foreach ($this->data as $k => $v) {
            if ($k !== 'Name' && $k !== 'Value' && $v !== null && $v !== false) {
                if ($k === 'Expires') {
                    $str .= 'Expires=' . \gmdate('D, d M Y H:i:s \G\M\T', $v) . '; ';
                } else {
                    $str .= ($v === true ? $k : "{$k}={$v}") . '; ';
                }
            }
        }
        return \rtrim($str, '; ');
    }
    public function to_array(): array
    {
        return $this->data;
    }
    /**
     * Get the cookie name.
     *
     * @return string
     */
    public function get_name()
    {
        return $this->data['Name'];
    }
    /**
     * Set the cookie name.
     *
     * @param string $name Cookie name
     */
    public function set_name($name): void
    {
        if (!is_string($name)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a string to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Name'] = $name;
    }
    /**
     * Get the cookie value.
     *
     * @return string|null
     */
    public function get_value()
    {
        return $this->data['Value'];
    }
    /**
     * Set the cookie value.
     *
     * @param string $value Cookie value
     */
    public function set_value($value): void
    {
        if (!is_string($value)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a string to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Value'] = $value;
    }
    /**
     * Get the domain.
     *
     * @return string|null
     */
    public function get_domain()
    {
        return $this->data['Domain'];
    }
    /**
     * Set the domain of the cookie.
     *
     * @param string|null $domain
     */
    public function set_domain($domain): void
    {
        if (!is_string($domain) && null !== $domain) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a string or null to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Domain'] = $domain ?? null;
    }
    /**
     * Get the path.
     *
     * @return string
     */
    public function get_path()
    {
        return $this->data['Path'];
    }
    /**
     * Set the path of the cookie.
     *
     * @param string $path Path of the cookie
     */
    public function set_path($path): void
    {
        if (!is_string($path)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a string to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Path'] = $path;
    }
    /**
     * Maximum lifetime of the cookie in seconds.
     */
    public function get_max_age(): ?int
    {
        return null === $this->data['Max-Age'] ? null : (int) $this->data['Max-Age'];
    }
    /**
     * Set the max-age of the cookie.
     *
     * @param int|null $maxAge Max age of the cookie in seconds
     */
    public function set_max_age($max_age): void
    {
        if (!is_int($max_age) && null !== $max_age) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing an int or null to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Max-Age'] = $max_age ?? null;
    }
    /**
     * The UNIX timestamp when the cookie Expires.
     *
     * @return string|int|null
     */
    public function get_expires()
    {
        return $this->data['Expires'];
    }
    /**
     * Set the unix timestamp for which the cookie will expire.
     *
     * @param int|string|null $timestamp Unix timestamp or any English textual datetime description.
     */
    public function set_expires($timestamp): void
    {
        if (!is_int($timestamp) && !is_string($timestamp) && null !== $timestamp) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing an int, string or null to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Expires'] = null === $timestamp ? null : (\is_numeric($timestamp) ? (int) $timestamp : \strtotime($timestamp));
    }
    /**
     * Get whether or not this is a secure cookie.
     *
     * @return bool
     */
    public function get_secure()
    {
        return $this->data['Secure'];
    }
    /**
     * Set whether or not the cookie is secure.
     *
     * @param bool $secure Set to true or false if secure
     */
    public function set_secure($secure): void
    {
        if (!is_bool($secure)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a bool to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Secure'] = $secure;
    }
    /**
     * Get whether or not this is a session cookie.
     *
     * @return bool|null
     */
    public function get_discard()
    {
        return $this->data['Discard'];
    }
    /**
     * Set whether or not this is a session cookie.
     *
     * @param bool $discard Set to true or false if this is a session cookie
     */
    public function set_discard($discard): void
    {
        if (!is_bool($discard)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a bool to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['Discard'] = $discard;
    }
    /**
     * Get whether or not this is an HTTP only cookie.
     *
     * @return bool
     */
    public function get_http_only()
    {
        return $this->data['HttpOnly'];
    }
    /**
     * Set whether or not this is an HTTP only cookie.
     *
     * @param bool $httpOnly Set to true or false if this is HTTP only
     */
    public function set_http_only($http_only): void
    {
        if (!is_bool($http_only)) {
            trigger_deprecation('guzzlehttp/guzzle', '7.4', 'Not passing a bool to %s::%s() is deprecated and will cause an error in 8.0.', self::class, __FUNCTION__);
        }
        $this->data['HttpOnly'] = $http_only;
    }
    /**
     * Check if the cookie matches a path value.
     *
     * A request-path path-matches a given cookie-path if at least one of
     * the following conditions holds:
     *
     * - The cookie-path and the request-path are identical.
     * - The cookie-path is a prefix of the request-path, and the last
     *   character of the cookie-path is %x2F ("/").
     * - The cookie-path is a prefix of the request-path, and the first
     *   character of the request-path that is not included in the cookie-
     *   path is a %x2F ("/") character.
     *
     * @param string $requestPath Path to check against
     */
    public function matches_path(string $request_path): bool
    {
        $cookie_path = $this->get_path();
        // Match on exact matches or when path is the default empty "/"
        if ($cookie_path === '/' || $cookie_path == $request_path) {
            return true;
        }
        // Ensure that the cookie-path is a prefix of the request path.
        if (0 !== \strpos($request_path, $cookie_path)) {
            return false;
        }
        // Match if the last character of the cookie-path is "/"
        if (\substr($cookie_path, -1, 1) === '/') {
            return true;
        }
        // Match if the first character not included in cookie path is "/"
        return \substr($request_path, \strlen($cookie_path), 1) === '/';
    }
    /**
     * Check if the cookie matches a domain value.
     *
     * @param string $domain Domain to check against
     */
    public function matches_domain(string $domain): bool
    {
        $cookie_domain = $this->get_domain();
        if (null === $cookie_domain) {
            return true;
        }
        // Remove the leading '.' as per spec in RFC 6265.
        // https://datatracker.ietf.org/doc/html/rfc6265#section-5.2.3
        $cookie_domain = \ltrim(\strtolower($cookie_domain), '.');
        $domain = \strtolower($domain);
        // Reject cookies whose domain reduces to empty string after dot-stripping
        // (e.g. Domain=.), as they would match all hosts.
        if ('' === $cookie_domain) {
            return false;
        }
        // Exact match.
        if ($domain === $cookie_domain) {
            return true;
        }
        // Matching the subdomain according to RFC 6265.
        // https://datatracker.ietf.org/doc/html/rfc6265#section-5.1.3
        if (\filter_var($domain, \FILTER_VALIDATE_IP)) {
            return false;
        }
        return (bool) \preg_match('/\.' . \preg_quote($cookie_domain, '/') . '$/', $domain);
    }
    /**
     * Check if the cookie is expired.
     */
    public function is_expired(): bool
    {
        return $this->get_expires() !== null && \time() > $this->get_expires();
    }
    /**
     * Check if the cookie is valid according to RFC 6265.
     *
     * @return bool|string Returns true if valid or an error message if invalid
     */
    public function validate()
    {
        $name = $this->get_name();
        if ($name === '') {
            return 'The cookie name must not be empty';
        }
        // Check if any of the invalid characters are present in the cookie name
        if (\preg_match('/[\x00-\x20\x22\x28-\x29\x2c\x2f\x3a-\x40\x5c\x7b\x7d\x7f]/', $name)) {
            return 'Cookie name must not contain invalid characters: ASCII ' . 'Control characters (0-31;127), space, tab and the ' . 'following characters: ()<>@,;:\"/?={}';
        }
        // Value must not be null. 0 and empty string are valid. Empty strings
        // are technically against RFC 6265, but known to happen in the wild.
        $value = $this->get_value();
        if ($value === null) {
            return 'The cookie value must not be empty';
        }
        // Domains must not be empty, but can be 0. "0" is not a valid internet
        // domain, but may be used as server name in a private network.
        $domain = $this->get_domain();
        if ($domain === null || $domain === '') {
            return 'The cookie domain must not be empty';
        }
        return true;
    }
}
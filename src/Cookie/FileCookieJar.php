<?php

declare (strict_types=1);
namespace Guzzle_Http\Cookie;

use Guzzle_Http\Utils;
/**
 * Persists non-session cookies using a JSON formatted file
 */
class File_Cookie_Jar extends Cookie_Jar
{
    /**
     * @var string filename
     */
    private $filename;
    /**
     * @var bool Control whether to persist session cookies or not.
     */
    private $store_session_cookies;
    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile          File to store the cookie data
     * @param bool   $storeSessionCookies Set to true to store session cookies
     *                                    in the cookie jar.
     *
     * @throws \RuntimeException if the file cannot be found or created
     */
    public function __construct(string $cookie_file, bool $store_session_cookies = false)
    {
        parent::__construct();
        $this->filename = $cookie_file;
        $this->store_session_cookies = $store_session_cookies;
        if (\file_exists($cookie_file)) {
            $this->load($cookie_file);
        }
    }
    /**
     * Saves the file when shutting down
     */
    public function __destruct()
    {
        $this->save($this->filename);
    }
    /**
     * Saves the cookies to a file.
     *
     * @param string $filename File to save
     *
     * @throws \RuntimeException if the file cannot be found or created
     */
    public function save(string $filename): void
    {
        $json = [];
        /** @var SetCookie $cookie */
        foreach ($this as $cookie) {
            if (Cookie_Jar::should_persist($cookie, $this->store_session_cookies)) {
                $json[] = $cookie->to_array();
            }
        }
        $json_str = Utils::json_encode($json);
        if (false === \file_put_contents($filename, $json_str, \LOCK_EX)) {
            throw new \RuntimeException("Unable to save file {$filename}");
        }
    }
    /**
     * Load cookies from a JSON formatted file.
     *
     * Old cookies are kept unless overwritten by newly loaded ones.
     *
     * @param string $filename Cookie file to load.
     *
     * @throws \RuntimeException if the file cannot be loaded.
     */
    public function load(string $filename): void
    {
        $json = \file_get_contents($filename);
        if (false === $json) {
            throw new \RuntimeException("Unable to load file {$filename}");
        }
        if ($json === '') {
            return;
        }
        $data = Utils::json_decode($json, true);
        if (\is_array($data)) {
            foreach ($data as $cookie) {
                $this->set_cookie(new Set_Cookie($cookie));
            }
        } elseif (\is_scalar($data) && !empty($data)) {
            throw new \RuntimeException("Invalid cookie file: {$filename}");
        }
    }
}
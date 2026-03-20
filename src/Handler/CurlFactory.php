<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Guzzle_Http\Exception\Connect_Exception;
use Guzzle_Http\Exception\Request_Exception;
use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Fulfilled_Promise;
use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Psr7\Lazy_Open_Stream;
use Guzzle_Http\Transfer_Stats;
use Guzzle_Http\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Creates curl resources from a request
 *
 * @final
 */
class Curl_Factory implements Curl_Factory_Interface
{
    public const CURL_VERSION_STR = 'curl_version';
    /**
     * @deprecated
     */
    public const LOW_CURL_VERSION_NUMBER = '7.21.2';
    /**
     * @var resource[]|\CurlHandle[]
     */
    private $handles = [];
    /**
     * @var int Total number of idle handles to keep in cache
     */
    private $max_handles;
    /**
     * @param int $maxHandles Maximum number of idle handles.
     */
    public function __construct(int $max_handles)
    {
        $this->max_handles = $max_handles;
    }
    public function create(Request_Interface $request, array $options): Easy_Handle
    {
        $protocol_version = $request->get_protocol_version();
        if ('2' === $protocol_version || '2.0' === $protocol_version) {
            if (!self::supports_http2()) {
                throw new Connect_Exception('HTTP/2 is supported by the cURL handler, however libcurl is built without HTTP/2 support.', $request);
            }
        } elseif ('1.0' !== $protocol_version && '1.1' !== $protocol_version) {
            throw new Connect_Exception(sprintf('HTTP/%s is not supported by the cURL handler.', $protocol_version), $request);
        }
        if (isset($options['curl']['body_as_string'])) {
            $options['_body_as_string'] = $options['curl']['body_as_string'];
            unset($options['curl']['body_as_string']);
        }
        $easy = new Easy_Handle();
        $easy->request = $request;
        $easy->options = $options;
        $conf = $this->get_default_conf($easy);
        $this->apply_method($easy, $conf);
        $this->apply_handler_options($easy, $conf);
        $this->apply_headers($easy, $conf);
        unset($conf['_headers']);
        // Add handler options from the request configuration options
        if (isset($options['curl'])) {
            $conf = \array_replace($conf, $options['curl']);
        }
        $conf[\CURLOPT_HEADERFUNCTION] = $this->create_header_fn($easy);
        $easy->handle = $this->handles ? \array_pop($this->handles) : \curl_init();
        curl_setopt_array($easy->handle, $conf);
        return $easy;
    }
    private static function supports_http2(): bool
    {
        static $supports_http2 = null;
        if (null === $supports_http2) {
            $supports_http2 = self::supports_tls12() && defined('CURL_VERSION_HTTP2') && \CURL_VERSION_HTTP2 & \curl_version()['features'];
        }
        return $supports_http2;
    }
    private static function supports_tls12(): bool
    {
        static $supports_tls12 = null;
        if (null === $supports_tls12) {
            $supports_tls12 = \Curl_sslversion_tl_Sv1_2 & \curl_version()['features'];
        }
        return $supports_tls12;
    }
    private static function supports_tls13(): bool
    {
        static $supports_tls13 = null;
        if (null === $supports_tls13) {
            $supports_tls13 = defined('CURL_SSLVERSION_TLSv1_3') && \Curl_sslversion_tl_Sv1_3 & \curl_version()['features'];
        }
        return $supports_tls13;
    }
    public function release(Easy_Handle $easy): void
    {
        $resource = $easy->handle;
        unset($easy->handle);
        if (\count($this->handles) >= $this->max_handles) {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($resource);
            }
        } else {
            // Remove all callback functions as they can hold onto references
            // and are not cleaned up by curl_reset. Using curl_setopt_array
            // does not work for some reason, so removing each one
            // individually.
            \curl_setopt($resource, \CURLOPT_HEADERFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_READFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_WRITEFUNCTION, null);
            \curl_setopt($resource, \CURLOPT_PROGRESSFUNCTION, null);
            \curl_reset($resource);
            $this->handles[] = $resource;
        }
    }
    /**
     * Completes a cURL transaction, either returning a response promise or a
     * rejected promise.
     *
     * @param callable(RequestInterface, array): PromiseInterface $handler
     * @param CurlFactoryInterface                                $factory Dictates how the handle is released
     */
    public static function finish(callable $handler, Easy_Handle $easy, Curl_Factory_Interface $factory): Promise_Interface
    {
        if (isset($easy->options['on_stats'])) {
            self::invoke_stats($easy);
        }
        if (!$easy->response || $easy->errno) {
            return self::finish_error($handler, $easy, $factory);
        }
        // Return the response if it is present and there is no error.
        $factory->release($easy);
        // Rewind the body of the response if possible.
        $body = $easy->response->get_body();
        if ($body->is_seekable()) {
            $body->rewind();
        }
        return new Fulfilled_Promise($easy->response);
    }
    private static function invoke_stats(Easy_Handle $easy): void
    {
        $curl_stats = \curl_getinfo($easy->handle);
        $curl_stats['appconnect_time'] = \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME);
        $stats = new Transfer_Stats($easy->request, $easy->response, $curl_stats['total_time'], $easy->errno, $curl_stats);
        $easy->options['on_stats']($stats);
    }
    /**
     * @param callable(RequestInterface, array): PromiseInterface $handler
     */
    private static function finish_error(callable $handler, Easy_Handle $easy, Curl_Factory_Interface $factory): Promise_Interface
    {
        // Get error information and release the handle to the factory.
        $ctx = ['errno' => $easy->errno, 'error' => \curl_error($easy->handle), 'appconnect_time' => \curl_getinfo($easy->handle, \CURLINFO_APPCONNECT_TIME)] + \curl_getinfo($easy->handle);
        $ctx[self::CURL_VERSION_STR] = self::get_curl_version();
        // Redact credentials from effective_url to prevent leaking them via
        // exception context (curl_getinfo includes the resolved URL verbatim).
        if (isset($ctx['effective_url']) && is_string($ctx['effective_url'])) {
            $parsed = \parse_url($ctx['effective_url']);
            if ($parsed !== false && (isset($parsed['user']) || isset($parsed['pass']))) {
                $ctx['effective_url'] = \Guzzle_Http\Psr7\Utils::redact_user_info(new \Guzzle_Http\Psr7\Uri($ctx['effective_url']))->__toString();
            }
        }
        $factory->release($easy);
        // Retry when nothing is present or when curl failed to rewind.
        if (empty($easy->options['_err_message']) && (!$easy->errno || $easy->errno == 65)) {
            return self::retry_failed_rewind($handler, $easy, $ctx);
        }
        return self::create_rejection($easy, $ctx);
    }
    private static function get_curl_version(): string
    {
        static $curl_version = null;
        if (null === $curl_version) {
            $curl_version = \curl_version()['version'];
        }
        return $curl_version;
    }
    private static function create_rejection(Easy_Handle $easy, array $ctx): Promise_Interface
    {
        static $connection_errors = [\CURLE_OPERATION_TIMEOUTED => true, \CURLE_COULDNT_RESOLVE_HOST => true, \CURLE_COULDNT_CONNECT => true, \CURLE_SSL_CONNECT_ERROR => true, \CURLE_GOT_NOTHING => true];
        if ($easy->create_response_exception) {
            return P\Create::rejection_for(new Request_Exception('An error was encountered while creating the response', $easy->request, $easy->response, $easy->create_response_exception, $ctx));
        }
        // If an exception was encountered during the onHeaders event, then
        // return a rejected promise that wraps that exception.
        if ($easy->on_headers_exception) {
            return P\Create::rejection_for(new Request_Exception('An error was encountered during the on_headers event', $easy->request, $easy->response, $easy->on_headers_exception, $ctx));
        }
        $uri = $easy->request->get_uri();
        $sanitized_error = self::sanitize_curl_error($ctx['error'] ?? '', $uri);
        $message = \sprintf('cURL error %s: %s (%s)', $ctx['errno'], $sanitized_error, 'see https://curl.haxx.se/libcurl/c/libcurl-errors.html');
        if ('' !== $sanitized_error) {
            $redacted_uri_string = \Guzzle_Http\Psr7\Utils::redact_user_info($uri)->__toString();
            if ($redacted_uri_string !== '' && false === \strpos($sanitized_error, $redacted_uri_string)) {
                $message .= \sprintf(' for %s', $redacted_uri_string);
            }
        }
        // Create a connection exception if it was a specific error code.
        $error = isset($connection_errors[$easy->errno]) ? new Connect_Exception($message, $easy->request, null, $ctx) : new Request_Exception($message, $easy->request, $easy->response, null, $ctx);
        return P\Create::rejection_for($error);
    }
    private static function sanitize_curl_error(string $error, Uri_Interface $uri): string
    {
        if ('' === $error) {
            return $error;
        }
        $base_uri = $uri->with_query('')->with_fragment('');
        $base_uri_string = $base_uri->__toString();
        if ('' === $base_uri_string) {
            return $error;
        }
        $redacted_uri_string = \Guzzle_Http\Psr7\Utils::redact_user_info($base_uri)->__toString();
        return str_replace($base_uri_string, $redacted_uri_string, $error);
    }
    /**
     * @return array<int|string, mixed>
     */
    private function get_default_conf(Easy_Handle $easy): array
    {
        $conf = ['_headers' => $easy->request->get_headers(), \CURLOPT_CUSTOMREQUEST => $easy->request->get_method(), \CURLOPT_URL => (string) $easy->request->get_uri()->with_fragment(''), \CURLOPT_RETURNTRANSFER => false, \CURLOPT_HEADER => false, \CURLOPT_CONNECTTIMEOUT => 300];
        if (\defined('CURLOPT_PROTOCOLS')) {
            $conf[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        }
        $version = $easy->request->get_protocol_version();
        if ('2' === $version || '2.0' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_2_0;
        } elseif ('1.1' === $version) {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
        } else {
            $conf[\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_0;
        }
        return $conf;
    }
    private function apply_method(Easy_Handle $easy, array &$conf): void
    {
        $body = $easy->request->get_body();
        $size = $body->get_size();
        if ($size === null || $size > 0) {
            $this->apply_body($easy->request, $easy->options, $conf);
            return;
        }
        $method = $easy->request->get_method();
        if ($method === 'PUT' || $method === 'POST') {
            // See https://datatracker.ietf.org/doc/html/rfc7230#section-3.3.2
            if (!$easy->request->has_header('Content-Length')) {
                $conf[\CURLOPT_HTTPHEADER][] = 'Content-Length: 0';
            }
        } elseif ($method === 'HEAD') {
            $conf[\CURLOPT_NOBODY] = true;
            unset($conf[\CURLOPT_WRITEFUNCTION], $conf[\CURLOPT_READFUNCTION], $conf[\CURLOPT_FILE], $conf[\CURLOPT_INFILE]);
        }
    }
    private function apply_body(Request_Interface $request, array $options, array &$conf): void
    {
        $size = $request->has_header('Content-Length') ? (int) $request->get_header_line('Content-Length') : null;
        // Send the body as a string if the size is less than 1MB OR if the
        // [curl][body_as_string] request value is set.
        if ($size !== null && $size < 1000000 || !empty($options['_body_as_string'])) {
            $conf[\CURLOPT_POSTFIELDS] = (string) $request->get_body();
            // Don't duplicate the Content-Length header
            $this->remove_header('Content-Length', $conf);
            $this->remove_header('Transfer-Encoding', $conf);
        } else {
            $conf[\CURLOPT_UPLOAD] = true;
            if ($size !== null) {
                $conf[\CURLOPT_INFILESIZE] = $size;
                $this->remove_header('Content-Length', $conf);
            }
            $body = $request->get_body();
            if ($body->is_seekable()) {
                $body->rewind();
            }
            $conf[\CURLOPT_READFUNCTION] = static function ($ch, $fd, $length) use ($body) {
                return $body->read($length);
            };
        }
        // If the Expect header is not present, prevent curl from adding it
        if (!$request->has_header('Expect')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Expect:';
        }
        // cURL sometimes adds a content-type by default. Prevent this.
        if (!$request->has_header('Content-Type')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Content-Type:';
        }
    }
    private function apply_headers(Easy_Handle $easy, array &$conf): void
    {
        foreach ($conf['_headers'] as $name => $values) {
            foreach ($values as $value) {
                $value = (string) $value;
                if ($value === '') {
                    // cURL requires a special format for empty headers.
                    // See https://github.com/guzzle/guzzle/issues/1882 for more details.
                    $conf[\CURLOPT_HTTPHEADER][] = "{$name};";
                } else {
                    $conf[\CURLOPT_HTTPHEADER][] = "{$name}: {$value}";
                }
            }
        }
        // Remove the Accept header if one was not set
        if (!$easy->request->has_header('Accept')) {
            $conf[\CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }
    /**
     * Remove a header from the options array.
     *
     * @param string $name    Case-insensitive header to remove
     * @param array  $options Array of options to modify
     */
    private function remove_header(string $name, array &$options): void
    {
        foreach (\array_keys($options['_headers']) as $key) {
            if (!\strcasecmp($key, $name)) {
                unset($options['_headers'][$key]);
                return;
            }
        }
    }
    private function apply_handler_options(Easy_Handle $easy, array &$conf): void
    {
        $options = $easy->options;
        if (isset($options['verify'])) {
            if ($options['verify'] === false) {
                unset($conf[\CURLOPT_CAINFO]);
                $conf[\CURLOPT_SSL_VERIFYHOST] = 0;
                $conf[\CURLOPT_SSL_VERIFYPEER] = false;
            } else {
                $conf[\CURLOPT_SSL_VERIFYHOST] = 2;
                $conf[\CURLOPT_SSL_VERIFYPEER] = true;
                if (\is_string($options['verify'])) {
                    // Throw an error if the file/folder/link path is not valid or doesn't exist.
                    if (!\file_exists($options['verify'])) {
                        throw new \InvalidArgumentException("SSL CA bundle not found: {$options['verify']}");
                    }
                    // If it's a directory or a link to a directory use CURLOPT_CAPATH.
                    // If not, it's probably a file, or a link to a file, so use CURLOPT_CAINFO.
                    if (\is_dir($options['verify']) || \is_link($options['verify']) === true && ($verify_link = \readlink($options['verify'])) !== false && \is_dir($verify_link)) {
                        $conf[\CURLOPT_CAPATH] = $options['verify'];
                    } else {
                        $conf[\CURLOPT_CAINFO] = $options['verify'];
                    }
                }
            }
        }
        if (!isset($options['curl'][\CURLOPT_ENCODING]) && !empty($options['decode_content'])) {
            $accept = $easy->request->get_header_line('Accept-Encoding');
            if ($accept) {
                $conf[\CURLOPT_ENCODING] = $accept;
            } else {
                // The empty string enables all available decoders and implicitly
                // sets a matching 'Accept-Encoding' header.
                $conf[\CURLOPT_ENCODING] = '';
                // But as the user did not specify any encoding preference,
                // let's leave it up to server by preventing curl from sending
                // the header, which will be interpreted as 'Accept-Encoding: *'.
                // https://www.rfc-editor.org/rfc/rfc9110#field.accept-encoding
                $conf[\CURLOPT_HTTPHEADER][] = 'Accept-Encoding:';
            }
        }
        if (!isset($options['sink'])) {
            // Use a default temp stream if no sink was set.
            $options['sink'] = \Guzzle_Http\Psr7\Utils::try_fopen('php://temp', 'w+');
        }
        $sink = $options['sink'];
        if (!\is_string($sink)) {
            $sink = \Guzzle_Http\Psr7\Utils::stream_for($sink);
        } elseif (!\is_dir(\dirname($sink))) {
            // Ensure that the directory exists before failing in curl.
            throw new \RuntimeException(\sprintf('Directory %s does not exist for sink value of %s', \dirname($sink), $sink));
        } else {
            $sink = new Lazy_Open_Stream($sink, 'w+');
        }
        $easy->sink = $sink;
        $conf[\CURLOPT_WRITEFUNCTION] = static function ($ch, $write) use ($sink): int {
            return $sink->write($write);
        };
        $timeout_requires_no_signal = false;
        if (isset($options['timeout'])) {
            $timeout_requires_no_signal |= $options['timeout'] < 1;
            $conf[\CURLOPT_TIMEOUT_MS] = $options['timeout'] * 1000;
        }
        // CURL default value is CURL_IPRESOLVE_WHATEVER
        if (isset($options['force_ip_resolve'])) {
            if ('v4' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
            } elseif ('v6' === $options['force_ip_resolve']) {
                $conf[\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V6;
            }
        }
        if (isset($options['connect_timeout'])) {
            $timeout_requires_no_signal |= $options['connect_timeout'] < 1;
            $conf[\CURLOPT_CONNECTTIMEOUT_MS] = $options['connect_timeout'] * 1000;
        }
        if ($timeout_requires_no_signal && \strtoupper(\substr(\PHP_OS, 0, 3)) !== 'WIN') {
            $conf[\CURLOPT_NOSIGNAL] = true;
        }
        if (isset($options['proxy'])) {
            if (!\is_array($options['proxy'])) {
                $conf[\CURLOPT_PROXY] = $options['proxy'];
            } else {
                $scheme = $easy->request->get_uri()->get_scheme();
                if (isset($options['proxy'][$scheme])) {
                    $host = $easy->request->get_uri()->get_host();
                    if (isset($options['proxy']['no']) && Utils::is_host_in_no_proxy($host, $options['proxy']['no'])) {
                        unset($conf[\CURLOPT_PROXY]);
                    } else {
                        $conf[\CURLOPT_PROXY] = $options['proxy'][$scheme];
                    }
                }
            }
        }
        if (isset($options['crypto_method'])) {
            $protocol_version = $easy->request->get_protocol_version();
            // If HTTP/2, upgrade TLS 1.0 and 1.1 to 1.2
            if ('2' === $protocol_version || '2.0' === $protocol_version) {
                if (\Stream_crypto_method_tl_Sv1_0_client === $options['crypto_method'] || \Stream_crypto_method_tl_Sv1_1_client === $options['crypto_method'] || \Stream_crypto_method_tl_Sv1_2_client === $options['crypto_method']) {
                    $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_2;
                } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && \Stream_crypto_method_tl_Sv1_3_client === $options['crypto_method']) {
                    if (!self::supports_tls13()) {
                        throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                    }
                    $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_3;
                } else {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
                }
            } elseif (\Stream_crypto_method_tl_Sv1_0_client === $options['crypto_method']) {
                $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_0;
            } elseif (\Stream_crypto_method_tl_Sv1_1_client === $options['crypto_method']) {
                $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_1;
            } elseif (\Stream_crypto_method_tl_Sv1_2_client === $options['crypto_method']) {
                if (!self::supports_tls12()) {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.2 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_2;
            } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && \Stream_crypto_method_tl_Sv1_3_client === $options['crypto_method']) {
                if (!self::supports_tls13()) {
                    throw new \InvalidArgumentException('Invalid crypto_method request option: TLS 1.3 not supported by your version of cURL');
                }
                $conf[\CURLOPT_SSLVERSION] = \Curl_sslversion_tl_Sv1_3;
            } else {
                throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
            }
        }
        if (isset($options['cert'])) {
            $cert = $options['cert'];
            if (\is_array($cert)) {
                $conf[\CURLOPT_SSLCERTPASSWD] = $cert[1];
                $cert = $cert[0];
            }
            if (!\file_exists($cert)) {
                throw new \InvalidArgumentException("SSL certificate not found: {$cert}");
            }
            // OpenSSL (versions 0.9.3 and later) also support "P12" for PKCS#12-encoded files.
            // see https://curl.se/libcurl/c/CURLOPT_SSLCERTTYPE.html
            $ext = pathinfo($cert, \PATHINFO_EXTENSION);
            if (preg_match('#^(der|p12)$#i', $ext)) {
                $conf[\CURLOPT_SSLCERTTYPE] = strtoupper($ext);
            }
            $conf[\CURLOPT_SSLCERT] = $cert;
        }
        if (isset($options['ssl_key'])) {
            if (\is_array($options['ssl_key'])) {
                if (\count($options['ssl_key']) === 2) {
                    [$ssl_key, $conf[\CURLOPT_SSLKEYPASSWD]] = $options['ssl_key'];
                } else {
                    [$ssl_key] = $options['ssl_key'];
                }
            }
            $ssl_key = $ssl_key ?? $options['ssl_key'];
            if (!\file_exists($ssl_key)) {
                throw new \InvalidArgumentException("SSL private key not found: {$ssl_key}");
            }
            $conf[\CURLOPT_SSLKEY] = $ssl_key;
        }
        if (isset($options['progress'])) {
            $progress = $options['progress'];
            if (!\is_callable($progress)) {
                throw new \InvalidArgumentException('progress client option must be callable');
            }
            $conf[\CURLOPT_NOPROGRESS] = false;
            $conf[\CURLOPT_PROGRESSFUNCTION] = static function ($resource, int $download_size, int $downloaded, int $upload_size, int $uploaded) use ($progress): void {
                $progress($download_size, $downloaded, $upload_size, $uploaded);
            };
        }
        if (!empty($options['debug'])) {
            $conf[\CURLOPT_STDERR] = Utils::debug_resource($options['debug']);
            $conf[\CURLOPT_VERBOSE] = true;
        }
    }
    /**
     * This function ensures that a response was set on a transaction. If one
     * was not set, then the request is retried if possible. This error
     * typically means you are sending a payload, curl encountered a
     * "Connection died, retrying a fresh connect" error, tried to rewind the
     * stream, and then encountered a "necessary data rewind wasn't possible"
     * error, causing the request to be sent through curl_multi_info_read()
     * without an error status.
     *
     * @param callable(RequestInterface, array): PromiseInterface $handler
     */
    private static function retry_failed_rewind(callable $handler, Easy_Handle $easy, array $ctx): Promise_Interface
    {
        try {
            // Only rewind if the body has been read from.
            $body = $easy->request->get_body();
            if ($body->tell() > 0) {
                $body->rewind();
            }
        } catch (\RuntimeException $e) {
            $ctx['error'] = 'The connection unexpectedly failed without ' . 'providing an error. The request would have been retried, ' . 'but attempting to rewind the request body failed. ' . 'Exception: ' . $e;
            return self::create_rejection($easy, $ctx);
        }
        // Retry no more than 3 times before giving up.
        if (!isset($easy->options['_curl_retries'])) {
            $easy->options['_curl_retries'] = 1;
        } elseif ($easy->options['_curl_retries'] == 2) {
            $ctx['error'] = 'The cURL request was retried 3 times ' . 'and did not succeed. The most likely reason for the failure ' . 'is that cURL was unable to rewind the body of the request ' . 'and subsequent retries resulted in the same error. Turn on ' . 'the debug option to see what went wrong. See ' . 'https://bugs.php.net/bug.php?id=47204 for more information.';
            return self::create_rejection($easy, $ctx);
        } else {
            ++$easy->options['_curl_retries'];
        }
        return $handler($easy->request, $easy->options);
    }
    private function create_header_fn(Easy_Handle $easy): callable
    {
        if (isset($easy->options['on_headers'])) {
            $on_headers = $easy->options['on_headers'];
            if (!\is_callable($on_headers)) {
                throw new \InvalidArgumentException('on_headers must be callable');
            }
        } else {
            $on_headers = null;
        }
        return static function ($ch, $h) use ($on_headers, $easy, &$starting_response): int {
            $value = \trim($h);
            if ($value === '') {
                $starting_response = true;
                try {
                    $easy->create_response();
                } catch (\Exception $e) {
                    $easy->create_response_exception = $e;
                    return -1;
                }
                if ($on_headers !== null) {
                    try {
                        $on_headers($easy->response);
                    } catch (\Exception $e) {
                        // Associate the exception with the handle and trigger
                        // a curl header write error by returning 0.
                        $easy->on_headers_exception = $e;
                        return -1;
                    }
                }
            } elseif ($starting_response) {
                $starting_response = false;
                $easy->headers = [$value];
            } else {
                $easy->headers[] = $value;
            }
            return \strlen($h);
        };
    }
    public function __destruct()
    {
        foreach ($this->handles as $id => $handle) {
            if (PHP_VERSION_ID < 80000) {
                \curl_close($handle);
            }
            unset($this->handles[$id]);
        }
    }
}
<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Guzzle_Http\Exception\Connect_Exception;
use Guzzle_Http\Exception\Request_Exception;
use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Fulfilled_Promise;
use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Psr7;
use Guzzle_Http\Transfer_Stats;
use Guzzle_Http\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * HTTP handler that uses PHP's HTTP stream wrapper.
 *
 * @final
 */
class Stream_Handler
{
    /**
     * @var array
     */
    private $last_headers = [];
    /**
     * Sends an HTTP request.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Request transfer options.
     */
    public function __invoke(Request_Interface $request, array $options): Promise_Interface
    {
        // Sleep if there is a delay specified.
        if (isset($options['delay'])) {
            \usleep($options['delay'] * 1000);
        }
        $protocol_version = $request->get_protocol_version();
        if ('1.0' !== $protocol_version && '1.1' !== $protocol_version) {
            throw new Connect_Exception(sprintf('HTTP/%s is not supported by the stream handler.', $protocol_version), $request);
        }
        $start_time = isset($options['on_stats']) ? Utils::current_time() : null;
        try {
            // Does not support the expect header.
            $request = $request->without_header('Expect');
            // Append a content-length header if body size is zero to match
            // the behavior of `CurlHandler`
            if ((0 === \strcasecmp('PUT', $request->get_method()) || 0 === \strcasecmp('POST', $request->get_method())) && 0 === $request->get_body()->get_size()) {
                $request = $request->with_header('Content-Length', '0');
            }
            return $this->create_response($request, $options, $this->create_stream($request, $options), $start_time);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Determine if the error was a networking error.
            $message = $e->get_message();
            // This list can probably get more comprehensive.
            if (false !== \strpos($message, 'getaddrinfo') || false !== \strpos($message, 'Connection refused') || false !== \strpos($message, "couldn't connect to host") || false !== \strpos($message, 'connection attempt failed')) {
                $e = new Connect_Exception($e->get_message(), $request, $e);
            } else {
                $e = Request_Exception::wrap_exception($request, $e);
            }
            $this->invoke_stats($options, $request, $start_time, null, $e);
            return P\Create::rejection_for($e);
        }
    }
    private function invoke_stats(array $options, Request_Interface $request, ?float $start_time, ?Response_Interface $response = null, ?\Throwable $error = null): void
    {
        if (isset($options['on_stats'])) {
            $stats = new Transfer_Stats($request, $response, Utils::current_time() - $start_time, $error, []);
            $options['on_stats']($stats);
        }
    }
    /**
     * @param resource $stream
     */
    private function create_response(Request_Interface $request, array $options, $stream, ?float $start_time): Promise_Interface
    {
        $hdrs = $this->last_headers;
        $this->last_headers = [];
        try {
            [$ver, $status, $reason, $headers] = Header_Processor::parse_headers($hdrs);
        } catch (\Exception $e) {
            return P\Create::rejection_for(new Request_Exception('An error was encountered while creating the response', $request, null, $e));
        }
        [$stream, $headers] = $this->check_decode($options, $headers, $stream);
        $stream = Psr7\Utils::stream_for($stream);
        $sink = $stream;
        if (\strcasecmp('HEAD', $request->get_method())) {
            $sink = $this->create_sink($stream, $options);
        }
        try {
            $response = new Psr7\Response($status, $headers, $sink, $ver, $reason);
        } catch (\Exception $e) {
            return P\Create::rejection_for(new Request_Exception('An error was encountered while creating the response', $request, null, $e));
        }
        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response);
            } catch (\Exception $e) {
                return P\Create::rejection_for(new Request_Exception('An error was encountered during the on_headers event', $request, $response, $e));
            }
        }
        // Do not drain when the request is a HEAD request because they have
        // no body.
        if ($sink !== $stream) {
            $this->drain($stream, $sink, $response->get_header_line('Content-Length'));
        }
        $this->invoke_stats($options, $request, $start_time, $response);
        return new Fulfilled_Promise($response);
    }
    private function create_sink(Stream_Interface $stream, array $options): Stream_Interface
    {
        if (!empty($options['stream'])) {
            return $stream;
        }
        $sink = $options['sink'] ?? Psr7\Utils::try_fopen('php://temp', 'r+');
        return \is_string($sink) ? new Psr7\Lazy_Open_Stream($sink, 'w+') : Psr7\Utils::stream_for($sink);
    }
    /**
     * @param resource $stream
     */
    private function check_decode(array $options, array $headers, $stream): array
    {
        // Automatically decode responses when instructed.
        if (!empty($options['decode_content'])) {
            $normalized_keys = Utils::normalize_header_keys($headers);
            if (isset($normalized_keys['content-encoding'])) {
                $encoding = $headers[$normalized_keys['content-encoding']];
                if ($encoding[0] === 'gzip' || $encoding[0] === 'deflate') {
                    $stream = new Psr7\Inflate_Stream(Psr7\Utils::stream_for($stream));
                    $headers['x-encoded-content-encoding'] = $headers[$normalized_keys['content-encoding']];
                    // Remove content-encoding header
                    unset($headers[$normalized_keys['content-encoding']]);
                    // Fix content-length header
                    if (isset($normalized_keys['content-length'])) {
                        $headers['x-encoded-content-length'] = $headers[$normalized_keys['content-length']];
                        $length = (int) $stream->get_size();
                        if ($length === 0) {
                            unset($headers[$normalized_keys['content-length']]);
                        } else {
                            $headers[$normalized_keys['content-length']] = [$length];
                        }
                    }
                }
            }
        }
        return [$stream, $headers];
    }
    /**
     * Drains the source stream into the "sink" client option.
     *
     * @param string $contentLength Header specifying the amount of
     *                              data to read.
     *
     * @throws \RuntimeException when the sink option is invalid.
     */
    private function drain(Stream_Interface $source, Stream_Interface $sink, string $content_length): Stream_Interface
    {
        // If a content-length header is provided, then stop reading once
        // that number of bytes has been read. This can prevent infinitely
        // reading from a stream when dealing with servers that do not honor
        // Connection: Close headers.
        Psr7\Utils::copy_to_stream($source, $sink, \strlen($content_length) > 0 && (int) $content_length > 0 ? (int) $content_length : -1);
        $sink->seek(0);
        $source->close();
        return $sink;
    }
    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable $callback Callable that returns stream resource
     *
     * @return resource
     *
     * @throws \RuntimeException on error
     */
    private function create_resource(callable $callback)
    {
        $errors = [];
        \set_error_handler(static function ($_, $msg, $file, $line) use (&$errors): bool {
            $errors[] = ['message' => $msg, 'file' => $file, 'line' => $line];
            return true;
        });
        try {
            $resource = $callback();
        } finally {
            \restore_error_handler();
        }
        if (!$resource) {
            $message = 'Error creating resource: ';
            foreach ($errors as $err) {
                foreach ($err as $key => $value) {
                    $message .= "[{$key}] {$value}" . \PHP_EOL;
                }
            }
            throw new \RuntimeException(\trim($message));
        }
        return $resource;
    }
    /**
     * @return resource
     */
    private function create_stream(Request_Interface $request, array $options)
    {
        static $methods;
        if (!$methods) {
            $methods = \array_flip(\get_class_methods(self::class));
        }
        if (!\in_array($request->get_uri()->get_scheme(), ['http', 'https'])) {
            throw new Request_Exception(\sprintf("The scheme '%s' is not supported.", $request->get_uri()->get_scheme()), $request);
        }
        // HTTP/1.1 streams using the PHP stream wrapper require a
        // Connection: close header
        if ($request->get_protocol_version() === '1.1' && !$request->has_header('Connection')) {
            $request = $request->with_header('Connection', 'close');
        }
        // Ensure SSL is verified by default
        if (!isset($options['verify'])) {
            $options['verify'] = true;
        }
        $params = [];
        $context = $this->get_default_context($request);
        if (isset($options['on_headers']) && !\is_callable($options['on_headers'])) {
            throw new \InvalidArgumentException('on_headers must be callable');
        }
        foreach ($options as $key => $value) {
            $method = "add_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $context, $value, $params);
            }
        }
        if (isset($options['stream_context'])) {
            if (!\is_array($options['stream_context'])) {
                throw new \InvalidArgumentException('stream_context must be an array');
            }
            $context = \array_replace_recursive($context, $options['stream_context']);
        }
        // Microsoft NTLM authentication only supported with curl handler
        if (isset($options['auth'][2]) && 'ntlm' === $options['auth'][2]) {
            throw new \InvalidArgumentException('Microsoft NTLM authentication only supported with curl handler');
        }
        $uri = $this->resolve_host($request, $options);
        $context_resource = $this->create_resource(static function () use ($context, $params) {
            return \stream_context_create($context, $params);
        });
        return $this->create_resource(function () use ($uri, $context_resource, $context, $options, $request) {
            $resource = @\fopen((string) $uri, 'r', false, $context_resource);
            // See https://wiki.php.net/rfc/deprecations_php_8_5#deprecate_the_http_response_header_predefined_variable
            if (function_exists('http_get_last_response_headers')) {
                /** @var array|null */
                $http_response_header = \http_get_last_response_headers();
            }
            $this->last_headers = $http_response_header ?? [];
            if (false === $resource) {
                throw new Connect_Exception(sprintf('Connection refused for URI %s', $uri), $request, null, $context);
            }
            if (isset($options['read_timeout'])) {
                $read_timeout = $options['read_timeout'];
                $sec = (int) $read_timeout;
                $usec = ($read_timeout - $sec) * 1000000;
                \stream_set_timeout($resource, $sec, $usec);
            }
            return $resource;
        });
    }
    private function resolve_host(Request_Interface $request, array $options): Uri_Interface
    {
        $uri = $request->get_uri();
        if (isset($options['force_ip_resolve']) && !\filter_var($uri->get_host(), \FILTER_VALIDATE_IP)) {
            if ('v4' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->get_host(), \DNS_A);
                if (false === $records || !isset($records[0]['ip'])) {
                    throw new Connect_Exception(\sprintf("Could not resolve IPv4 address for host '%s'", $uri->get_host()), $request);
                }
                return $uri->with_host($records[0]['ip']);
            }
            if ('v6' === $options['force_ip_resolve']) {
                $records = \dns_get_record($uri->get_host(), \DNS_AAAA);
                if (false === $records || !isset($records[0]['ipv6'])) {
                    throw new Connect_Exception(\sprintf("Could not resolve IPv6 address for host '%s'", $uri->get_host()), $request);
                }
                return $uri->with_host('[' . $records[0]['ipv6'] . ']');
            }
        }
        return $uri;
    }
    private function get_default_context(Request_Interface $request): array
    {
        $headers = '';
        foreach ($request->get_headers() as $name => $value) {
            foreach ($value as $val) {
                $headers .= "{$name}: {$val}\r\n";
            }
        }
        $context = ['http' => ['method' => $request->get_method(), 'header' => $headers, 'protocol_version' => $request->get_protocol_version(), 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['peer_name' => $request->get_uri()->get_host()]];
        $body = (string) $request->get_body();
        if ('' !== $body) {
            $context['http']['content'] = $body;
            // Prevent the HTTP handler from adding a Content-Type header.
            if (!$request->has_header('Content-Type')) {
                $context['http']['header'] .= "Content-Type:\r\n";
            }
        }
        $context['http']['header'] = \rtrim($context['http']['header']);
        return $context;
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_proxy(Request_Interface $request, array &$options, $value): void
    {
        $uri = null;
        if (!\is_array($value)) {
            $uri = $value;
        } else {
            $scheme = $request->get_uri()->get_scheme();
            if (isset($value[$scheme])) {
                if (!isset($value['no']) || !Utils::is_host_in_no_proxy($request->get_uri()->get_host(), $value['no'])) {
                    $uri = $value[$scheme];
                }
            }
        }
        if (!$uri) {
            return;
        }
        $parsed = $this->parse_proxy($uri);
        $options['http']['proxy'] = $parsed['proxy'];
        if ($parsed['auth']) {
            if (!isset($options['http']['header'])) {
                $options['http']['header'] = [];
            }
            $options['http']['header'] .= "\r\nProxy-Authorization: {$parsed['auth']}";
        }
    }
    /**
     * Parses the given proxy URL to make it compatible with the format PHP's stream context expects.
     */
    private function parse_proxy(string $url): array
    {
        $parsed = \parse_url($url);
        if ($parsed !== false && isset($parsed['scheme']) && $parsed['scheme'] === 'http') {
            if (isset($parsed['host']) && isset($parsed['port'])) {
                $auth = null;
                if (isset($parsed['user']) && isset($parsed['pass'])) {
                    $auth = \base64_encode("{$parsed['user']}:{$parsed['pass']}");
                }
                return ['proxy' => "tcp://{$parsed['host']}:{$parsed['port']}", 'auth' => $auth ? "Basic {$auth}" : null];
            }
        }
        // Return proxy as-is.
        return ['proxy' => $url, 'auth' => null];
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_timeout(array &$options, $value): void
    {
        if ($value > 0) {
            $options['http']['timeout'] = $value;
        }
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_crypto_method(array &$options, $value): void
    {
        if ($value === \Stream_crypto_method_tl_Sv1_0_client || $value === \Stream_crypto_method_tl_Sv1_1_client || $value === \Stream_crypto_method_tl_Sv1_2_client || defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') && $value === \Stream_crypto_method_tl_Sv1_3_client) {
            $options['http']['crypto_method'] = $value;
            return;
        }
        throw new \InvalidArgumentException('Invalid crypto_method request option: unknown version provided');
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_verify(array &$options, $value): void
    {
        if ($value === false) {
            $options['ssl']['verify_peer'] = false;
            $options['ssl']['verify_peer_name'] = false;
            return;
        }
        if (\is_string($value)) {
            $options['ssl']['cafile'] = $value;
            if (!\file_exists($value)) {
                throw new \RuntimeException("SSL CA bundle not found: {$value}");
            }
        } elseif ($value !== true) {
            throw new \InvalidArgumentException('Invalid verify request option');
        }
        $options['ssl']['verify_peer'] = true;
        $options['ssl']['verify_peer_name'] = true;
        $options['ssl']['allow_self_signed'] = false;
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_cert(array &$options, $value): void
    {
        if (\is_array($value)) {
            $options['ssl']['passphrase'] = $value[1];
            $value = $value[0];
        }
        if (!\file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }
        $options['ssl']['local_cert'] = $value;
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_progress($value, array &$params): void
    {
        self::add_notification($params, static function ($code, $a, $b, $c, $transferred, $total) use ($value): void {
            if ($code == \STREAM_NOTIFY_PROGRESS) {
                // The upload progress cannot be determined. Use 0 for cURL compatibility:
                // https://curl.se/libcurl/c/CURLOPT_PROGRESSFUNCTION.html
                $value($total, $transferred, 0, 0);
            }
        });
    }
    /**
     * @param mixed $value as passed via Request transfer options.
     */
    private function add_debug(Request_Interface $request, $value, array &$params): void
    {
        if ($value === false) {
            return;
        }
        static $map = [\STREAM_NOTIFY_CONNECT => 'CONNECT', \STREAM_NOTIFY_AUTH_REQUIRED => 'AUTH_REQUIRED', \STREAM_NOTIFY_AUTH_RESULT => 'AUTH_RESULT', \STREAM_NOTIFY_MIME_TYPE_IS => 'MIME_TYPE_IS', \STREAM_NOTIFY_FILE_SIZE_IS => 'FILE_SIZE_IS', \STREAM_NOTIFY_REDIRECTED => 'REDIRECTED', \STREAM_NOTIFY_PROGRESS => 'PROGRESS', \STREAM_NOTIFY_FAILURE => 'FAILURE', \STREAM_NOTIFY_COMPLETED => 'COMPLETED', \STREAM_NOTIFY_RESOLVE => 'RESOLVE'];
        static $args = ['severity', 'message', 'message_code', 'bytes_transferred', 'bytes_max'];
        $value = Utils::debug_resource($value);
        $ident = $request->get_method() . ' ' . $request->get_uri()->with_fragment('');
        self::add_notification($params, static function (int $code, ...$passed) use ($ident, $value, $map, $args): void {
            \fprintf($value, '<%s> [%s] ', $ident, $map[$code]);
            foreach (\array_filter($passed) as $i => $v) {
                \fwrite($value, $args[$i] . ': "' . $v . '" ');
            }
            \fwrite($value, "\n");
        });
    }
    private static function add_notification(array &$params, callable $notify): void
    {
        // Wrap the existing function if needed.
        if (!isset($params['notification'])) {
            $params['notification'] = $notify;
        } else {
            $params['notification'] = self::call_array([$params['notification'], $notify]);
        }
    }
    private static function call_array(array $functions): callable
    {
        return static function (...$args) use ($functions): void {
            foreach ($functions as $fn) {
                $fn(...$args);
            }
        };
    }
}
<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Cookie\Cookie_Jar;
use Guzzle_Http\Exception\Guzzle_Exception;
use Guzzle_Http\Exception\InvalidArgumentException;
use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Promise_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * @final
 */
class Client implements Client_Interface, \Psr\Http\Client\Client_Interface
{
    use Client_Trait;
    /**
     * @var array Default request options
     */
    private $config;
    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a base_uri and an array of
     * default request options to apply to each request:
     *
     *     $client = new Client([
     *         'base_uri'        => 'http://www.foo.com/1.0/',
     *         'timeout'         => 0,
     *         'allow_redirects' => false,
     *         'proxy'           => '192.168.16.1:10'
     *     ]);
     *
     * Client configuration settings include the following options:
     *
     * - handler: (callable) Function that transfers HTTP requests over the
     *   wire. The function is called with a Psr7\Http\Message\RequestInterface
     *   and array of transfer options, and must return a
     *   GuzzleHttp\Promise\PromiseInterface that is fulfilled with a
     *   Psr7\Http\Message\ResponseInterface on success.
     *   If no handler is provided, a default handler will be created
     *   that enables all of the request options below by attaching all of the
     *   default middleware to the handler.
     * - base_uri: (string|UriInterface) Base URI of the client that is merged
     *   into relative URIs. Can be a string or instance of UriInterface.
     * - **: any request option
     *
     * @param array $config Client configuration settings.
     *
     * @see RequestOptions for a list of available request options.
     */
    public function __construct(array $config = [])
    {
        if (!isset($config['handler'])) {
            $config['handler'] = Handler_Stack::create();
        } elseif (!\is_callable($config['handler'])) {
            throw new InvalidArgumentException('handler must be a callable');
        }
        // Convert the base_uri to a UriInterface
        if (isset($config['base_uri'])) {
            $config['base_uri'] = Psr7\Utils::uri_for($config['base_uri']);
        }
        $this->configure_defaults($config);
    }
    /**
     * Magic method providing shorthand HTTP verb methods (get, post, put, delete, …).
     *
     * Synchronous call:  `$client->get($uri, $options)` → `ResponseInterface`
     * Async call suffix: `$client->getAsync($uri, $options)` → `PromiseInterface`
     *
     * @param  string                    $method The HTTP verb or verb+'Async' (e.g. 'post', 'putAsync').
     * @param  array<int, mixed>         $args   First element is the URI (string|UriInterface),
     *                                           second optional element is the options array.
     *
     * @return Promise_Interface|Response_Interface
     * @throws InvalidArgumentException When called with fewer than one argument.
     * @deprecated since 7.0 — Use the explicit send()/sendAsync()/request()/requestAsync() methods instead.
     *             Client::__call will be removed in guzzlehttp/guzzle:8.0.
     */
    public function __call(string $method, array $args): Promise_Interface|Response_Interface
    {
        trigger_error("Client::{$method}() via magic __call is deprecated since Guzzle 7.0 and will be removed in 8.0. Use request()/requestAsync() instead.", E_USER_DEPRECATED);
        if (\count($args) < 1) {
            throw new InvalidArgumentException('Magic request methods require a URI and optional options array');
        }
        $uri = $args[0];
        $opts = $args[1] ?? [];
        return \substr($method, -5) === 'Async' ? $this->request_async(\substr($method, 0, -5), $uri, $opts) : $this->request($method, $uri, $opts);
    }
    /**
     * Asynchronously send a PSR-7 request object.
     *
     * The returned promise resolves with a `ResponseInterface` on success.
     * Use `->wait()` to block until the response is received.
     *
     * @param  Request_Interface      $request The PSR-7 request to send. Its URI may be merged with base_uri.
     * @param  array<string, mixed>   $options Per-request options that override client defaults.
     *                                         See {@see Request_Options} for the full list.
     *
     * @return Promise_Interface A promise that resolves with a ResponseInterface.
     * @since  6.0
     * @see    send()          The synchronous variant.
     * @see    request_async() For building requests from method/URI strings.
     */
    public function send_async(Request_Interface $request, array $options = []): Promise_Interface
    {
        // Merge the base URI into the request URI if needed.
        $options = $this->prepare_defaults($options);
        return $this->transfer($request->with_uri($this->build_uri($request->get_uri(), $options), $request->has_header('Host')), $options);
    }
    /**
     * Synchronously send a PSR-7 request and return the response.
     *
     * Blocks until the full response is received or an exception is thrown.
     * HTTP error status codes (4xx, 5xx) throw a `Bad_Response_Exception`
     * unless `http_errors` is set to false.
     *
     * @param  Request_Interface    $request The PSR-7 request to send.
     * @param  array<string, mixed> $options Per-request overrides; see {@see Request_Options}.
     *
     * @return Response_Interface The PSR-7 response object.
     * @throws \Guzzle_Http\Exception\Guzzle_Exception On network error or (by default) 4xx/5xx response.
     * @since  6.0
     * @see    send_async() The non-blocking variant.
     */
    public function send(Request_Interface $request, array $options = []): Response_Interface
    {
        $options[Request_Options::SYNCHRONOUS] = true;
        return $this->send_async($request, $options)->wait();
    }
    /**
     * The HttpClient PSR (PSR-18) specify this method.
     *
     * {@inheritDoc}
     */
    public function send_request(Request_Interface $request): Response_Interface
    {
        $options[Request_Options::SYNCHRONOUS] = true;
        $options[Request_Options::ALLOW_REDIRECTS] = false;
        $options[Request_Options::HTTP_ERRORS] = false;
        return $this->send_async($request, $options)->wait();
    }
    /**
     * Create and send an asynchronous HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string              $method  HTTP method
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply. See \GuzzleHttp\RequestOptions.
     */
    public function request_async(string $method, $uri = '', array $options = []): Promise_Interface
    {
        $options = $this->prepare_defaults($options);
        // Remove request modifying parameter because it can be done up-front.
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $version = $options['version'] ?? '1.1';
        // Merge the URI into the base URI.
        $uri = $this->build_uri(Psr7\Utils::uri_for($uri), $options);
        if (\is_array($body)) {
            throw $this->invalid_body();
        }
        $request = new Psr7\Request($method, $uri, $headers, $body, $version);
        // Remove the option so that they are not doubly-applied.
        unset($options['headers'], $options['body'], $options['version']);
        return $this->transfer($request, $options);
    }
    /**
     * Create and send an HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string              $method  HTTP method.
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply. See \GuzzleHttp\RequestOptions.
     *
     * @throws GuzzleException
     */
    public function request(string $method, $uri = '', array $options = []): Response_Interface
    {
        $options[Request_Options::SYNCHRONOUS] = true;
        return $this->request_async($method, $uri, $options)->wait();
    }
    /**
     * Returns a client configuration option or the entire config array.
     *
     * @param  ?string $option The option key to retrieve (e.g. 'base_uri', 'timeout').
     *                         Pass null to retrieve the full configuration array.
     *
     * @return mixed The option value, or null if the key does not exist, or the full array when $option is null.
     *
     * @deprecated since 7.0 — Inspect options at construction time; this method will be removed in guzzlehttp/guzzle:8.0.
     */
    public function get_config(?string $option = null): mixed
    {
        trigger_error('Client::get_config() is deprecated since Guzzle 7.0 and will be removed in 8.0.', E_USER_DEPRECATED);
        return $option === null ? $this->config : $this->config[$option] ?? null;
    }
    private function build_uri(Uri_Interface $uri, array $config): Uri_Interface
    {
        if (isset($config['base_uri'])) {
            $uri = Psr7\Uri_Resolver::resolve(Psr7\Utils::uri_for($config['base_uri']), $uri);
        }
        if (isset($config['idn_conversion']) && $config['idn_conversion'] !== false) {
            $idn_options = $config['idn_conversion'] === true ? \IDNA_DEFAULT : $config['idn_conversion'];
            $uri = Utils::idn_uri_convert($uri, $idn_options);
        }
        return $uri->get_scheme() === '' && $uri->get_host() !== '' ? $uri->with_scheme('http') : $uri;
    }
    /**
     * Configures the default options for a client.
     */
    private function configure_defaults(array $config): void
    {
        $defaults = ['allow_redirects' => Redirect_Middleware::$default_settings, 'http_errors' => true, 'decode_content' => true, 'verify' => true, 'cookies' => false, 'idn_conversion' => false];
        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set.
        // We can only trust the HTTP_PROXY environment variable in a CLI
        // process due to the fact that PHP has no reliable mechanism to
        // get environment variables that start with "HTTP_".
        if (\PHP_SAPI === 'cli' && $proxy = Utils::getenv('HTTP_PROXY')) {
            $defaults['proxy']['http'] = $proxy;
        }
        if ($proxy = Utils::getenv('HTTPS_PROXY')) {
            $defaults['proxy']['https'] = $proxy;
        }
        if ($no_proxy = Utils::getenv('NO_PROXY')) {
            $cleaned_no_proxy = \str_replace(' ', '', $no_proxy);
            $defaults['proxy']['no'] = \explode(',', $cleaned_no_proxy);
        }
        $this->config = $config + $defaults;
        if (!empty($config['cookies']) && $config['cookies'] === true) {
            $this->config['cookies'] = new Cookie_Jar();
        }
        // Add the default user-agent header.
        if (!isset($this->config['headers'])) {
            $this->config['headers'] = ['User-Agent' => Utils::default_user_agent()];
        } else {
            // Add the User-Agent header if one was not already set.
            foreach (\array_keys($this->config['headers']) as $name) {
                if (\strtolower($name) === 'user-agent') {
                    return;
                }
            }
            $this->config['headers']['User-Agent'] = Utils::default_user_agent();
        }
    }
    /**
     * Merges default options into the array.
     *
     * @param array $options Options to modify by reference
     */
    private function prepare_defaults(array $options): array
    {
        $defaults = $this->config;
        if (!empty($defaults['headers'])) {
            // Default headers are only added if they are not present.
            $defaults['_conditional'] = $defaults['headers'];
            unset($defaults['headers']);
        }
        // Special handling for headers is required as they are added as
        // conditional headers and as headers passed to a request ctor.
        if (\array_key_exists('headers', $options)) {
            // Allows default headers to be unset.
            if ($options['headers'] === null) {
                $defaults['_conditional'] = [];
                unset($options['headers']);
            } elseif (!\is_array($options['headers'])) {
                throw new InvalidArgumentException('headers must be an array');
            }
        }
        // Shallow merge defaults underneath options.
        $result = $options + $defaults;
        // Remove null values.
        foreach ($result as $k => $v) {
            if ($v === null) {
                unset($result[$k]);
            }
        }
        return $result;
    }
    /**
     * Transfers the given request and applies request options.
     *
     * The URI of the request is not modified and the request options are used
     * as-is without merging in default options.
     *
     * @param array $options See \GuzzleHttp\RequestOptions.
     */
    private function transfer(Request_Interface $request, array $options): Promise_Interface
    {
        $request = $this->apply_options($request, $options);
        /** @var HandlerStack $handler */
        $handler = $options['handler'];
        try {
            return P\Create::promise_for($handler($request, $options));
        } catch (\Exception $e) {
            return P\Create::rejection_for($e);
        }
    }
    /**
     * Applies the array of request options to a request.
     */
    private function apply_options(Request_Interface $request, array &$options): Request_Interface
    {
        $modify = ['set_headers' => []];
        if (isset($options['headers'])) {
            if (array_keys($options['headers']) === range(0, count($options['headers']) - 1)) {
                throw new InvalidArgumentException('The headers array must have header name as keys.');
            }
            $modify['set_headers'] = $options['headers'];
            unset($options['headers']);
        }
        if (isset($options['form_params'])) {
            if (isset($options['multipart'])) {
                throw new InvalidArgumentException('You cannot use ' . 'form_params and multipart at the same time. Use the ' . 'form_params option if you want to send application/' . 'x-www-form-urlencoded requests, and the multipart ' . 'option to send multipart/form-data requests.');
            }
            $options['body'] = \http_build_query($options['form_params'], '', '&');
            unset($options['form_params']);
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caseless_remove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if (isset($options['multipart'])) {
            $options['body'] = new Psr7\Multipart_Stream($options['multipart']);
            unset($options['multipart']);
        }
        if (isset($options['json'])) {
            $options['body'] = Utils::json_encode($options['json']);
            unset($options['json']);
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caseless_remove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'application/json';
        }
        if (!empty($options['decode_content']) && $options['decode_content'] !== true) {
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caseless_remove(['Accept-Encoding'], $options['_conditional']);
            $modify['set_headers']['Accept-Encoding'] = $options['decode_content'];
        }
        if (isset($options['body'])) {
            if (\is_array($options['body'])) {
                throw $this->invalid_body();
            }
            $modify['body'] = Psr7\Utils::stream_for($options['body']);
            unset($options['body']);
        }
        if (!empty($options['auth']) && \is_array($options['auth'])) {
            $value = $options['auth'];
            $type = isset($value[2]) ? \strtolower($value[2]) : 'basic';
            switch ($type) {
                case 'basic':
                    // Ensure that we don't have the header in different case and set the new value.
                    $modify['set_headers'] = Psr7\Utils::caseless_remove(['Authorization'], $modify['set_headers']);
                    $modify['set_headers']['Authorization'] = 'Basic ' . \base64_encode("{$value[0]}:{$value[1]}");
                    break;
                case 'digest':
                    // @todo: Do not rely on curl
                    $options['curl'][\CURLOPT_HTTPAUTH] = \CURLAUTH_DIGEST;
                    $options['curl'][\CURLOPT_USERPWD] = "{$value[0]}:{$value[1]}";
                    break;
                case 'ntlm':
                    $options['curl'][\CURLOPT_HTTPAUTH] = \CURLAUTH_NTLM;
                    $options['curl'][\CURLOPT_USERPWD] = "{$value[0]}:{$value[1]}";
                    break;
            }
        }
        if (isset($options['query'])) {
            $value = $options['query'];
            if (\is_array($value)) {
                $value = \http_build_query($value, '', '&', \PHP_QUERY_RFC3986);
            }
            if (!\is_string($value)) {
                throw new InvalidArgumentException('query must be a string or array');
            }
            $modify['query'] = $value;
            unset($options['query']);
        }
        // Ensure that sink is not an invalid value.
        if (isset($options['sink'])) {
            // TODO: Add more sink validation?
            if (\is_bool($options['sink'])) {
                throw new InvalidArgumentException('sink must not be a boolean');
            }
        }
        if (isset($options['version'])) {
            $modify['version'] = $options['version'];
        }
        $request = Psr7\Utils::modify_request($request, $modify);
        if ($request->get_body() instanceof Psr7\Multipart_Stream) {
            // Use a multipart/form-data POST if a Content-Type is not set.
            // Ensure that we don't have the header in different case and set the new value.
            $options['_conditional'] = Psr7\Utils::caseless_remove(['Content-Type'], $options['_conditional']);
            $options['_conditional']['Content-Type'] = 'multipart/form-data; boundary=' . $request->get_body()->get_boundary();
        }
        // Merge in conditional headers if they are not present.
        if (isset($options['_conditional'])) {
            // Build up the changes so it's in a single clone of the message.
            $modify = [];
            foreach ($options['_conditional'] as $k => $v) {
                if (!$request->has_header($k)) {
                    $modify['set_headers'][$k] = $v;
                }
            }
            $request = Psr7\Utils::modify_request($request, $modify);
            // Don't pass this internal value along to middleware/handlers.
            unset($options['_conditional']);
        }
        return $request;
    }
    /**
     * Return an InvalidArgumentException with pre-set message.
     */
    private function invalid_body(): InvalidArgumentException
    {
        return new InvalidArgumentException('Passing in the "body" request ' . 'option as an array to send a request is not supported. ' . 'Please use the "form_params" request option to send a ' . 'application/x-www-form-urlencoded request, or the "multipart" ' . 'request option to send a multipart/form-data request.');
    }
}
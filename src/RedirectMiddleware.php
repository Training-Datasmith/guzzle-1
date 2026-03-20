<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Exception\Bad_Response_Exception;
use Guzzle_Http\Exception\Too_Many_Redirects_Exception;
use Guzzle_Http\Promise\Promise_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Request redirect middleware.
 *
 * Apply this middleware like other middleware using
 * {@see Middleware::redirect()}.
 *
 * @final
 */
class Redirect_Middleware
{
    public const HISTORY_HEADER = 'X-Guzzle-Redirect-History';
    public const STATUS_HISTORY_HEADER = 'X-Guzzle-Redirect-Status-History';
    /**
     * @var array
     */
    public static $default_settings = ['max' => 5, 'protocols' => ['http', 'https'], 'strict' => false, 'referer' => false, 'track_redirects' => false];
    /**
     * @var callable(RequestInterface, array): PromiseInterface
     */
    private $next_handler;
    /**
     * @param callable(RequestInterface, array): PromiseInterface $nextHandler Next handler to invoke.
     */
    public function __construct(callable $next_handler)
    {
        $this->next_handler = $next_handler;
    }
    public function __invoke(Request_Interface $request, array $options): Promise_Interface
    {
        $fn = $this->next_handler;
        if (empty($options['allow_redirects'])) {
            return $fn($request, $options);
        }
        if ($options['allow_redirects'] === true) {
            $options['allow_redirects'] = self::$default_settings;
        } elseif (!\is_array($options['allow_redirects'])) {
            throw new \InvalidArgumentException('allow_redirects must be true, false, or array');
        } else {
            // Merge the default settings with the provided settings
            $options['allow_redirects'] += self::$default_settings;
        }
        if (empty($options['allow_redirects']['max'])) {
            return $fn($request, $options);
        }
        return $fn($request, $options)->then(function (Response_Interface $response) use ($request, $options) {
            return $this->check_redirect($request, $options, $response);
        });
    }
    /**
     * @return ResponseInterface|PromiseInterface
     */
    public function check_redirect(Request_Interface $request, array $options, Response_Interface $response)
    {
        if (\strpos((string) $response->get_status_code(), '3') !== 0 || !$response->has_header('Location')) {
            return $response;
        }
        $this->guard_max($request, $response, $options);
        $next_request = $this->modify_request($request, $options, $response);
        // If authorization is handled by curl, unset it if URI is cross-origin.
        if (Psr7\Uri_Comparator::is_cross_origin($request->get_uri(), $next_request->get_uri()) && defined('\CURLOPT_HTTPAUTH')) {
            unset($options['curl'][\CURLOPT_HTTPAUTH], $options['curl'][\CURLOPT_USERPWD]);
        }
        if (isset($options['allow_redirects']['on_redirect'])) {
            $options['allow_redirects']['on_redirect']($request, $response, $next_request->get_uri());
        }
        $promise = $this($next_request, $options);
        // Add headers to be able to track history of redirects.
        if (!empty($options['allow_redirects']['track_redirects'])) {
            return $this->with_tracking($promise, (string) $next_request->get_uri(), $response->get_status_code());
        }
        return $promise;
    }
    /**
     * Enable tracking on promise.
     */
    private function with_tracking(Promise_Interface $promise, string $uri, int $status_code): Promise_Interface
    {
        return $promise->then(static function (Response_Interface $response) use ($uri, $status_code) {
            // Note that we are pushing to the front of the list as this
            // would be an earlier response than what is currently present
            // in the history header.
            $history_header = $response->get_header(self::HISTORY_HEADER);
            $status_header = $response->get_header(self::STATUS_HISTORY_HEADER);
            \array_unshift($history_header, $uri);
            \array_unshift($status_header, (string) $status_code);
            return $response->with_header(self::HISTORY_HEADER, $history_header)->with_header(self::STATUS_HISTORY_HEADER, $status_header);
        });
    }
    /**
     * Check for too many redirects.
     *
     * @throws TooManyRedirectsException Too many redirects.
     */
    private function guard_max(Request_Interface $request, Response_Interface $response, array &$options): void
    {
        $current = $options['__redirect_count'] ?? 0;
        $options['__redirect_count'] = $current + 1;
        $max = $options['allow_redirects']['max'];
        if ($options['__redirect_count'] > $max) {
            throw new Too_Many_Redirects_Exception("Will not follow more than {$max} redirects", $request, $response);
        }
    }
    public function modify_request(Request_Interface $request, array $options, Response_Interface $response): Request_Interface
    {
        // Request modifications to apply.
        $modify = [];
        $protocols = $options['allow_redirects']['protocols'];
        // Use a GET request if this is an entity enclosing request and we are
        // not forcing RFC compliance, but rather emulating what all browsers
        // would do.
        $status_code = $response->get_status_code();
        if ($status_code == 303 || $status_code <= 302 && !$options['allow_redirects']['strict']) {
            $safe_methods = ['GET', 'HEAD', 'OPTIONS'];
            $request_method = $request->get_method();
            $modify['method'] = in_array($request_method, $safe_methods) ? $request_method : 'GET';
            $modify['body'] = '';
        }
        $uri = self::redirect_uri($request, $response, $protocols);
        if (isset($options['idn_conversion']) && $options['idn_conversion'] !== false) {
            $idn_options = $options['idn_conversion'] === true ? \IDNA_DEFAULT : $options['idn_conversion'];
            $uri = Utils::idn_uri_convert($uri, $idn_options);
        }
        $modify['uri'] = $uri;
        Psr7\Message::rewind_body($request);
        // Add the Referer header if it is told to do so and only
        // add the header if we are not redirecting from https to http.
        if ($options['allow_redirects']['referer'] && $modify['uri']->get_scheme() === $request->get_uri()->get_scheme()) {
            $uri = $request->get_uri()->with_user_info('');
            $modify['set_headers']['Referer'] = (string) $uri;
        } else {
            $modify['remove_headers'][] = 'Referer';
        }
        // Remove Authorization and Cookie headers if URI is cross-origin.
        if (Psr7\Uri_Comparator::is_cross_origin($request->get_uri(), $modify['uri'])) {
            $modify['remove_headers'][] = 'Authorization';
            $modify['remove_headers'][] = 'Cookie';
        }
        return Psr7\Utils::modify_request($request, $modify);
    }
    /**
     * Set the appropriate URL on the request based on the location header.
     */
    private static function redirect_uri(Request_Interface $request, Response_Interface $response, array $protocols): Uri_Interface
    {
        $location = Psr7\Uri_Resolver::resolve($request->get_uri(), new Psr7\Uri($response->get_header_line('Location')));
        // Ensure that the redirect URI is allowed based on the protocols.
        if (!\in_array($location->get_scheme(), $protocols)) {
            throw new Bad_Response_Exception(\sprintf('Redirect URI, %s, does not use one of the allowed redirect protocols: %s', $location, \implode(', ', $protocols)), $request, $response);
        }
        return $location;
    }
}
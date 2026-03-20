<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Immutable value object capturing all transfer data after a request completes
 * (successfully or after a network error).
 *
 * Instances are created by the active handler and passed to the
 * `on_stats` request option callback.
 *
 * @since 6.0
 */
final class Transfer_Stats
{
    /**
     * @param Request_Interface       $request            The PSR-7 request that was sent.
     * @param ?Response_Interface     $response           The PSR-7 response received, or null on network error.
     * @param ?float                  $transfer_time      Total elapsed handler time in seconds, or null if unknown.
     * @param mixed                   $handler_error_data Handler-specific error payload (e.g. a curl error code).
     *                                                    Format depends on the active handler.
     * @param array<string, mixed>    $handler_stats      Map of handler-specific statistics (e.g. cURL info array).
     */
    public function __construct(
        private readonly Request_Interface $request,
        private readonly ?Response_Interface $response = null,
        private readonly ?float $transfer_time = null,
        private readonly mixed $handler_error_data = null,
        private readonly array $handler_stats = []
    ) {}

    /**
     * Returns the PSR-7 request that was sent.
     *
     * @return Request_Interface The original (possibly modified by middleware) request object.
     * @since 6.0
     */
    public function get_request(): Request_Interface
    {
        return $this->request;
    }

    /**
     * Returns the PSR-7 response received from the server, or null on network error.
     *
     * @return ?Response_Interface Null when the transfer failed before a response was received.
     * @since 6.0
     */
    public function get_response(): ?Response_Interface
    {
        return $this->response;
    }

    /**
     * Returns true if a response was received from the server.
     *
     * A true result does not indicate a successful status code — only that
     * the server returned an HTTP response of any kind.
     *
     * @return bool True when get_response() will return a non-null value.
     * @since 6.0
     */
    public function has_response(): bool
    {
        return $this->response !== null;
    }

    /**
     * Returns handler-specific error data when the transfer failed.
     *
     * The format is entirely handler-dependent:
     * - `Curl_Handler`: an integer cURL error code (CURLE_*)
     * - `Stream_Handler`: a PHP error string or null
     * - `Mock_Handler`: the exception thrown from the queue
     *
     * @return mixed Null when no error occurred at the handler level.
     * @since 6.0
     */
    public function get_handler_error_data(): mixed
    {
        return $this->handler_error_data;
    }

    /**
     * Returns the effective URI that the request was actually sent to.
     *
     * This reflects any URI modifications applied by middleware (e.g. base_uri
     * merging, IDN conversion) before the request reached the handler.
     *
     * @return Uri_Interface The final resolved request URI.
     * @since 6.0
     */
    public function get_effective_uri(): Uri_Interface
    {
        return $this->request->get_uri();
    }

    /**
     * Returns the total time the handler spent transferring the request, in seconds.
     *
     * This is the elapsed wall-clock time from the moment the handler received
     * the request to when the response was fully received (or the error occurred).
     * Does not include middleware processing time.
     *
     * @return ?float Seconds as a float (e.g. 0.123), or null if the handler did not record timing.
     * @since 6.0
     */
    public function get_transfer_time(): ?float
    {
        return $this->transfer_time;
    }

    /**
     * Returns all handler-specific statistics as an associative array.
     *
     * For `Curl_Handler` this is the array returned by `curl_getinfo()`.
     *
     * @return array<string, mixed> Key-value map of handler stats. Empty array if the handler reports none.
     * @since 6.0
     */
    public function get_handler_stats(): array
    {
        return $this->handler_stats;
    }

    /**
     * Returns a single named statistic from the handler stats array.
     *
     * @param string $stat The name of the statistic to retrieve (e.g. 'total_time', 'namelookup_time').
     *
     * @return mixed The statistic value, or null if the key is not present.
     * @since 6.0
     * @see   get_handler_stats() To retrieve all stats at once.
     */
    public function get_handler_stat(string $stat): mixed
    {
        return $this->handler_stats[$stat] ?? null;
    }
}
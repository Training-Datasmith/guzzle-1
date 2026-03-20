<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Represents data at the point after it was transferred either successfully
 * or after a network error.
 */
final class Transfer_Stats
{
    /**
     * @var RequestInterface
     */
    private $request;
    /**
     * @var ResponseInterface|null
     */
    private $response;
    /**
     * @var float|null
     */
    private $transfer_time;
    /**
     * @var array
     */
    private $handler_stats;
    /**
     * @var mixed|null
     */
    private $handler_error_data;
    /**
     * @param RequestInterface       $request          Request that was sent.
     * @param ResponseInterface|null $response         Response received (if any)
     * @param float|null             $transferTime     Total handler transfer time.
     * @param mixed                  $handlerErrorData Handler error data.
     * @param array                  $handlerStats     Handler specific stats.
     */
    public function __construct(Request_Interface $request, ?Response_Interface $response = null, ?float $transfer_time = null, $handler_error_data = null, array $handler_stats = [])
    {
        $this->request = $request;
        $this->response = $response;
        $this->transfer_time = $transfer_time;
        $this->handler_error_data = $handler_error_data;
        $this->handler_stats = $handler_stats;
    }
    public function get_request(): Request_Interface
    {
        return $this->request;
    }
    /**
     * Returns the response that was received (if any).
     */
    public function get_response(): ?Response_Interface
    {
        return $this->response;
    }
    /**
     * Returns true if a response was received.
     */
    public function has_response(): bool
    {
        return $this->response !== null;
    }
    /**
     * Gets handler specific error data.
     *
     * This might be an exception, a integer representing an error code, or
     * anything else. Relying on this value assumes that you know what handler
     * you are using.
     *
     * @return mixed
     */
    public function get_handler_error_data()
    {
        return $this->handler_error_data;
    }
    /**
     * Get the effective URI the request was sent to.
     */
    public function get_effective_uri(): Uri_Interface
    {
        return $this->request->get_uri();
    }
    /**
     * Get the estimated time the request was being transferred by the handler.
     *
     * @return float|null Time in seconds.
     */
    public function get_transfer_time(): ?float
    {
        return $this->transfer_time;
    }
    /**
     * Gets an array of all of the handler specific transfer data.
     */
    public function get_handler_stats(): array
    {
        return $this->handler_stats;
    }
    /**
     * Get a specific handler statistic from the handler by name.
     *
     * @param string $stat Handler specific transfer stat to retrieve.
     *
     * @return mixed|null
     */
    public function get_handler_stat(string $stat)
    {
        return $this->handler_stats[$stat] ?? null;
    }
}
<?php

declare (strict_types=1);
namespace Guzzle_Http\Exception;

use Psr\Http\Client\Network_Exception_Interface;
use Psr\Http\Message\Request_Interface;
/**
 * Exception thrown when a connection cannot be established.
 *
 * Note that no response is present for a ConnectException
 */
class Connect_Exception extends Transfer_Exception implements Network_Exception_Interface
{
    /**
     * @var RequestInterface
     */
    private $request;
    /**
     * @var array
     */
    private $handler_context;
    public function __construct(string $message, Request_Interface $request, ?\Throwable $previous = null, array $handler_context = [])
    {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
        $this->handler_context = $handler_context;
    }
    /**
     * Get the request that caused the exception
     */
    public function get_request(): Request_Interface
    {
        return $this->request;
    }
    /**
     * Get contextual information about the error from the underlying handler.
     *
     * The contents of this array will vary depending on which handler you are
     * using. It may also be just an empty array. Relying on this data will
     * couple you to a specific handler, but can give more debug information
     * when needed.
     */
    public function get_handler_context(): array
    {
        return $this->handler_context;
    }
}
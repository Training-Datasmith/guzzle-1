<?php

declare (strict_types=1);
namespace Guzzle_Http\Exception;

use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Exception when an HTTP error occurs (4xx or 5xx error)
 */
class Bad_Response_Exception extends Request_Exception
{
    public function __construct(string $message, Request_Interface $request, Response_Interface $response, ?\Throwable $previous = null, array $handler_context = [])
    {
        parent::__construct($message, $request, $response, $previous, $handler_context);
    }
    /**
     * Current exception and the ones that extend it will always have a response.
     */
    public function has_response(): bool
    {
        return true;
    }
    /**
     * This function narrows the return type from the parent class and does not allow it to be nullable.
     */
    public function get_response(): Response_Interface
    {
        /** @var ResponseInterface */
        return parent::get_response();
    }
}
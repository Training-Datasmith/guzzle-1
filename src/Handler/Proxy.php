<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Request_Options;
use Psr\Http\Message\Request_Interface;
/**
 * Provides basic proxies for handlers.
 *
 * @final
 */
class Proxy
{
    /**
     * Sends synchronous requests to a specific handler while sending all other
     * requests to another handler.
     *
     * @param callable(RequestInterface, array): PromiseInterface $default Handler used for normal responses
     * @param callable(RequestInterface, array): PromiseInterface $sync    Handler used for synchronous responses.
     *
     * @return callable(RequestInterface, array): PromiseInterface Returns the composed handler.
     */
    public static function wrap_sync(callable $default, callable $sync): callable
    {
        return static function (Request_Interface $request, array $options) use ($default, $sync): Promise_Interface {
            return empty($options[Request_Options::SYNCHRONOUS]) ? $default($request, $options) : $sync($request, $options);
        };
    }
    /**
     * Sends streaming requests to a streaming compatible handler while sending
     * all other requests to a default handler.
     *
     * This, for example, could be useful for taking advantage of the
     * performance benefits of curl while still supporting true streaming
     * through the StreamHandler.
     *
     * @param callable(RequestInterface, array): PromiseInterface $default   Handler used for non-streaming responses
     * @param callable(RequestInterface, array): PromiseInterface $streaming Handler used for streaming responses
     *
     * @return callable(RequestInterface, array): PromiseInterface Returns the composed handler.
     */
    public static function wrap_streaming(callable $default, callable $streaming): callable
    {
        return static function (Request_Interface $request, array $options) use ($default, $streaming): Promise_Interface {
            return empty($options['stream']) ? $default($request, $options) : $streaming($request, $options);
        };
    }
}
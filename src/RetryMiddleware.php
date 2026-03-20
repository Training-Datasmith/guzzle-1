<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Promise_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Middleware that retries requests based on the boolean result of
 * invoking the provided "decider" function.
 *
 * @final
 */
class Retry_Middleware
{
    /**
     * @var callable(RequestInterface, array): PromiseInterface
     */
    private $next_handler;
    /**
     * @var callable
     */
    private $decider;
    /**
     * @var callable(int)
     */
    private $delay;
    /**
     * @param callable                                            $decider     Function that accepts the number of retries,
     *                                                                         a request, [response], and [exception] and
     *                                                                         returns true if the request is to be
     *                                                                         retried.
     * @param callable(RequestInterface, array): PromiseInterface $nextHandler Next handler to invoke.
     * @param (callable(int): int)|null                           $delay       Function that accepts the number of retries
     *                                                                         and returns the number of
     *                                                                         milliseconds to delay.
     */
    public function __construct(callable $decider, callable $next_handler, ?callable $delay = null)
    {
        $this->decider = $decider;
        $this->next_handler = $next_handler;
        $this->delay = $delay ?: self::class . '::exponentialDelay';
    }
    /**
     * Default exponential backoff delay function.
     *
     * @return int milliseconds.
     */
    public static function exponential_delay(int $retries): int
    {
        return (int) 2 ** ($retries - 1) * 1000;
    }
    public function __invoke(Request_Interface $request, array $options): Promise_Interface
    {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }
        $fn = $this->next_handler;
        return $fn($request, $options)->then($this->on_fulfilled($request, $options), $this->on_rejected($request, $options));
    }
    /**
     * Execute fulfilled closure
     */
    private function on_fulfilled(Request_Interface $request, array $options): callable
    {
        return function ($value) use ($request, $options) {
            if (!($this->decider)($options['retries'], $request, $value, null)) {
                return $value;
            }
            return $this->do_retry($request, $options, $value);
        };
    }
    /**
     * Execute rejected closure
     */
    private function on_rejected(Request_Interface $req, array $options): callable
    {
        return function ($reason) use ($req, $options) {
            if (!($this->decider)($options['retries'], $req, null, $reason)) {
                return P\Create::rejection_for($reason);
            }
            return $this->do_retry($req, $options);
        };
    }
    private function do_retry(Request_Interface $request, array $options, ?Response_Interface $response = null): Promise_Interface
    {
        $options['delay'] = ($this->delay)(++$options['retries'], $response, $request);
        return $this($request, $options);
    }
}
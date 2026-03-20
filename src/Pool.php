<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Each_Promise;
use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Promise\Promisor_Interface;
use Psr\Http\Message\Request_Interface;
/**
 * Sends an iterator of requests concurrently using a capped pool size.
 *
 * The pool will read from an iterator until it is cancelled or until the
 * iterator is consumed. When a request is yielded, the request is sent after
 * applying the "request_options" request options (if provided in the ctor).
 *
 * When a function is yielded by the iterator, the function is provided the
 * "request_options" array that should be merged on top of any existing
 * options, and the function MUST then return a wait-able promise.
 *
 * @final
 */
class Pool implements Promisor_Interface
{
    /**
     * @var EachPromise
     */
    private $each;
    /**
     * @param ClientInterface $client   Client used to send the requests.
     * @param array|\Iterator $requests Requests or functions that return
     *                                  requests to send concurrently.
     * @param array           $config   Associative array of options
     *                                  - concurrency: (int) Maximum number of requests to send concurrently
     *                                  - options: Array of request options to apply to each request.
     *                                  - fulfilled: (callable) Function to invoke when a request completes.
     *                                  - rejected: (callable) Function to invoke when a request is rejected.
     */
    public function __construct(Client_Interface $client, $requests, array $config = [])
    {
        if (!isset($config['concurrency'])) {
            $config['concurrency'] = 25;
        }
        if (isset($config['options'])) {
            $opts = $config['options'];
            unset($config['options']);
        } else {
            $opts = [];
        }
        $iterable = P\Create::iter_for($requests);
        $requests = static function () use ($iterable, $client, $opts) {
            foreach ($iterable as $key => $rfn) {
                if ($rfn instanceof Request_Interface) {
                    yield $key => $client->send_async($rfn, $opts);
                } elseif (\is_callable($rfn)) {
                    yield $key => $rfn($opts);
                } else {
                    throw new \InvalidArgumentException('Each value yielded by the iterator must be a Psr7\Http\Message\RequestInterface or a callable that returns a promise that fulfills with a Psr7\Message\Http\ResponseInterface object.');
                }
            }
        };
        $this->each = new Each_Promise($requests(), $config);
    }
    /**
     * Get promise
     */
    public function promise(): Promise_Interface
    {
        return $this->each->promise();
    }
    /**
     * Sends multiple requests concurrently and returns an array of responses
     * and exceptions that uses the same ordering as the provided requests.
     *
     * IMPORTANT: This method keeps every request and response in memory, and
     * as such, is NOT recommended when sending a large number or an
     * indeterminate number of requests concurrently.
     *
     * @param ClientInterface $client   Client used to send the requests
     * @param array|\Iterator $requests Requests to send concurrently.
     * @param array           $options  Passes through the options available in
     *                                  {@see Pool::__construct}
     *
     * @return array Returns an array containing the response or an exception
     *               in the same order that the requests were sent.
     *
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(Client_Interface $client, $requests, array $options = []): array
    {
        $res = [];
        self::cmp_callback($options, 'fulfilled', $res);
        self::cmp_callback($options, 'rejected', $res);
        $pool = new static($client, $requests, $options);
        $pool->promise()->wait();
        \ksort($res);
        return $res;
    }
    /**
     * Execute callback(s)
     */
    private static function cmp_callback(array &$options, string $name, array &$results): void
    {
        if (!isset($options[$name])) {
            $options[$name] = static function ($v, $k) use (&$results): void {
                $results[$k] = $v;
            };
        } else {
            $current_fn = $options[$name];
            $options[$name] = static function ($v, $k) use (&$results, $current_fn): void {
                $current_fn($v, $k);
                $results[$k] = $v;
            };
        }
    }
}
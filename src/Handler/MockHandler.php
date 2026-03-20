<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Guzzle_Http\Exception\Request_Exception;
use Guzzle_Http\Handler_Stack;
use Guzzle_Http\Promise as P;
use Guzzle_Http\Promise\Promise_Interface;
use Guzzle_Http\Transfer_Stats;
use Guzzle_Http\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Handler that returns responses or throw exceptions from a queue.
 *
 * @final
 */
class Mock_Handler implements \Countable
{
    /**
     * @var array
     */
    private $queue = [];
    /**
     * @var RequestInterface|null
     */
    private $last_request;
    /**
     * @var array
     */
    private $last_options = [];
    /**
     * @var callable|null
     */
    private $on_fulfilled;
    /**
     * @var callable|null
     */
    private $on_rejected;
    /**
     * Creates a new MockHandler that uses the default handler stack list of
     * middlewares.
     *
     * @param array|null    $queue       Array of responses, callables, or exceptions.
     * @param callable|null $onFulfilled Callback to invoke when the return value is fulfilled.
     * @param callable|null $onRejected  Callback to invoke when the return value is rejected.
     */
    public static function create_with_middleware(?array $queue = null, ?callable $on_fulfilled = null, ?callable $on_rejected = null): Handler_Stack
    {
        return Handler_Stack::create(new self($queue, $on_fulfilled, $on_rejected));
    }
    /**
     * The passed in value must be an array of
     * {@see ResponseInterface} objects, Exceptions,
     * callables, or Promises.
     *
     * @param array<int, mixed>|null $queue       The parameters to be passed to the append function, as an indexed array.
     * @param callable|null          $onFulfilled Callback to invoke when the return value is fulfilled.
     * @param callable|null          $onRejected  Callback to invoke when the return value is rejected.
     */
    public function __construct(?array $queue = null, ?callable $on_fulfilled = null, ?callable $on_rejected = null)
    {
        $this->on_fulfilled = $on_fulfilled;
        $this->on_rejected = $on_rejected;
        if ($queue) {
            // array_values included for BC
            $this->append(...array_values($queue));
        }
    }
    public function __invoke(Request_Interface $request, array $options): Promise_Interface
    {
        if (!$this->queue) {
            throw new \OutOfBoundsException('Mock queue is empty');
        }
        if (isset($options['delay']) && \is_numeric($options['delay'])) {
            \usleep((int) $options['delay'] * 1000);
        }
        $this->last_request = $request;
        $this->last_options = $options;
        $response = \array_shift($this->queue);
        if (isset($options['on_headers'])) {
            if (!\is_callable($options['on_headers'])) {
                throw new \InvalidArgumentException('on_headers must be callable');
            }
            try {
                $options['on_headers']($response);
            } catch (\Exception $e) {
                $msg = 'An error was encountered during the on_headers event';
                $response = new Request_Exception($msg, $request, $response, $e);
            }
        }
        if (\is_callable($response)) {
            $response = $response($request, $options);
        }
        $response = $response instanceof \Throwable ? P\Create::rejection_for($response) : P\Create::promise_for($response);
        return $response->then(function (?Response_Interface $value) use ($request, $options): ?\Psr\Http\Message\Response_Interface {
            $this->invoke_stats($request, $options, $value);
            if ($this->on_fulfilled) {
                ($this->on_fulfilled)($value);
            }
            if ($value !== null && isset($options['sink'])) {
                $contents = (string) $value->get_body();
                $sink = $options['sink'];
                if (\is_resource($sink)) {
                    \fwrite($sink, $contents);
                } elseif (\is_string($sink)) {
                    \file_put_contents($sink, $contents);
                } elseif ($sink instanceof Stream_Interface) {
                    $sink->write($contents);
                }
            }
            return $value;
        }, function ($reason) use ($request, $options) {
            $this->invoke_stats($request, $options, null, $reason);
            if ($this->on_rejected) {
                ($this->on_rejected)($reason);
            }
            return P\Create::rejection_for($reason);
        });
    }
    /**
     * Adds one or more variadic requests, exceptions, callables, or promises
     * to the queue.
     *
     * @param mixed ...$values
     */
    public function append(...$values): void
    {
        foreach ($values as $value) {
            if ($value instanceof Response_Interface || $value instanceof \Throwable || $value instanceof Promise_Interface || \is_callable($value)) {
                $this->queue[] = $value;
            } else {
                throw new \TypeError('Expected a Response, Promise, Throwable or callable. Found ' . Utils::describe_type($value));
            }
        }
    }
    /**
     * Get the last received request.
     */
    public function get_last_request(): ?Request_Interface
    {
        return $this->last_request;
    }
    /**
     * Get the last received request options.
     */
    public function get_last_options(): array
    {
        return $this->last_options;
    }
    /**
     * Returns the number of remaining items in the queue.
     */
    public function count(): int
    {
        return \count($this->queue);
    }
    public function reset(): void
    {
        $this->queue = [];
    }
    /**
     * @param mixed $reason Promise or reason.
     */
    private function invoke_stats(Request_Interface $request, array $options, ?Response_Interface $response = null, $reason = null): void
    {
        if (isset($options['on_stats'])) {
            $transfer_time = $options['transfer_time'] ?? 0;
            $stats = new Transfer_Stats($request, $response, $transfer_time, $reason);
            $options['on_stats']($stats);
        }
    }
}
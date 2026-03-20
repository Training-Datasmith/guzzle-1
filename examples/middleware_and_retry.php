<?php

declare(strict_types=1);

/**
 * Guzzle HTTP Client — Middleware and Retry Examples
 *
 * Shows how to compose a handler stack with logging middleware and retry logic.
 * Run: php examples/middleware_and_retry.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Guzzle_Http\Client;
use Guzzle_Http\Handler_Stack;
use Guzzle_Http\Middleware;
use Guzzle_Http\Message_Formatter;
use Guzzle_Http\Exception\Request_Exception;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;

// ── 1. Logging middleware ────────────────────────────────────────────────────

$stack = Handler_Stack::create();

// Log every request/response to stdout
$formatter = new Message_Formatter('{method} {uri} → {code}');
$stack->push(Middleware::log(
    new class implements \Psr\Log\Logger_Interface {
        use \Psr\Log\Logger_Trait;
        public function log($level, $message, array $context = []): void
        {
            echo '[LOG] ' . $message . "\n";
        }
    },
    $formatter
));

$client = new Client(['handler' => $stack, 'base_uri' => 'https://httpbin.org/']);

$client->get('status/200');

// ── 2. Retry middleware ──────────────────────────────────────────────────────

$retryStack = Handler_Stack::create();

/**
 * Decide whether to retry: retry on 5xx or connection errors, max 3 times.
 */
$decider = function (
    int $retries,
    Request_Interface $request,
    ?Response_Interface $response,
    ?\Throwable $exception
) use (&$retryCount): bool {
    if ($retries >= 3) {
        return false;
    }
    if ($exception instanceof Request_Exception) {
        return true;
    }
    if ($response && $response->getStatusCode() >= 500) {
        echo "Retrying (attempt {$retries})…\n";
        return true;
    }
    return false;
};

$retryStack->push(Middleware::retry($decider));

$retryClient = new Client(['handler' => $retryStack, 'base_uri' => 'https://httpbin.org/']);

try {
    // This endpoint returns 500 — the retry middleware will attempt it 3 more times
    $retryClient->get('status/500');
} catch (Request_Exception $e) {
    echo 'All retries exhausted: ' . $e->getResponse()->getStatusCode() . "\n";
}

// ── 3. History middleware (inspect sent requests) ────────────────────────────

$container = [];
$historyStack = Handler_Stack::create();
$historyStack->push(Middleware::history($container));

$historyClient = new Client(['handler' => $historyStack, 'base_uri' => 'https://httpbin.org/']);
$historyClient->get('get');
$historyClient->get('ip');

foreach ($container as $transaction) {
    echo 'Sent: ' . $transaction['request']->getMethod()
        . ' ' . $transaction['request']->getUri() . "\n";
}

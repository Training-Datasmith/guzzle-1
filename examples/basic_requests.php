<?php

declare(strict_types=1);

/**
 * Guzzle HTTP Client — Basic Usage Examples
 *
 * Demonstrates GET, POST with JSON, and error handling.
 * Run: php examples/basic_requests.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Guzzle_Http\Client;
use Guzzle_Http\Exception\Client_Exception;
use Guzzle_Http\Exception\Server_Exception;
use Guzzle_Http\Exception\Connect_Exception;

// ── 1. Simple GET request ────────────────────────────────────────────────────

$client = new Client([
    'base_uri' => 'https://httpbin.org/',
    'timeout'  => 5.0,
]);

try {
    $response = $client->get('get', [
        'query' => ['foo' => 'bar', 'page' => 1],
    ]);

    echo 'Status: ' . $response->getStatusCode() . "\n";

    $body = json_decode((string) $response->getBody(), true);
    echo 'URL called: ' . $body['url'] . "\n";
} catch (Connect_Exception $e) {
    echo 'Connection failed: ' . $e->getMessage() . "\n";
}

// ── 2. POST with JSON body ───────────────────────────────────────────────────

try {
    $response = $client->post('post', [
        'json' => [
            'username' => 'alice',
            'action'   => 'login',
        ],
    ]);

    $body = json_decode((string) $response->getBody(), true);
    echo 'Posted JSON: ' . json_encode($body['json']) . "\n";
} catch (Client_Exception $e) {
    // 4xx response — safe to inspect the response body
    echo '4xx error: ' . $e->getMessage() . "\n";
} catch (Server_Exception $e) {
    // 5xx response
    echo '5xx error: ' . $e->getMessage() . "\n";
}

// ── 3. POST form-encoded with Basic Auth ─────────────────────────────────────

try {
    $response = $client->post('post', [
        'form_params' => [
            'field1' => 'value1',
            'field2' => 'value2',
        ],
        'auth' => ['user', 'secret'],
    ]);

    echo 'Form POST status: ' . $response->getStatusCode() . "\n";
} catch (Client_Exception | Server_Exception $e) {
    echo 'Request failed: ' . $e->getMessage() . "\n";
}

// ── 4. Async concurrent requests ─────────────────────────────────────────────

use Guzzle_Http\Promise;

$promises = [
    'get1' => $client->getAsync('get'),
    'get2' => $client->getAsync('ip'),
];

$results = Promise\Utils::settle($promises)->wait();

foreach ($results as $key => $result) {
    if ($result['state'] === 'fulfilled') {
        echo $key . ' → ' . $result['value']->getStatusCode() . "\n";
    } else {
        echo $key . ' → failed: ' . $result['reason']->getMessage() . "\n";
    }
}

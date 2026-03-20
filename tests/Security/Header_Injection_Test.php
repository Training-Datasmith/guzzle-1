<?php

declare(strict_types=1);

namespace Guzzle_Http\Tests\Security;

use Guzzle_Http\Client;
use Guzzle_Http\Exception\Invalid_Argument_Exception;
use Guzzle_Http\Handler\Mock_Handler;
use Guzzle_Http\Handler_Stack;
use Guzzle_Http\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Security tests verifying that header injection and array-body attacks
 * are rejected by the Client before reaching the transport layer.
 *
 * These tests document the fixes applied to prevent:
 * - Indexed (non-associative) header arrays that could overwrite legitimate headers
 * - Array bodies passed as the 'body' option (should use 'form_params')
 */
class Header_Injection_Test extends TestCase
{
    private function make_client(): Client
    {
        $mock  = new Mock_Handler([new Response(200)]);
        $stack = Handler_Stack::create($mock);
        return new Client(['handler' => $stack]);
    }

    /**
     * An indexed (non-associative) headers array must be rejected.
     *
     * Accepting indexed arrays would allow a crafted value to set arbitrary
     * header names, enabling header injection attacks.
     */
    public function test_indexed_headers_array_is_rejected(): void
    {
        $client = $this->make_client();

        $this->expectException(Invalid_Argument_Exception::class);
        $this->expectExceptionMessageMatches('/header name as keys/i');

        $client->get('http://example.com', [
            'headers' => [
                // Indexed array — no header names supplied
                'Content-Type: application/json',
                'X-Injected: evil',
            ],
        ]);
    }

    /**
     * An array passed as the 'body' option must be rejected.
     *
     * PHP's http_build_query() would silently ignore nested arrays in some
     * encodings. Users must explicitly choose 'form_params' or 'multipart'.
     */
    public function test_array_body_option_is_rejected(): void
    {
        $client = $this->make_client();

        $this->expectException(Invalid_Argument_Exception::class);
        $this->expectExceptionMessageMatches('/form_params/i');

        $client->post('http://example.com', [
            'body' => ['key' => 'value'],
        ]);
    }

    /**
     * A boolean value for the 'sink' option must be rejected.
     *
     * Passing a boolean instead of a valid sink (file path, stream, or
     * StreamInterface) would cause confusing silent failures at the handler
     * level.
     */
    public function test_boolean_sink_is_rejected(): void
    {
        $client = $this->make_client();

        $this->expectException(Invalid_Argument_Exception::class);
        $this->expectExceptionMessageMatches('/sink must not be a boolean/i');

        $client->get('http://example.com', [
            'sink' => true,
        ]);
    }

    /**
     * A non-callable 'handler' option must be rejected at construction time.
     */
    public function test_non_callable_handler_is_rejected_at_construction(): void
    {
        $this->expectException(Invalid_Argument_Exception::class);
        $this->expectExceptionMessageMatches('/handler must be a callable/i');

        new Client(['handler' => 'not_a_callable']);
    }

    /**
     * Combining 'form_params' and 'multipart' in the same request must be rejected.
     *
     * Both options affect the Content-Type header; mixing them is ambiguous.
     */
    public function test_form_params_and_multipart_cannot_be_combined(): void
    {
        $client = $this->make_client();

        $this->expectException(Invalid_Argument_Exception::class);
        $this->expectExceptionMessageMatches('/form_params and multipart/i');

        $client->post('http://example.com', [
            'form_params' => ['a' => '1'],
            'multipart'   => [['name' => 'b', 'contents' => '2']],
        ]);
    }

    /**
     * Associative headers array must be accepted and forwarded to the handler.
     */
    public function test_associative_headers_array_is_accepted(): void
    {
        $mock  = new Mock_Handler([new Response(200)]);
        $stack = Handler_Stack::create($mock);
        $container = [];
        $stack->push(\Guzzle_Http\Middleware::history($container));
        $client = new Client(['handler' => $stack]);

        $client->get('http://example.com', [
            'headers' => [
                'X-Custom' => 'safe-value',
                'Accept'   => 'application/json',
            ],
        ]);

        $sentRequest = $container[0]['request'];
        $this->assertSame('safe-value', $sentRequest->get_header_line('X-Custom'));
        $this->assertSame('application/json', $sentRequest->get_header_line('Accept'));
    }
}

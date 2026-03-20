<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Promise\Promise_Interface;
use Psr\Http\Message\Request_Interface;
/**
 * Prepares requests that contain a body, adding the Content-Length,
 * Content-Type, and Expect headers.
 *
 * @final
 */
class Prepare_Body_Middleware
{
    /**
     * @var callable(RequestInterface, array): PromiseInterface
     */
    private $next_handler;
    /**
     * @param callable(RequestInterface, array): PromiseInterface $nextHandler Next handler to invoke.
     */
    public function __construct(callable $next_handler)
    {
        $this->next_handler = $next_handler;
    }
    public function __invoke(Request_Interface $request, array $options): Promise_Interface
    {
        $fn = $this->next_handler;
        // Don't do anything if the request has no body.
        if ($request->get_body()->get_size() === 0) {
            return $fn($request, $options);
        }
        $modify = [];
        // Add a default content-type if possible.
        if (!$request->has_header('Content-Type')) {
            if ($uri = $request->get_body()->get_metadata('uri')) {
                if (is_string($uri) && $type = Psr7\Mime_Type::from_filename($uri)) {
                    $modify['set_headers']['Content-Type'] = $type;
                }
            }
        }
        // Add a default content-length or transfer-encoding header.
        if (!$request->has_header('Content-Length') && !$request->has_header('Transfer-Encoding')) {
            $size = $request->get_body()->get_size();
            if ($size !== null) {
                $modify['set_headers']['Content-Length'] = $size;
            } else {
                $modify['set_headers']['Transfer-Encoding'] = 'chunked';
            }
        }
        // Add the expect header if needed.
        $this->add_expect_header($request, $options, $modify);
        return $fn(Psr7\Utils::modify_request($request, $modify), $options);
    }
    /**
     * Add expect header
     */
    private function add_expect_header(Request_Interface $request, array $options, array &$modify): void
    {
        // Determine if the Expect header should be used
        if ($request->has_header('Expect')) {
            return;
        }
        $expect = $options['expect'] ?? null;
        // Return if disabled or using HTTP/1.0
        if ($expect === false || $request->get_protocol_version() === '1.0') {
            return;
        }
        // The expect header is unconditionally enabled
        if ($expect === true) {
            $modify['set_headers']['Expect'] = '100-Continue';
            return;
        }
        // By default, send the expect header when the payload is > 1mb
        if ($expect === null) {
            $expect = 1048576;
        }
        // Always add if the body cannot be rewound, the size cannot be
        // determined, or the size is greater than the cutoff threshold
        $body = $request->get_body();
        $size = $body->get_size();
        if ($size === null || $size >= (int) $expect || !$body->is_seekable()) {
            $modify['set_headers']['Expect'] = '100-Continue';
        }
    }
}
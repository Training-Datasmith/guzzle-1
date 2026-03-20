<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Guzzle_Http\Psr7\Response;
use Guzzle_Http\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Represents a cURL easy handle and the data it populates.
 *
 * @internal
 */
final class Easy_Handle
{
    /**
     * @var resource|\CurlHandle cURL resource
     */
    public $handle;
    /**
     * @var StreamInterface Where data is being written
     */
    public $sink;
    /**
     * @var array Received HTTP headers so far
     */
    public $headers = [];
    /**
     * @var ResponseInterface|null Received response (if any)
     */
    public $response;
    /**
     * @var RequestInterface Request being sent
     */
    public $request;
    /**
     * @var array Request options
     */
    public $options = [];
    /**
     * @var int cURL error number (if any)
     */
    public $errno = 0;
    /**
     * @var \Throwable|null Exception during on_headers (if any)
     */
    public $on_headers_exception;
    /**
     * @var \Exception|null Exception during createResponse (if any)
     */
    public $create_response_exception;
    /**
     * Attach a response to the easy handle based on the received headers.
     *
     * @throws \RuntimeException if no headers have been received or the first
     *                           header line is invalid.
     */
    public function create_response(): void
    {
        [$ver, $status, $reason, $headers] = Header_Processor::parse_headers($this->headers);
        $normalized_keys = Utils::normalize_header_keys($headers);
        if (!empty($this->options['decode_content']) && isset($normalized_keys['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$normalized_keys['content-encoding']];
            unset($headers[$normalized_keys['content-encoding']]);
            if (isset($normalized_keys['content-length'])) {
                $headers['x-encoded-content-length'] = $headers[$normalized_keys['content-length']];
                $body_length = (int) $this->sink->get_size();
                if ($body_length) {
                    $headers[$normalized_keys['content-length']] = $body_length;
                } else {
                    unset($headers[$normalized_keys['content-length']]);
                }
            }
        }
        // Attach a response to the easy handle with the parsed headers.
        $this->response = new Response($status, $headers, $this->sink, $ver, $reason);
    }
    /**
     *
     * @return void
     * @throws \BadMethodCallException
     */
    public function __get(string $name)
    {
        $msg = $name === 'handle' ? 'The EasyHandle has been released' : 'Invalid property: ' . $name;
        throw new \BadMethodCallException($msg);
    }
}
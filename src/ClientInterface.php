<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Guzzle_Http\Exception\Guzzle_Exception;
use Guzzle_Http\Promise\Promise_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Uri_Interface;
/**
 * Client interface for sending HTTP requests.
 */
interface Client_Interface
{
    /**
     * The Guzzle major version.
     */
    public const MAJOR_VERSION = 7;
    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request options to apply to the given
     *                                  request and to the transfer.
     *
     * @throws GuzzleException
     */
    public function send(Request_Interface $request, array $options = []): Response_Interface;
    /**
     * Asynchronously send an HTTP request.
     *
     * @param RequestInterface $request Request to send
     * @param array            $options Request options to apply to the given
     *                                  request and to the transfer.
     */
    public function send_async(Request_Interface $request, array $options = []): Promise_Interface;
    /**
     * Create and send an HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well.
     *
     * @param string              $method  HTTP method.
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply.
     *
     * @throws GuzzleException
     */
    public function request(string $method, $uri, array $options = []): Response_Interface;
    /**
     * Create and send an asynchronous HTTP request.
     *
     * Use an absolute path to override the base path of the client, or a
     * relative path to append to the base path of the client. The URL can
     * contain the query string as well. Use an array to provide a URL
     * template and additional variables to use in the URL template expansion.
     *
     * @param string              $method  HTTP method
     * @param string|UriInterface $uri     URI object or string.
     * @param array               $options Request options to apply.
     */
    public function request_async(string $method, $uri, array $options = []): Promise_Interface;
    /**
     * Get a client configuration option.
     *
     * These options include default request options of the client, a "handler"
     * (if utilized by the concrete client), and a "base_uri" if utilized by
     * the concrete client.
     *
     * @param string|null $option The config option to retrieve.
     *
     * @return mixed
     *
     * @deprecated ClientInterface::getConfig will be removed in guzzlehttp/guzzle:8.0.
     */
    public function get_config(?string $option = null);
}
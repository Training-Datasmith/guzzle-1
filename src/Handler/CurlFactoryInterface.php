<?php

declare (strict_types=1);
namespace Guzzle_Http\Handler;

use Psr\Http\Message\Request_Interface;
interface Curl_Factory_Interface
{
    /**
     * Creates a cURL handle resource.
     *
     * @param RequestInterface $request Request
     * @param array            $options Transfer options
     *
     * @throws \RuntimeException when an option cannot be applied
     */
    public function create(Request_Interface $request, array $options): Easy_Handle;
    /**
     * Release an easy handle, allowing it to be reused or closed.
     *
     * This function must call unset on the easy handle's "handle" property.
     */
    public function release(Easy_Handle $easy): void;
}
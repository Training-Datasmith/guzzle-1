<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
interface Message_Formatter_Interface
{
    /**
     * Returns a formatted message string.
     *
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @param \Throwable|null        $error    Exception that was received
     */
    public function format(Request_Interface $request, ?Response_Interface $response = null, ?\Throwable $error = null): string;
}
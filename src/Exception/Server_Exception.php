<?php

declare (strict_types=1);
namespace Guzzle_Http\Exception;

/**
 * Exception when a server error is encountered (5xx codes)
 */
class Server_Exception extends Bad_Response_Exception
{
}
<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Message_Interface;
interface Body_Summarizer_Interface
{
    /**
     * Returns a summarized message body.
     */
    public function summarize(Message_Interface $message): ?string;
}
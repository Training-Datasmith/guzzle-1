<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Message_Interface;
final class Body_Summarizer implements Body_Summarizer_Interface
{
    /**
     * @var int|null
     */
    private $truncate_at;
    public function __construct(?int $truncate_at = null)
    {
        $this->truncate_at = $truncate_at;
    }
    /**
     * Returns a summarized message body.
     */
    public function summarize(Message_Interface $message): ?string
    {
        return $this->truncate_at === null ? Psr7\Message::body_summary($message) : Psr7\Message::body_summary($message, $this->truncate_at);
    }
}
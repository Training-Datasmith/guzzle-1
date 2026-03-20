<?php

declare (strict_types=1);
namespace Guzzle_Http\Exception;

use Guzzle_Http\Body_Summarizer;
use Guzzle_Http\Body_Summarizer_Interface;
use Psr\Http\Client\Request_Exception_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * HTTP Request exception
 */
class Request_Exception extends Transfer_Exception implements Request_Exception_Interface
{
    /**
     * @var RequestInterface
     */
    private $request;
    /**
     * @var ResponseInterface|null
     */
    private $response;
    /**
     * @var array
     */
    private $handler_context;
    public function __construct(string $message, Request_Interface $request, ?Response_Interface $response = null, ?\Throwable $previous = null, array $handler_context = [])
    {
        // Set the code of the exception if the response is set and not future.
        $code = $response ? $response->get_status_code() : 0;
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->response = $response;
        $this->handler_context = $handler_context;
    }
    /**
     * Wrap non-RequestExceptions with a RequestException
     */
    public static function wrap_exception(Request_Interface $request, \Throwable $e): Request_Exception
    {
        return $e instanceof Request_Exception ? $e : new Request_Exception($e->get_message(), $request, null, $e);
    }
    /**
     * Factory method to create a new exception with a normalized error message
     *
     * @param RequestInterface             $request        Request sent
     * @param ResponseInterface            $response       Response received
     * @param \Throwable|null              $previous       Previous exception
     * @param array                        $handlerContext Optional handler context
     * @param BodySummarizerInterface|null $bodySummarizer Optional body summarizer
     */
    public static function create(Request_Interface $request, ?Response_Interface $response = null, ?\Throwable $previous = null, array $handler_context = [], ?Body_Summarizer_Interface $body_summarizer = null): self
    {
        if (!$response) {
            return new self('Error completing request', $request, null, $previous, $handler_context);
        }
        $level = (int) \floor($response->get_status_code() / 100);
        if ($level === 4) {
            $label = 'Client error';
            $class_name = Client_Exception::class;
        } elseif ($level === 5) {
            $label = 'Server error';
            $class_name = Server_Exception::class;
        } else {
            $label = 'Unsuccessful request';
            $class_name = self::class;
        }
        $uri = \Guzzle_Http\Psr7\Utils::redact_user_info($request->get_uri());
        // Client Error: `GET /` resulted in a `404 Not Found` response:
        // <html> ... (truncated)
        $message = \sprintf('%s: `%s %s` resulted in a `%s %s` response', $label, $request->get_method(), $uri->__toString(), $response->get_status_code(), $response->get_reason_phrase());
        $summary = ($body_summarizer ?? new Body_Summarizer())->summarize($response);
        if ($summary !== null) {
            $message .= ":\n{$summary}\n";
        }
        return new $class_name($message, $request, $response, $previous, $handler_context);
    }
    /**
     * Get the request that caused the exception
     */
    public function get_request(): Request_Interface
    {
        return $this->request;
    }
    /**
     * Get the associated response
     */
    public function get_response(): ?Response_Interface
    {
        return $this->response;
    }
    /**
     * Check if a response was received
     */
    public function has_response(): bool
    {
        return $this->response !== null;
    }
    /**
     * Get contextual information about the error from the underlying handler.
     *
     * The contents of this array will vary depending on which handler you are
     * using. It may also be just an empty array. Relying on this data will
     * couple you to a specific handler, but can give more debug information
     * when needed.
     */
    public function get_handler_context(): array
    {
        return $this->handler_context;
    }
}
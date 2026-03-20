<?php

declare (strict_types=1);
namespace Guzzle_Http;

use Psr\Http\Message\Message_Interface;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
/**
 * Formats log messages using variable substitutions for requests, responses,
 * and other transactional data.
 *
 * The following variable substitutions are supported:
 *
 * - {request}:        Full HTTP request message
 * - {response}:       Full HTTP response message
 * - {ts}:             ISO 8601 date in GMT
 * - {date_iso_8601}   ISO 8601 date in GMT
 * - {date_common_log} Apache common log date using the configured timezone.
 * - {host}:           Host of the request
 * - {method}:         Method of the request
 * - {uri}:            URI of the request
 * - {version}:        Protocol version
 * - {target}:         Request target of the request (path + query + fragment)
 * - {hostname}:       Hostname of the machine that sent the request
 * - {code}:           Status code of the response (if available)
 * - {phrase}:         Reason phrase of the response  (if available)
 * - {error}:          Any error messages (if available)
 * - {req_header_*}:   Replace `*` with the lowercased name of a request header to add to the message
 * - {res_header_*}:   Replace `*` with the lowercased name of a response header to add to the message
 * - {req_headers}:    Request headers
 * - {res_headers}:    Response headers
 * - {req_body}:       Request body
 * - {res_body}:       Response body
 *
 * @final
 */
class Message_Formatter implements Message_Formatter_Interface
{
    /**
     * Apache Common Log Format.
     *
     * @see https://httpd.apache.org/docs/2.4/logs.html#common
     *
     * @var string
     */
    public const CLF = '{hostname} {req_header_User-Agent} - [{date_common_log}] "{method} {target} HTTP/{version}" {code} {res_header_Content-Length}';
    public const DEBUG = ">>>>>>>>\n{request}\n<<<<<<<<\n{response}\n--------\n{error}";
    public const SHORT = '[{ts}] "{method} {target} HTTP/{version}" {code}';
    /**
     * @var string Template used to format log messages
     */
    private $template;
    /**
     * @param string $template Log message template
     */
    public function __construct(?string $template = self::CLF)
    {
        $this->template = $template ?: self::CLF;
    }
    /**
     * Returns a formatted message string.
     *
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface|null $response Response that was received
     * @param \Throwable|null        $error    Exception that was received
     */
    public function format(Request_Interface $request, ?Response_Interface $response = null, ?\Throwable $error = null): string
    {
        $cache = [];
        /** @var string */
        return \preg_replace_callback('/{\s*([A-Za-z_\-\.0-9]+)\s*}/', function (array $matches) use ($request, $response, $error, &$cache) {
            if (isset($cache[$matches[1]])) {
                return $cache[$matches[1]];
            }
            $result = '';
            switch ($matches[1]) {
                case 'request':
                    $result = Psr7\Message::to_string($request);
                    break;
                case 'response':
                    $result = $response ? Psr7\Message::to_string($response) : '';
                    break;
                case 'req_headers':
                    $result = \trim($request->get_method() . ' ' . $request->get_request_target()) . ' HTTP/' . $request->get_protocol_version() . "\r\n" . $this->headers($request);
                    break;
                case 'res_headers':
                    $result = $response ? \sprintf('HTTP/%s %d %s', $response->get_protocol_version(), $response->get_status_code(), $response->get_reason_phrase()) . "\r\n" . $this->headers($response) : 'NULL';
                    break;
                case 'req_body':
                    $result = $request->get_body()->__toString();
                    break;
                case 'res_body':
                    if (!$response instanceof Response_Interface) {
                        $result = 'NULL';
                        break;
                    }
                    $body = $response->get_body();
                    if (!$body->is_seekable()) {
                        $result = 'RESPONSE_NOT_LOGGEABLE';
                        break;
                    }
                    $result = $response->get_body()->__toString();
                    break;
                case 'ts':
                case 'date_iso_8601':
                    $result = \gmdate('c');
                    break;
                case 'date_common_log':
                    $result = \date('d/M/Y:H:i:s O');
                    break;
                case 'method':
                    $result = $request->get_method();
                    break;
                case 'version':
                case 'req_version':
                    $result = $request->get_protocol_version();
                    break;
                case 'uri':
                case 'url':
                    $result = $request->get_uri()->__toString();
                    break;
                case 'target':
                    $result = $request->get_request_target();
                    break;
                case 'res_version':
                    $result = $response ? $response->get_protocol_version() : 'NULL';
                    break;
                case 'host':
                    $result = $request->get_header_line('Host');
                    break;
                case 'hostname':
                    $result = \gethostname();
                    break;
                case 'code':
                    $result = $response ? $response->get_status_code() : 'NULL';
                    break;
                case 'phrase':
                    $result = $response ? $response->get_reason_phrase() : 'NULL';
                    break;
                case 'error':
                    $result = $error ? $error->get_message() : 'NULL';
                    break;
                default:
                    // handle prefixed dynamic headers
                    if (\strpos($matches[1], 'req_header_') === 0) {
                        $result = $request->get_header_line(\substr($matches[1], 11));
                    } elseif (\strpos($matches[1], 'res_header_') === 0) {
                        $result = $response ? $response->get_header_line(\substr($matches[1], 11)) : 'NULL';
                    }
            }
            $cache[$matches[1]] = $result;
            return $result;
        }, $this->template);
    }
    /**
     * Get headers from message as string
     */
    private function headers(Message_Interface $message): string
    {
        $result = '';
        foreach ($message->get_headers() as $name => $values) {
            $result .= $name . ': ' . \implode(', ', $values) . "\r\n";
        }
        return \trim($result);
    }
}
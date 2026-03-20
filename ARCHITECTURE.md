# Guzzle HTTP Client — Architecture

## Purpose

Guzzle is a PHP HTTP client library that makes it easy to send HTTP requests and trivial to integrate with web services. It provides a simple interface for building query strings, POST requests, streaming large uploads/downloads, HTTP cookies, JSON, and more.

## Directory Structure

```
src/
  Client.php                  — Main entry point; implements PSR-18 ClientInterface
  Client_Interface.php        — Public API contract
  Client_Trait.php            — Shared logic for HTTP method shortcuts (get/post/etc.)
  Handler_Stack.php           — Middleware pipeline builder
  Middleware.php              — Built-in middleware factories (auth, redirect, retry, etc.)
  Request_Options.php         — Documented constants for all supported request options
  Redirect_Middleware.php     — Handles 3xx redirect following
  Retry_Middleware.php        — Configurable retry logic
  Pool.php                    — Concurrent async request pool
  Transfer_Stats.php          — Value object for completed request statistics
  Message_Formatter.php       — Log/debug message formatter
  Utils.php                   — Utility functions (JSON, IDN, URI helpers)
  Cookie/                     — PSR-compatible cookie jar implementations
  Handler/                    — Low-level transport handlers (cURL, stream, mock)
  Exception/                  — Domain exception hierarchy
```

## Key Design Decisions

### Middleware Pipeline
All cross-cutting concerns (auth, cookies, redirects, retries, logging) are implemented as middleware via `Handler_Stack`. Each middleware wraps the next handler and returns a `PromiseInterface`. This allows arbitrary composition without subclassing.

### Promise-Based Async
`request_async()` / `send_async()` return `PromiseInterface`. Synchronous variants (`request()` / `send()`) simply call `->wait()` on the promise. This unified model means async and sync share all middleware logic.

### PSR Compliance
- Implements **PSR-18** (`Psr\Http\Client\ClientInterface`) for interoperability
- Accepts/returns **PSR-7** request/response objects
- Handlers accept PSR-7 `RequestInterface` and return a promise of PSR-7 `ResponseInterface`

### Configuration vs. Per-Request Options
`Client::__construct()` accepts default options merged with per-request options at call time in `prepare_defaults()`. `_conditional` headers are applied only when the request does not already supply them.

## Extension Points

- **Custom handlers**: pass `['handler' => $callable]` to the constructor
- **Middleware**: push/unshift onto a `Handler_Stack` using `push()` / `unshift()`
- **Mock handler** (`Handler\Mock_Handler`): replays pre-defined responses for testing
- **Cookie jars**: implement `Cookie\Cookie_Jar_Interface`

## Dependency Flow

```
Client
  └─ Handler_Stack (pipeline)
       ├─ Middleware (cookies, auth, redirect, retry, …)
       └─ Handler (Curl_Handler | Stream_Handler | Mock_Handler)
            └─ Returns PromiseInterface<ResponseInterface>
```

## Security Notes

- Header injection is prevented by validating header arrays are associative (line 292 of `Client.php`)
- SSRF mitigation: IDN conversion can be disabled via `idn_conversion => false`
- `HTTP_PROXY` is only read in CLI SAPI to avoid header injection attacks

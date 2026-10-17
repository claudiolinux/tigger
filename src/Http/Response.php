<?php

declare(strict_types=1);

namespace Tigger\Http;

use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * An outgoing HTTP response (the `$res` of your handlers).
 *
 * The response is buffered: nothing reaches the client until
 * {@see Response::flush()} runs, so middleware can still add headers
 * after `$next()` returns.
 */
final class Response
{
    private int $statusCode = 200;

    /** @var array<string, array{0: string, 1: string}> */
    private array $headers = [];

    /** @var list<string> */
    private array $cookies = [];

    private string $body = '';

    private bool $finished = false;

    private bool $flushed = false;

    /**
     * Sets the HTTP status code.
     *
     * @param int $code Status code between 100 and 599.
     *
     * @return static
     *
     * @throws InvalidArgumentException If the code is out of range.
     */
    public function status(int $code): static
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException("Invalid HTTP status code {$code}.");
        }

        $this->statusCode = $code;

        return $this;
    }

    /**
     * @return int Current status code.
     */
    public function getStatus(): int
    {
        return $this->statusCode;
    }

    /**
     * Sets a response header (replacing any previous value).
     *
     * @param string                $name  Header name.
     * @param string|int|Stringable $value Header value.
     *
     * @return static
     *
     * @throws InvalidArgumentException On an invalid name or a value containing line breaks (header injection).
     */
    public function header(string $name, string|int|Stringable $value): static
    {
        if (preg_match('/^[A-Za-z0-9\-]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid header name \"{$name}\".");
        }

        $value = (string) $value;
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("Invalid characters in value of header \"{$name}\".");
        }

        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    /**
     * Reads a header previously set on this response.
     *
     * @param string $name Header name (case-insensitive).
     *
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    /**
     * Removes a header.
     *
     * @param string $name Header name (case-insensitive).
     *
     * @return static
     */
    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    /**
     * Sends a JSON body.
     *
     * @param mixed    $data   Any JSON-encodable value.
     * @param int|null $status Optional status code.
     * @param int      $flags  Extra `json_encode` flags.
     *
     * @return static
     *
     * @throws \JsonException      If the data cannot be encoded.
     * @throws LogicException      If the response was already finished.
     */
    public function json(mixed $data, ?int $status = null, int $flags = 0): static
    {
        $this->assertWritable();

        $encoded = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags
        );

        return $this->finish($encoded, 'application/json; charset=utf-8', $status);
    }

    /**
     * Sends a plain-text body.
     *
     * @param string   $text   Body text.
     * @param int|null $status Optional status code.
     *
     * @return static
     *
     * @throws LogicException If the response was already finished.
     */
    public function text(string $text, ?int $status = null): static
    {
        $this->assertWritable();

        return $this->finish($text, 'text/plain; charset=utf-8', $status);
    }

    /**
     * Sends an HTML body.
     *
     * @param string   $html   HTML markup. Escape user input before passing it in.
     * @param int|null $status Optional status code.
     *
     * @return static
     *
     * @throws LogicException If the response was already finished.
     */
    public function html(string $html, ?int $status = null): static
    {
        $this->assertWritable();

        return $this->finish($html, 'text/html; charset=utf-8', $status);
    }

    /**
     * Sends a raw body, defaulting the content type to HTML when none is set.
     *
     * @param string $body Body content.
     *
     * @return static
     *
     * @throws LogicException If the response was already finished.
     */
    public function send(string $body = ''): static
    {
        $this->assertWritable();

        return $this->finish($body, $body !== '' ? 'text/html; charset=utf-8' : null, null);
    }

    /**
     * Redirects the client. Never pass unvalidated user input as the URL.
     *
     * @param string $url    Target URL.
     * @param int    $status 301, 302, 303, 307 or 308.
     *
     * @return static
     *
     * @throws InvalidArgumentException On an unsupported redirect status.
     * @throws LogicException           If the response was already finished.
     */
    public function redirect(string $url, int $status = 302): static
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("Invalid redirect status {$status}.");
        }

        $this->assertWritable();
        $this->header('Location', $url);

        return $this->finish('', null, $status);
    }

    /**
     * Ends the response with 204 No Content.
     *
     * @return static
     *
     * @throws LogicException If the response was already finished.
     */
    public function noContent(): static
    {
        $this->assertWritable();

        return $this->finish('', null, 204);
    }

    /**
     * Adds a Set-Cookie header. Defaults: Path=/, HttpOnly, SameSite=Lax.
     *
     * @param string               $name    Cookie name.
     * @param string               $value   Cookie value (URL-encoded automatically).
     * @param array<string,mixed>  $options `maxAge` (int), `expires` (int timestamp), `path`, `domain`,
     *                                      `secure` (bool), `httpOnly` (bool), `sameSite` (Strict|Lax|None).
     *
     * @return static
     *
     * @throws InvalidArgumentException On invalid names or options.
     */
    public function cookie(string $name, string $value, array $options = []): static
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid cookie name \"{$name}\".");
        }

        $o = array_merge([
            'maxAge' => null,
            'expires' => null,
            'path' => '/',
            'domain' => null,
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ], $options);

        $parts = [$name . '=' . rawurlencode($value)];

        if ($o['maxAge'] !== null) {
            $maxAge = (int) $o['maxAge'];
            $parts[] = 'Max-Age=' . $maxAge;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $maxAge > 0 ? time() + $maxAge : 0) . ' GMT';
        } elseif ($o['expires'] !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', (int) $o['expires']) . ' GMT';
        }

        foreach (['path' => 'Path', 'domain' => 'Domain'] as $key => $label) {
            if ($o[$key] !== null) {
                if (preg_match('/[;\r\n]/', (string) $o[$key]) === 1) {
                    throw new InvalidArgumentException("Invalid cookie {$key}.");
                }
                $parts[] = $label . '=' . $o[$key];
            }
        }

        $sameSite = ucfirst(strtolower((string) $o['sameSite']));
        if (!in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new InvalidArgumentException('SameSite must be Strict, Lax or None.');
        }

        $secure = (bool) $o['secure'] || $sameSite === 'None';

        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($o['httpOnly']) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . $sameSite;

        $this->cookies[] = implode('; ', $parts);

        return $this;
    }

    /**
     * Expires a cookie in the client.
     *
     * @param string              $name    Cookie name.
     * @param array<string,mixed> $options Same options used when it was set (path/domain must match).
     *
     * @return static
     */
    public function clearCookie(string $name, array $options = []): static
    {
        return $this->cookie($name, '', array_merge($options, ['maxAge' => 0]));
    }

    /**
     * @return bool True once a body-producing method has been called.
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @return string The buffered body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Discards the buffered body so the response can be rewritten (used for error pages).
     * Other headers (CORS, security headers...) are kept.
     *
     * @return static
     */
    public function resetBody(): static
    {
        $this->body = '';
        $this->finished = false;
        unset($this->headers['content-type']);

        return $this;
    }

    /**
     * Sends status, headers and body to the client. Called by the application.
     *
     * @param bool $omitBody Skip the body (HEAD requests).
     *
     * @return void
     */
    public function flush(bool $omitBody = false): void
    {
        if ($this->flushed) {
            return;
        }
        $this->flushed = true;

        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }

        $bodyless = $this->statusCode < 200 || $this->statusCode === 204 || $this->statusCode === 304;
        if (!$omitBody && !$bodyless) {
            echo $this->body;
        }
    }

    /**
     * @return void
     *
     * @throws LogicException If the response was already finished.
     */
    private function assertWritable(): void
    {
        if ($this->finished) {
            throw new LogicException('The response was already sent; call only one of json/send/text/html/redirect.');
        }
    }

    /**
     * Stores the body and marks the response as finished.
     *
     * @param string      $body        Body content.
     * @param string|null $contentType Content type to set, if any.
     * @param int|null    $status      Status code to set, if any.
     *
     * @return static
     */
    private function finish(string $body, ?string $contentType, ?int $status): static
    {
        if ($status !== null) {
            $this->status($status);
        }
        if ($contentType !== null) {
            $this->header('Content-Type', $contentType);
        }

        $this->body = $body;
        $this->finished = true;

        return $this;
    }
}

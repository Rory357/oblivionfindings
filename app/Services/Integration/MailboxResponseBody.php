<?php

namespace App\Services\Integration;

use App\Services\Integration\Exceptions\MailboxProviderFailure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** A memory-only receive sink: decoded bytes never spill into untracked temp files. */
final class MailboxResponseBody implements StreamInterface
{
    use StreamDecoratorTrait;

    public const DEFAULT_LIMIT = 8388608;

    public const MAX_LIMIT = 83886080;

    private StreamInterface $stream;

    private bool $overflow = false;

    public function __construct(private readonly int $limit)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Invalid mailbox response byte limit.');
        }
        $this->stream = Utils::streamFor(Utils::tryFopen('php://memory', 'w+'));
    }

    public function write($string): int
    {
        if ($this->overflow || strlen($string) > $this->limit - $this->stream->tell()) {
            $this->overflow = true;

            // cURL aborts on a short write; StreamHandler stops its unknown-
            // length copy. Both outcomes are rejected by our middleware.
            return 0;
        }

        return $this->stream->write($string);
    }

    private function checkHeaders(ResponseInterface $response): void
    {
        $length = $response->getHeaderLine('Content-Length');
        if ($length === '') {
            return;
        }
        if (! ctype_digit($length)) {
            throw new MailboxProviderFailure('invalid_response');
        }
        $length = ltrim($length, '0');
        $maximum = (string) $this->limit;
        if (strlen($length) > strlen($maximum) || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0)) {
            $this->overflow = true;
            throw new MailboxProviderFailure('response_too_large');
        }
    }

    /** Fresh sink per request, including when an adapter reuses its HTTP client. */
    public static function middleware(int $limit = self::DEFAULT_LIMIT): callable
    {
        return static fn (callable $handler): callable => static function ($request, array $options) use ($handler, $limit) {
            $sink = new self($limit);
            $onHeaders = $options['on_headers'] ?? null;
            $options['sink'] = $sink;
            $options['stream'] = false;
            $options['on_headers'] = static function (ResponseInterface $response) use ($sink, $onHeaders): void {
                $sink->checkHeaders($response);
                if ($onHeaders !== null) {
                    $onHeaders($response);
                }
            };
            $reject = static function (Throwable $failure) use ($sink): never {
                $sink->close();
                if ($sink->overflow) {
                    throw new MailboxProviderFailure('response_too_large');
                }
                throw $failure;
            };
            try {
                return $handler($request, $options)->then(static function (ResponseInterface $response) use ($sink): ResponseInterface {
                    try {
                        $sink->checkHeaders($response);
                        if ($sink->overflow) {
                            throw new MailboxProviderFailure('response_too_large');
                        }
                        $body = $response->getBody();
                        // Test/custom handlers may not honor sink. Apply the same
                        // cap before callers receive or JSON-decode their body.
                        if ($body !== $sink) {
                            // MockHandler writes the sink but returns its original
                            // stream; never append the same response a second time.
                            $copy = new self($sink->limit);
                            $sink->close();
                            $sink = $copy;
                            if ($body->isSeekable()) {
                                $body->rewind();
                            }
                            Utils::copyToStream($body, $sink);
                        }
                        if ($sink->overflow) {
                            throw new MailboxProviderFailure('response_too_large');
                        }
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ! $response->hasHeader('Content-Encoding') && ! $response->hasHeader('x-encoded-content-encoding')
                            && (int) $length !== $sink->getSize()) {
                            throw new MailboxProviderFailure('invalid_response');
                        }
                        $sink->rewind();

                        return $response->withBody($sink);
                    } catch (Throwable $failure) {
                        $sink->close();
                        throw $failure;
                    }
                }, $reject);
            } catch (Throwable $failure) {
                $reject($failure);
            }
        };
    }
}

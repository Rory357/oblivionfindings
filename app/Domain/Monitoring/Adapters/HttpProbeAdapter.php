<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\HttpTransport;
use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\EgressPolicy;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class HttpProbeAdapter implements ProbeAdapter
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly EgressPolicy $egressPolicy,
    ) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Http;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        if ($context->kind !== $this->kind() || ! in_array($context->target->scheme, ['http', 'https'], true)) {
            throw new LogicException('HTTP probe context does not match its adapter.');
        }

        $observedAt = CarbonImmutable::now();
        $expectedStatus = $context->config['expected_status'] ?? [200];
        $content = $context->config['content_contains'] ?? null;
        if (! is_array($expectedStatus) || ! array_is_list($expectedStatus) || $expectedStatus === []
            || count($expectedStatus) > 20
            || collect($expectedStatus)->contains(fn (mixed $status): bool => ! is_int($status) || $status < 100 || $status > 599)
            || ($content !== null && (! is_string($content) || $content === '' || strlen($content) > 1024))) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'ms', null, 'invalid_configuration', []);
        }

        $target = $context->target;
        $redirects = 0;
        while (true) {
            try {
                $response = $this->transport->request($target);
            } catch (Throwable) {
                return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'ms', null, 'probe_error', ['redirects' => $redirects]);
            }

            if ($response->truncated || strlen($response->body) > $target->maxResponseBytes) {
                return new ProtocolObservation(
                    MonitorState::Failed,
                    $observedAt,
                    $response->latencyMs,
                    'ms',
                    $response->latencyMs,
                    'response_too_large',
                    ['status' => $response->status, 'redirects' => $redirects, 'response_bytes' => min(strlen($response->body), $target->maxResponseBytes + 1)],
                );
            }

            if ($response->status >= 300 && $response->status < 400) {
                if ($response->location === null || $response->location === '') {
                    return $this->failed($observedAt, $response->latencyMs, 'redirect_missing_location', $response->status, $redirects, strlen($response->body));
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    return $this->failed($observedAt, $response->latencyMs, 'redirect_limit_exceeded', $response->status, $redirects, strlen($response->body));
                }

                try {
                    $target = $this->egressPolicy->reauthoriseRedirect($target, $response->location);
                } catch (EgressDenied) {
                    return $this->failed($observedAt, $response->latencyMs, 'redirect_denied', $response->status, $redirects, strlen($response->body));
                }
                $redirects++;

                continue;
            }

            $statusMatched = in_array($response->status, $expectedStatus, true);
            $contentMatched = $content === null || str_contains($response->body, $content);
            $state = $statusMatched && $contentMatched ? MonitorState::Healthy : MonitorState::Failed;
            $reason = ! $statusMatched
                ? 'status_mismatch'
                : ($contentMatched ? ($content === null ? 'status_match' : 'status_and_content_match') : 'content_mismatch');
            $evidence = [
                'status' => $response->status,
                'redirects' => $redirects,
                'response_bytes' => strlen($response->body),
            ];
            if ($content !== null) {
                $evidence['content_matched'] = $contentMatched;
            }

            return new ProtocolObservation(
                $state,
                $observedAt,
                $response->latencyMs,
                'ms',
                $response->latencyMs,
                $reason,
                $evidence,
            );
        }
    }

    private function failed(
        CarbonImmutable $observedAt,
        int $latencyMs,
        string $reason,
        int $status,
        int $redirects,
        int $responseBytes,
    ): ProtocolObservation {
        return new ProtocolObservation(
            MonitorState::Failed,
            $observedAt,
            $latencyMs,
            'ms',
            $latencyMs,
            $reason,
            [
                'status' => $status,
                'redirects' => $redirects,
                'response_bytes' => $responseBytes,
            ],
        );
    }
}

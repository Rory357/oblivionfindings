<?php

namespace App\Domain\Monitoring\Adapters;

use App\Domain\Monitoring\Contracts\DnsTransport;
use App\Domain\Monitoring\Contracts\ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

final class DnsProbeAdapter implements ProbeAdapter
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    public function __construct(private readonly DnsTransport $transport) {}

    public function kind(): MonitorKind
    {
        return MonitorKind::Dns;
    }

    public function probe(AuthorisedProbeContext $context): ProtocolObservation
    {
        if ($context->kind !== $this->kind() || $context->target->scheme !== 'dns') {
            throw new LogicException('DNS probe context does not match its adapter.');
        }

        $observedAt = CarbonImmutable::now();
        $name = $context->config['name'] ?? null;
        $type = strtoupper((string) ($context->config['type'] ?? 'A'));
        if (! is_string($name) || ! $this->validName($name) || ! in_array($type, self::TYPES, true)) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'answers', null, 'invalid_configuration', []);
        }

        try {
            $result = $this->transport->query($context->target, strtolower(rtrim($name, '.')), $type);
        } catch (Throwable) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'answers', null, 'probe_error', []);
        }

        if (count($result->answers) > 64) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'answers', $result->latencyMs, 'answer_limit_exceeded', []);
        }

        $answers = collect($result->answers)
            ->filter(fn (mixed $answer): bool => is_string($answer) && $answer !== '' && strlen($answer) <= 1024)
            ->map(fn (string $answer): string => strtolower(rtrim($answer, '.')))
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (! $result->answered || $answers === []) {
            $reason = in_array($result->reasonCode, ['nxdomain', 'timeout', 'no_answer', 'server_failure'], true)
                ? $result->reasonCode
                : 'probe_failed';

            return new ProtocolObservation(MonitorState::Failed, $observedAt, 0, 'answers', $result->latencyMs, $reason, ['answer_count' => 0]);
        }

        $expected = $context->config['expected_answers'] ?? [];
        if (! is_array($expected) || ! array_is_list($expected) || count($expected) > 64
            || collect($expected)->contains(
                fn (mixed $answer): bool => ! is_string($answer) || $answer === '' || strlen($answer) > 1024,
            )) {
            return new ProtocolObservation(MonitorState::Unknown, $observedAt, null, 'answers', $result->latencyMs, 'invalid_configuration', []);
        }
        $expected = collect($expected)
            ->map(fn (string $answer): string => strtolower(rtrim($answer, '.')))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $matched = $expected === [] || $expected === $answers;

        return new ProtocolObservation(
            $matched ? MonitorState::Healthy : MonitorState::Failed,
            $observedAt,
            count($answers),
            'answers',
            $result->latencyMs,
            $expected === [] ? 'answer_received' : ($matched ? 'answer_match' : 'answer_mismatch'),
            ['answer_count' => count($answers), 'matched' => $matched],
        );
    }

    private function validName(string $name): bool
    {
        $name = rtrim($name, '.');

        return $name !== ''
            && strlen($name) <= 253
            && preg_match('/^[a-z0-9_*-]+(?:\.[a-z0-9_-]+)*$/i', $name) === 1;
    }
}

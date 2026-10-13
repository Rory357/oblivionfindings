<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferenceInput;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CctvRecordingReferenceInputTest extends TestCase
{
    private function input(): array
    {
        return ['expected_actor_id' => 9, 'request_key' => '11111111-1111-4111-8111-111111111111', 'expected_source_revision' => 1, 'requested_start_at' => '2026-10-08T00:00:00Z', 'requested_end_at' => '2026-10-08T00:15:00Z', 'purpose' => 'Choose the reviewed camera reference window.'];
    }

    private function deny(callable $operation): void
    {
        try {
            $operation();
            self::fail('Invalid reference input accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_request', $exception->getMessage());
        }
    }

    public function test_window_in_progress_is_metadata_only_and_does_not_wait_for_end(): void
    {
        $input = $this->input();
        self::assertSame($input, CctvRecordingReferenceInput::create($input, CarbonImmutable::parse('2026-10-08T00:01:00Z'), 3600));
    }

    public function test_strict_instants_types_and_technical_window_bound_cannot_be_bypassed(): void
    {
        foreach ([['requested_start_at' => '2026-10-08T00:02:00Z'], ['requested_end_at' => '2026-10-08T00:00:00Z'], ['requested_end_at' => '2026-10-08T01:00:01Z'], ['requested_start_at' => '2026-02-30T00:00:00Z'], ['requested_start_at' => '2026-10-08T00:00:00+00:00'], ['requested_start_at' => '2026-10-08T00:00:00.000Z'], ['expected_actor_id' => '9'], ['expected_source_revision' => 0], ['purpose' => "A valid but hidden\u{202e} purpose"], ['preserved' => true]] as $change) {
            $this->deny(fn () => CctvRecordingReferenceInput::create(array_replace($this->input(), $change), CarbonImmutable::parse('2026-10-08T00:01:00Z'), 3600));
        }
    }

    public function test_seek_is_canonical_bounded_and_bound_to_actor_camera_site_context(): void
    {
        $scope = hash('sha256', '9:7:3');
        $cursor = new Cursor(['id' => 20, 'scope_sha256' => $scope], false);
        $decoded = CctvRecordingReferenceInput::cursor(['cursor' => $cursor->encode()], $scope);
        self::assertSame(20, $decoded->parameter('id'));
        self::assertFalse($decoded->pointsToNextItems());
        self::assertNull(CctvRecordingReferenceInput::cursor([], $scope));
        foreach ([['cursor' => $cursor->encode(), 'q' => 'copy'], ['cursor' => []], ['cursor' => str_repeat('x', 257)], ['cursor' => (new Cursor(['id' => 20, 'scope_sha256' => hash('sha256', '10:7:3')], true))->encode()], ['cursor' => (new Cursor(['id' => '20', 'scope_sha256' => $scope], true))->encode()], ['cursor' => (new Cursor(['id' => 20, 'scope_sha256' => $scope, 'external' => 'url'], true))->encode()]] as $input) {
            $this->deny(fn () => CctvRecordingReferenceInput::cursor($input, $scope));
        }
    }
}

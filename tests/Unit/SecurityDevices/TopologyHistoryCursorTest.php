<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Presenters\TopologyWorkspacePresenter;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TopologyHistoryCursorTest extends TestCase
{
    public function test_cursor_accepts_only_canonical_bounded_seek_state(): void
    {
        self::assertNull(TopologyWorkspacePresenter::decodeCursor(null));
        foreach (['2026-10-08 00:00:00', '2026-10-08 23:59:59.123456', '2024-02-29 12:30:59.1'] as $date) {
            foreach ([true, false] as $direction) {
                $cursor = new Cursor(['captured_at' => $date, 'id' => PHP_INT_MAX], $direction);
                $decoded = TopologyWorkspacePresenter::decodeCursor($cursor->encode());
                self::assertSame($date, $decoded->parameter('captured_at'));
                self::assertSame(PHP_INT_MAX, $decoded->parameter('id'));
                self::assertSame($direction, $decoded->pointsToNextItems());
            }
        }
    }

    public function test_malformed_state_is_rejected_with_a_generic_message(): void
    {
        $valid = ['captured_at' => '2026-10-08 00:00:00', 'id' => 1, '_pointsToNextItems' => true];
        $encode = fn (mixed $value): string => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $invalid = [
            '', [], 1, true, str_repeat('A', 513), 'not+a/cursor=', 'a', $encode('scalar'), $encode([]),
            $encode(['captured_at' => '2026-10-08 00:00:00', 'id' => 1]),
            $encode([...$valid, 'site_id' => 7]),
            $encode([...$valid, 'id' => '1']), $encode([...$valid, 'id' => 0]), $encode([...$valid, 'id' => -1]),
            $encode([...$valid, 'id' => 1.5]), $encode([...$valid, 'id' => '9223372036854775808']),
            $encode([...$valid, '_pointsToNextItems' => 1]), $encode([...$valid, '_pointsToNextItems' => 'false']),
            $encode([...$valid, 'captured_at' => '2026-02-30 12:00:00']),
            $encode([...$valid, 'captured_at' => '2026-00-01 12:00:00']),
            $encode([...$valid, 'captured_at' => '2026-01-01 24:00:00']),
            $encode([...$valid, 'captured_at' => '2026-01-01 12:60:00']),
            $encode([...$valid, 'captured_at' => '2026-01-01 12:00:60']),
            $encode([...$valid, 'captured_at' => '2026-10-08T00:00:00Z']),
            $encode([...$valid, 'captured_at' => '2026-10-08 00:00:00.1234567']),
            $encode([...$valid, 'captured_at' => ['2026-10-08 00:00:00']]),
            $encode(['id' => 1, 'captured_at' => '2026-10-08 00:00:00', '_pointsToNextItems' => true]),
            (new Cursor(['captured_at' => '2026-10-08 00:00:00', 'id' => 1]))->encode().'=',
        ];
        foreach ($invalid as $value) {
            try {
                TopologyWorkspacePresenter::decodeCursor($value);
                self::fail('Malformed cursor was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Topology history cursor is invalid.', $exception->getMessage());
            }
        }
    }
}

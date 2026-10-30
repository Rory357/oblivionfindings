<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use JsonException;

final class ConfigurationSnapshotComparisonService
{
    private const int MAX_DIFF_LINES = 400;

    private const int MAX_FIELD_CHANGES = 200;

    private const int CONTEXT_RADIUS = 3;

    public function __construct(
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly SecurityDevicesAccessService $access,
        private readonly NetworkConfigurationRedactor $redactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function compare(
        Device $device,
        ConfigurationSnapshot $target,
        ?ConfigurationSnapshot $baseline,
        User $actor,
    ): array {
        abort_unless($this->snapshots->canViewSnapshots($actor), 403);
        $this->access->assertCanViewDevice($actor, $device);

        $canonicalSiteId = $this->sites->resolve((int) $device->id);
        abort_unless(
            (int) $target->device_id === (int) $device->id
                && (int) $target->site_id === $canonicalSiteId,
            404,
        );

        if ($baseline !== null) {
            abort_unless(
                (int) $baseline->device_id === (int) $device->id
                    && (int) $baseline->site_id === $canonicalSiteId,
                404,
            );
        } else {
            $baseline = $this->resolveDefaultBaseline($device, $target, $canonicalSiteId);
        }

        $targetPayload = $this->redactor->redactPayload(
            $this->decodeJson($this->snapshots->retrieve($target, $actor)),
        );
        $baselinePayload = $baseline !== null
            ? $this->redactor->redactPayload($this->decodeJson($this->snapshots->retrieve($baseline, $actor)))
            : [];

        $fieldChanges = $this->computeFieldChanges($baselinePayload, $targetPayload);
        $targetLines = $this->renderableLines($targetPayload);
        $baselineLines = $baseline !== null ? $this->renderableLines($baselinePayload) : [];
        $lineDiff = $this->computeUnifiedDiff($baselineLines, $targetLines);

        $availableSnapshots = ConfigurationSnapshot::query()
            ->where('device_id', $device->id)
            ->where('site_id', $canonicalSiteId)
            ->where('storage_state', 'available')
            ->whereNull('payload_deleted_at')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (ConfigurationSnapshot $item): array => $this->presentSnapshotMeta($device, $item))
            ->values()
            ->all();

        return [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'uid' => $device->device_uid,
            ],
            'target' => $this->presentSnapshotMeta($device, $target),
            'baseline' => $baseline !== null ? $this->presentSnapshotMeta($device, $baseline) : null,
            'identical' => $baseline !== null
                && hash_equals((string) $baseline->configuration_hash, (string) $target->configuration_hash),
            'summary' => [
                'added_fields' => $fieldChanges['counts']['added'],
                'removed_fields' => $fieldChanges['counts']['removed'],
                'changed_fields' => $fieldChanges['counts']['changed'],
                'added_lines' => $lineDiff['added_count'],
                'removed_lines' => $lineDiff['removed_count'],
            ],
            'field_changes' => $fieldChanges['items'],
            'field_changes_truncated' => $fieldChanges['truncated'],
            'line_diff' => $lineDiff['lines'],
            'line_diff_truncated' => $lineDiff['truncated'],
            'line_source' => isset($targetPayload['running_config_lines']) || isset($baselinePayload['running_config_lines'])
                ? 'running_config'
                : 'structured_json',
            'available_snapshots' => $availableSnapshots,
        ];
    }

    private function resolveDefaultBaseline(
        Device $device,
        ConfigurationSnapshot $target,
        int $siteId,
    ): ?ConfigurationSnapshot {
        if ($target->previous_snapshot_id !== null) {
            $linked = ConfigurationSnapshot::query()
                ->where('id', $target->previous_snapshot_id)
                ->where('device_id', $device->id)
                ->where('site_id', $siteId)
                ->where('storage_state', 'available')
                ->whereNull('payload_deleted_at')
                ->first();
            if ($linked instanceof ConfigurationSnapshot) {
                return $linked;
            }
        }

        return ConfigurationSnapshot::query()
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->where('id', '!=', $target->id)
            ->where('storage_state', 'available')
            ->whereNull('payload_deleted_at')
            ->where(function ($query) use ($target): void {
                $query->where('captured_at', '<', $target->captured_at)
                    ->orWhere(function ($sameTime) use ($target): void {
                        $sameTime->where('captured_at', '=', $target->captured_at)
                            ->where('id', '<', $target->id);
                    });
            })
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSnapshotMeta(Device $device, ConfigurationSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'source_kind' => $snapshot->source_kind,
            'source_label' => match ($snapshot->source_kind) {
                'provider' => 'Provider configuration inventory',
                'ssh' => 'Read-only SSH inventory',
                'winrm' => 'Read-only WinRM inventory',
                'command' => 'SSH configuration backup',
                default => 'Configuration snapshot',
            },
            'source' => $snapshot->source,
            'captured_at' => $snapshot->captured_at?->toISOString(),
            'firmware_version' => $snapshot->firmware_version,
            'configuration_hash' => $snapshot->configuration_hash,
            'content_size' => $snapshot->content_size,
            'download_href' => "/security-devices/devices/{$device->id}/configuration-snapshots/{$snapshot->id}",
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{
     *     items: list<array{kind: string, path: string, before: string|null, after: string|null}>,
     *     counts: array{added: int, removed: int, changed: int},
     *     truncated: bool
     * }
     */
    private function computeFieldChanges(array $before, array $after): array
    {
        unset($before['running_config_lines'], $after['running_config_lines']);
        $beforeLeaves = $this->flattenScalars($before);
        $afterLeaves = $this->flattenScalars($after);

        $addedKeys = array_values(array_diff(array_keys($afterLeaves), array_keys($beforeLeaves)));
        $removedKeys = array_values(array_diff(array_keys($beforeLeaves), array_keys($afterLeaves)));
        $changedKeys = [];
        foreach (array_intersect(array_keys($beforeLeaves), array_keys($afterLeaves)) as $path) {
            if ($beforeLeaves[$path] !== $afterLeaves[$path]) {
                $changedKeys[] = $path;
            }
        }

        sort($addedKeys, SORT_STRING);
        sort($removedKeys, SORT_STRING);
        sort($changedKeys, SORT_STRING);

        $items = [];
        foreach ($changedKeys as $path) {
            $items[] = [
                'kind' => 'changed',
                'path' => $path,
                'before' => $beforeLeaves[$path],
                'after' => $afterLeaves[$path],
            ];
        }
        foreach ($addedKeys as $path) {
            $items[] = [
                'kind' => 'added',
                'path' => $path,
                'before' => null,
                'after' => $afterLeaves[$path],
            ];
        }
        foreach ($removedKeys as $path) {
            $items[] = [
                'kind' => 'removed',
                'path' => $path,
                'before' => $beforeLeaves[$path],
                'after' => null,
            ];
        }

        $truncated = count($items) > self::MAX_FIELD_CHANGES;

        return [
            'items' => array_slice($items, 0, self::MAX_FIELD_CHANGES),
            'counts' => [
                'added' => count($addedKeys),
                'removed' => count($removedKeys),
                'changed' => count($changedKeys),
            ],
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @return array<string, string>
     */
    private function flattenScalars(array $node, string $prefix = ''): array
    {
        $leaves = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                if ($value === []) {
                    $leaves[$path] = '[]';
                } else {
                    $leaves += $this->flattenScalars($value, $path);
                }

                continue;
            }

            $leaves[$path] = $this->formatScalar($value);
        }

        return $leaves;
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $text = (string) $value;

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 160).'...' : $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function renderableLines(array $payload): array
    {
        if (isset($payload['running_config_lines']) && is_array($payload['running_config_lines'])) {
            return $this->redactor->redactLines($payload['running_config_lines']);
        }

        if ($payload === []) {
            return [];
        }

        $formatted = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($formatted)) {
            return [];
        }

        return array_slice(preg_split("/\r\n|\n|\r/", $formatted) ?: [], 0, NetworkConfigurationRedactor::MAX_CONFIG_LINES);
    }

    /**
     * Compute a bounded LCS-based unified diff with context windows.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{
     *     lines: list<array{kind: string, old_line: int|null, new_line: int|null, content: string}>,
     *     added_count: int,
     *     removed_count: int,
     *     truncated: bool
     * }
     */
    private function computeUnifiedDiff(array $before, array $after): array
    {
        $m = count($before);
        $n = count($after);

        if ($m === 0 && $n === 0) {
            return ['lines' => [], 'added_count' => 0, 'removed_count' => 0, 'truncated' => false];
        }

        // Trim common prefix and suffix first so LCS stays tiny and fast even on 1000-line configs.
        $prefix = 0;
        while ($prefix < $m && $prefix < $n && $before[$prefix] === $after[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < ($m - $prefix)
            && $suffix < ($n - $prefix)
            && $before[$m - 1 - $suffix] === $after[$n - 1 - $suffix]
        ) {
            $suffix++;
        }

        $rawOps = [];
        for ($i = 0; $i < $prefix; $i++) {
            $rawOps[] = ['kind' => 'context', 'old_line' => $i + 1, 'new_line' => $i + 1, 'content' => $before[$i]];
        }

        $midBefore = array_slice($before, $prefix, $m - $prefix - $suffix);
        $midAfter = array_slice($after, $prefix, $n - $prefix - $suffix);
        $midM = count($midBefore);
        $midN = count($midAfter);

        if ($midM > 0 || $midN > 0) {
            if ($midM * $midN <= 250_000) {
                $dp = array_fill(0, $midM + 1, array_fill(0, $midN + 1, 0));
                for ($i = $midM - 1; $i >= 0; $i--) {
                    for ($j = $midN - 1; $j >= 0; $j--) {
                        $dp[$i][$j] = $midBefore[$i] === $midAfter[$j]
                            ? $dp[$i + 1][$j + 1] + 1
                            : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                    }
                }
                $i = 0;
                $j = 0;
                while ($i < $midM && $j < $midN) {
                    if ($midBefore[$i] === $midAfter[$j]) {
                        $rawOps[] = [
                            'kind' => 'context',
                            'old_line' => $prefix + $i + 1,
                            'new_line' => $prefix + $j + 1,
                            'content' => $midBefore[$i],
                        ];
                        $i++;
                        $j++;
                    } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                        $rawOps[] = [
                            'kind' => 'removed',
                            'old_line' => $prefix + $i + 1,
                            'new_line' => null,
                            'content' => $midBefore[$i],
                        ];
                        $i++;
                    } else {
                        $rawOps[] = [
                            'kind' => 'added',
                            'old_line' => null,
                            'new_line' => $prefix + $j + 1,
                            'content' => $midAfter[$j],
                        ];
                        $j++;
                    }
                }
                while ($i < $midM) {
                    $rawOps[] = [
                        'kind' => 'removed',
                        'old_line' => $prefix + $i + 1,
                        'new_line' => null,
                        'content' => $midBefore[$i],
                    ];
                    $i++;
                }
                while ($j < $midN) {
                    $rawOps[] = [
                        'kind' => 'added',
                        'old_line' => null,
                        'new_line' => $prefix + $j + 1,
                        'content' => $midAfter[$j],
                    ];
                    $j++;
                }
            } else {
                foreach ($midBefore as $idx => $line) {
                    $rawOps[] = [
                        'kind' => 'removed',
                        'old_line' => $prefix + $idx + 1,
                        'new_line' => null,
                        'content' => $line,
                    ];
                }
                foreach ($midAfter as $idx => $line) {
                    $rawOps[] = [
                        'kind' => 'added',
                        'old_line' => null,
                        'new_line' => $prefix + $idx + 1,
                        'content' => $line,
                    ];
                }
            }
        }

        for ($k = 0; $k < $suffix; $k++) {
            $oldIdx = $m - $suffix + $k;
            $newIdx = $n - $suffix + $k;
            $rawOps[] = [
                'kind' => 'context',
                'old_line' => $oldIdx + 1,
                'new_line' => $newIdx + 1,
                'content' => $before[$oldIdx],
            ];
        }

        $addedCount = 0;
        $removedCount = 0;
        $totalOps = count($rawOps);
        $keep = array_fill(0, $totalOps, false);

        foreach ($rawOps as $idx => $op) {
            if ($op['kind'] === 'added') {
                $addedCount++;
            } elseif ($op['kind'] === 'removed') {
                $removedCount++;
            }
            if ($op['kind'] !== 'context') {
                $start = max(0, $idx - self::CONTEXT_RADIUS);
                $end = min($totalOps - 1, $idx + self::CONTEXT_RADIUS);
                for ($c = $start; $c <= $end; $c++) {
                    $keep[$c] = true;
                }
            }
        }

        // When identical, show the first few lines as context preview so operators see what was compared.
        if ($addedCount === 0 && $removedCount === 0) {
            $previewCap = min(20, $totalOps);
            for ($c = 0; $c < $previewCap; $c++) {
                $keep[$c] = true;
            }
        }

        $windowed = [];
        $inGap = false;
        foreach ($rawOps as $idx => $op) {
            if ($keep[$idx]) {
                $windowed[] = $op;
                $inGap = false;
            } elseif (! $inGap && $windowed !== []) {
                $windowed[] = [
                    'kind' => 'gap',
                    'old_line' => null,
                    'new_line' => null,
                    'content' => '...',
                ];
                $inGap = true;
            }
        }

        $truncated = count($windowed) > self::MAX_DIFF_LINES;

        return [
            'lines' => array_slice($windowed, 0, self::MAX_DIFF_LINES),
            'added_count' => $addedCount,
            'removed_count' => $removedCount,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}

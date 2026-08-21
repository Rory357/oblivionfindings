<?php

namespace App\Services\Emar;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Builds the audit drawer's "Device & integrity" panel from data the app
 * ALREADY captures — the AuditLog rows written by the AuditableChanges trait
 * (IP, user-agent, create/update history) — plus a deterministic content
 * fingerprint of the stored row.
 *
 * This is an honest integrity surface, NOT a cryptographic tamper-evidence
 * seal: the fingerprint reflects the row's current content (it changes if the
 * record is altered) but there is no stored hash-chain to prove a row was never
 * edited. "Edited since" is therefore evidence-based — derived from real update
 * log entries — rather than a hardcoded claim.
 */
class MedicationAuditIntegrityService
{
    /**
     * @return array<string, mixed>
     */
    public function forModel(Model $model): array
    {
        $clientId = (int) $model->getAttribute('client_id');

        $logs = AuditLog::query()
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey())
            ->where('client_id', $clientId)
            ->orderBy('id')
            ->get(['id', 'action', 'ip_address', 'user_agent', 'created_at']);

        $createLog = $logs->first(fn ($l) => str_ends_with((string) $l->action, '.create'));
        $updateLogs = $logs->filter(fn ($l) => str_ends_with((string) $l->action, '.update'))->values();
        $editCount = $updateLogs->count();

        $recordedAt = $createLog?->created_at ?? $model->created_at;

        if ($editCount > 0) {
            $last = $updateLogs->last();
            $when = optional($last->created_at)->toDayDateTimeString();
            $edited = "Edited {$when} · {$editCount} change".($editCount === 1 ? '' : 's').' logged';
        } elseif ((bool) ($model->is_correction ?? false)) {
            $edited = 'Correcting entry — supersedes an earlier record';
        } else {
            $edited = 'No edits recorded — append-only';
        }

        return [
            'backed' => true,
            'note' => null,
            'recorded_at' => $recordedAt instanceof Carbon ? $recordedAt->toIso8601String() : ($recordedAt ? Carbon::parse($recordedAt)->toIso8601String() : null),
            'device' => $this->deviceLabel($createLog?->user_agent),
            'ip_address' => $createLog?->ip_address,
            'edited' => $edited,
            'edit_count' => $editCount,
            'fingerprint' => $this->fingerprint($model),
        ];
    }

    private function deviceLabel(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "{$browser} · {$platform}" : $platform;
    }

    /**
     * Deterministic SHA-256 over the stored row's content. Changes iff the
     * record's content changes — an integrity check, not a sealed chain.
     */
    private function fingerprint(Model $model): string
    {
        $attrs = $model->getAttributes();
        unset($attrs['updated_at']);
        ksort($attrs);

        return hash('sha256', (string) json_encode($attrs));
    }
}

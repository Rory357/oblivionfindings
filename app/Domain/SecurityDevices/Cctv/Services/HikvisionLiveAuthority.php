<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CctvLiveSession;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Models\User;

/** Strategy within the canonical CCTV session owner; no separate session table. */
final class HikvisionLiveAuthority
{
    public function source(User $actor, CctvSource $source, bool $enabled = true, bool $manage = false): array
    {
        abort_unless(config('cctv.enabled') === true && config('cctv.hikvision_live_enabled') === true, 503, 'temporarily_unavailable');
        abort_unless($source->provider === 'hikvision' && (! $enabled || $source->enabled), 404, 'unavailable');
        $current = app(HikvisionSources::class)->authority((int) $actor->id, $source, $manage);
        abort_unless(in_array(HikvisionLiveContract::CAPABILITY, $current['reference']->capabilities ?? [], true), 503, 'temporarily_unavailable');

        return [$current['device'], null, $current['collector'], $current];
    }

    public function snapshot(CctvSource $source, array $current): array
    {
        return ['version' => 1, 'source_id' => (int) $source->id, 'source_revision' => (int) $source->revision, 'source_fingerprint' => $source->fingerprint, 'binding_sha256' => $current['binding'], 'credential_binding_sha256' => $current['credential'], 'support_sha256' => $source->configuration['support_sha256'], 'discovery_result_sha256' => $source->configuration['result_sha256'], 'credential_reference_id' => (int) $current['reference']->id, 'credential_reference_version' => (int) $current['reference']->version];
    }

    public function session(CctvLiveSession $session, CctvSource $source, array $current): void
    {
        abort_unless($session->provider === 'hikvision' && is_array($session->source_context) && hash_equals(CctvContract::hash($session->source_context), CctvContract::hash($this->snapshot($source, $current))), 409, 'context_changed');
        abort_unless($session->material && $session->credential_lease_grant_id, 503, 'temporarily_unavailable');
        $grant = CredentialLeaseGrant::query()->whereKey($session->credential_lease_grant_id)->lock('for share nowait')->first();
        abort_unless($grant && $grant->status === CredentialLeaseGrant::STATUS_ISSUED && (int) $grant->site_id === $current['site'] && (int) $grant->credential_reference_id === (int) $current['reference']->id && (int) $grant->reference_version === (int) $current['reference']->version && $grant->capabilities === [HikvisionLiveContract::CAPABILITY] && $grant->issued_at->lte(now()) && $grant->expires_at->gt(now()) && $grant->expires_at->gte($session->session_expires_at), 503, 'temporarily_unavailable');
        $material = $session->material;
        CctvContract::exact($material, ['source_json', 'turn_json', 'offer_sdp', 'answer']);
        $private = json_decode($material['source_json'], true, 8, JSON_THROW_ON_ERROR);
        CctvContract::exact($private, ['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin', 'username', 'password']);
        foreach (['allowed_ip', 'allowed_port', 'tls_server_name', 'tls_pin'] as $field) {
            abort_unless($private[$field] === $source->configuration['discovery_configuration'][$field], 409, 'context_changed');
        }
    }
}

<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use Illuminate\Support\Facades\Validator;

final class EndpointSoftwareContract
{
    public const CAPABILITY = 'endpoint.software.install';

    public const MAX_BYTES = 536870912;

    public const REASONS = ['installed', 'installed_restart_required', 'restart_initiated', 'installer_busy', 'installer_failed', 'artifact_unavailable', 'artifact_invalid', 'signature_invalid', 'product_mismatch', 'architecture_mismatch', 'already_installed', 'downgrade_denied', 'policy_unavailable', 'consent_lost', 'operation_failed', 'declined', 'expired', 'interrupted', 'state_unavailable'];

    public static function parameters(array $input): array
    {
        abort_unless(array_keys($input) === ['package_uuid'], 422);

        return Validator::make($input, ['package_uuid' => ['required', 'uuid']])->validate();
    }

    public static function metadata(array $input): array
    {
        abort_if(array_diff(array_keys($input), ['name', 'publisher', 'product_code', 'product_version', 'architecture', 'signer_sha256']) !== [], 422);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}]/u'], 'publisher' => ['required', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}]/u'], 'product_code' => ['required', 'regex:/\A\{[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}\}\z/'], 'product_version' => ['required', 'regex:/\A(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,4})\z/'], 'architecture' => ['required', 'in:amd64,arm64'], 'signer_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/']])->validate();
        [$major,$minor,$build] = array_map('intval', explode('.', $data['product_version']));
        abort_unless($major <= 255 && $minor <= 255 && $build <= 65535, 422);

        return $data;
    }

    public static function validateResult(array $data): void
    {
        $status = $data['status'];
        $reason = $data['reason_code'];
        $started = $data['mutation_started'];
        $exit = $data['exit_code'];
        $reboot = $data['reboot_required'];
        if ($status === 'awaiting_observation') {
            abort_unless($started && (($exit === 0 && $reason === 'installed' && ! $reboot) || ($exit === 3010 && $reason === 'installed_restart_required' && $reboot)), 422);

            return;
        }
        if (in_array($status, ['declined', 'expired'], true)) {
            abort_unless(! $started && $exit === null && ! $reboot && $reason === $status, 422);

            return;
        }
        if ($status === 'uncertain') {
            // A lost claim reply or claiming-phase crash can be uncertain without installer entry.
            // This terminal receipt never authorizes execution or an automatic retry.
            abort_unless(($started === true && $exit === 1641 && $reason === 'restart_initiated' && $reboot) || (is_bool($started) && $exit === null && ! $reboot && in_array($reason, ['interrupted', 'state_unavailable'], true)), 422);

            return;
        }
        abort_unless($status === 'failed', 422);
        if ($started) {
            abort_unless(is_int($exit) && ! in_array($exit, [0, 3010, 1641], true) && ! $reboot && $reason === ($exit === 1618 ? 'installer_busy' : 'installer_failed'), 422);

            return;
        }
        abort_unless($exit === null && ! $reboot && in_array($reason, ['artifact_unavailable', 'artifact_invalid', 'signature_invalid', 'product_mismatch', 'architecture_mismatch', 'already_installed', 'downgrade_denied', 'policy_unavailable', 'consent_lost', 'operation_failed'], true), 422);
    }
}

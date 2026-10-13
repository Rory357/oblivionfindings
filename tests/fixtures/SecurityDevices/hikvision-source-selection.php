<?php

// Development-only consumer of the actual original PHP/Go discovery witness.
// This does not boot the app, approve hardware or activate a canonical source.
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSourcePolicy;

require dirname(__DIR__, 3).'/vendor/autoload.php';
try {
    $evidence = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
    if ($evidence['synthetic'] !== true || $evidence['hardware_acceptance'] !== false) {
        throw new RuntimeException;
    }
    $original = $evidence['result'];
    $hash = CctvContract::hash($original);
    $canonical = ['model' => 'DS-2CD2347G3-LIS2UY/SL', 'serial' => 'fixture-camera-serial'];
    $profiles = [['model' => $canonical['model'], 'firmware' => 'fixture-version', 'firmware_build' => 'fixture-build', 'transport' => HikvisionSourcePolicy::PROFILE, 'input_ids' => ['1'], 'witness_id' => 'synthetic-loopback-only']];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $selected = HikvisionSourcePolicy::select($original, $canonical, '102', HikvisionSourcePolicy::PROFILE, $profiles, $now);
    if ($original['inventory']['channels'][0] !== $selected['channel'] || $hash !== CctvContract::hash($original) || $selected['video_codec'] !== 'H264' || $selected['audio_enabled'] !== false) {
        throw new RuntimeException;
    }
    $denials = 0;
    foreach (['serial' => 'different-device', 'model' => 'different-model'] as $field => $value) {
        try {
            HikvisionSourcePolicy::select($original, array_replace($canonical, [$field => $value]), '102', HikvisionSourcePolicy::PROFILE, $profiles, $now);
        } catch (InvalidArgumentException) {
            $denials++;
        }
    }
    if ($denials !== 2) {
        throw new RuntimeException;
    }
    echo CctvContract::json(['synthetic' => true, 'hardware_acceptance' => false, 'original_result_sha256' => $hash, 'selected_channel' => $selected['channel']['id'], 'original_codec' => $selected['channel']['codec'], 'video_codec' => $selected['video_codec'], 'audio_enabled' => $selected['audio_enabled'], 'mismatched_identity_denials' => $denials]).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Synthetic source selection fixture unavailable.\n");
    exit(1);
}

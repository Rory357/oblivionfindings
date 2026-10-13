<?php

namespace App\Domain\Obli;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ObliDiagnostics
{
    public const CATEGORIES = ['system', 'software', 'services'];

    public const PREFIX = "\n\nTechnical snapshot selected by the user (informational, not remote attestation):\n";

    public static function normalize(array $snapshot, array $allowed, bool $latest = false): array
    {
        abort_if(strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 4096, 422);
        abort_if(array_diff(array_keys($snapshot), ['schema', 'observed_at', ...self::CATEGORIES]) !== [], 422);
        $groups = array_values(array_intersect(self::CATEGORIES, array_keys($snapshot)));
        abort_if($groups === [] || array_diff($groups, $allowed) !== [], 422);
        $rules = [
            'schema' => ['required', 'integer', 'in:1'],
            'observed_at' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/'],
            'system' => ['sometimes', 'array:windows_version,architecture,memory_total_bytes,memory_available_bytes,uptime_seconds,cpu_percent,disks'],
            'software' => ['sometimes', 'array', 'max:4'],
            'software.*' => ['array:name,version'],
            'software.*.name' => ['required', 'distinct', Rule::in(['microsoft_edge', 'google_chrome', 'microsoft_teams', 'teams_installer'])],
            'software.*.version' => ['required', 'string', 'regex:/\A[0-9]{1,9}(\.[0-9]{1,9}){1,5}\z/'],
            'services' => ['sometimes', 'array', 'max:5'],
            'services.*' => ['array:name,state'],
            'services.*.name' => ['required', 'distinct', Rule::in(['print_spooler', 'windows_update', 'background_transfer', 'windows_defender', 'windows_firewall'])],
            'services.*.state' => ['required', Rule::in(['running', 'stopped', 'unknown'])],
        ];
        if (in_array('system', $groups, true)) {
            $rules += [
                'system.windows_version' => ['required', 'string', 'regex:/\A(unknown|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,8}(\.[0-9]{1,8})?)\z/'],
                'system.architecture' => ['required', Rule::in(['amd64', 'arm64', 'unknown'])],
                'system.memory_total_bytes' => ['present', 'nullable', 'integer', 'min:1', 'max:9007199254740991'],
                'system.memory_available_bytes' => ['present', 'nullable', 'integer', 'min:0', 'max:9007199254740991'],
                'system.uptime_seconds' => ['present', 'nullable', 'integer', 'min:0', 'max:3155760000'],
                'system.cpu_percent' => ['present', 'nullable', 'numeric', 'between:0,100'],
                'system.disks' => ['present', 'array', 'max:8'],
                'system.disks.*' => ['array:name,total_bytes,free_bytes'],
                'system.disks.*.name' => ['required', 'string', 'distinct', 'regex:/\A[A-Z]:\z/'],
                'system.disks.*.total_bytes' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
                'system.disks.*.free_bytes' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            ];
        }
        $data = Validator::make($snapshot, $rules)->validate();
        $observed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['observed_at'], new DateTimeZone('UTC'));
        abort_unless($observed && $observed->format('Y-m-d\TH:i:s\Z') === $data['observed_at'], 422);
        abort_if($observed->getTimestamp() > now()->timestamp + 30 ||
            $observed->getTimestamp() < now()->timestamp - ($latest ? 360 : 86400), 422);
        $out = ['schema' => 1, 'observed_at' => $data['observed_at']];
        if (isset($data['system'])) {
            $system = $data['system'];
            foreach (['memory_total_bytes', 'memory_available_bytes', 'uptime_seconds'] as $field) {
                $system[$field] = $system[$field] === null ? null : (int) $system[$field];
            }
            $system['cpu_percent'] = $system['cpu_percent'] === null ? null : (float) $system['cpu_percent'];
            abort_if($system['memory_available_bytes'] !== null && ($system['memory_total_bytes'] === null || $system['memory_available_bytes'] > $system['memory_total_bytes']), 422);
            foreach ($system['disks'] as &$disk) {
                $disk['total_bytes'] = (int) $disk['total_bytes'];
                $disk['free_bytes'] = (int) $disk['free_bytes'];
                abort_if($disk['free_bytes'] > $disk['total_bytes'], 422);
            }
            unset($disk);
            abort_unless(array_is_list($system['disks']), 422);
            $out['system'] = $system;
        }
        foreach (['software', 'services'] as $field) {
            if (array_key_exists($field, $data)) {
                abort_unless(array_is_list($data[$field]), 422);
                $out[$field] = $data[$field];
            }
        }

        return $out;
    }

    public static function providerMessages(array $messages, array $allowed): array
    {
        $result = [];
        $bytes = 0;
        foreach ($messages as $i => $message) {
            $text = $message['content'];
            if (array_key_exists('diagnostics', $message)) {
                abort_unless($message['role'] === 'user' && is_array($message['diagnostics']), 422);
                $snapshot = self::normalize($message['diagnostics'], $allowed, $i === count($messages) - 1);
                $text .= self::PREFIX.json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            $bytes += strlen($text);
            $result[] = ['role' => $message['role'], 'content' => $text];
        }
        abort_if($bytes > 12000, 422);

        return $result;
    }
}

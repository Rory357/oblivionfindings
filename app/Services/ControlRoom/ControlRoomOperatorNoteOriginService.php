<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** CR human-writer provenance. It grants no Site/source/read authority. */
final class ControlRoomOperatorNoteOriginService
{
    public function capture(?ControlRoomAlert $alert, ?Shift $shift, User $actor, string $content, string $type, string $purpose): array
    {
        return [
            'version' => 1, 'source' => 'independent_operator',
            'alert_id' => $alert === null ? null : (int) $alert->id,
            'shift_id' => $shift === null ? null : (int) $shift->id,
            'site_id' => $alert?->site_id === null ? null : (int) $alert->site_id,
            'client_id' => $alert?->client_id === null ? null : (int) $alert->client_id,
            'user_id' => (int) $actor->id, 'type' => $type, 'purpose' => $purpose,
            'text_sha256' => hash('sha256', $content),
        ];
    }

    /** Read-only final check after the normal note/audit writes; no callback follows it. */
    public function persisted(OperatorNote $note, array $expected): array
    {
        $expected['id'] ??= (int) $note->id;
        $row = DB::table('control_room_operator_notes')->where('id', $expected['id'])->lockForUpdate()->first();
        if ($row === null || $this->normalise(array_intersect_key((array) $row, $expected)) !== $this->normalise($expected)) {
            throw new RuntimeException('Operator note and original context were not persisted.');
        }

        return (array) $row;
    }

    public function sameOrigin(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return false;
        }

        return $this->normalise(['operator_origin' => $left]) === $this->normalise(['operator_origin' => $right]);
    }

    private function normalise(array $tuple): array
    {
        foreach ($tuple as $key => $value) {
            if ($key === 'operator_origin') {
                $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
                if (is_array($decoded)) {
                    ksort($decoded);
                }
                $tuple[$key] = $decoded;
            } elseif ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) {
                $tuple[$key] = (int) $value;
            } elseif (in_array($key, ['is_pinned', 'requires_followup'], true)) {
                $tuple[$key] = (bool) $value;
            }
        }
        ksort($tuple);

        return $tuple;
    }
}

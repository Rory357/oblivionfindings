<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\MyDay\ShiftTaskWorkService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Person sections belong to the existing, versioned shift handover. */
class HandoverWorkerNotes
{
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'worker_notes' => ['sometimes', 'array:people,shared_notes'],
            $prefix.'worker_notes.people' => ['required_with:'.$prefix.'worker_notes', 'array', 'max:50'],
            $prefix.'worker_notes.people.*' => ['array:client_id,notes,no_updates,follow_up_needed,not_supported'],
            $prefix.'worker_notes.people.*.client_id' => ['required', 'integer', 'min:1', 'distinct'],
            $prefix.'worker_notes.people.*.notes' => ['nullable', 'string', 'max:2000'],
            $prefix.'worker_notes.people.*.no_updates' => ['required', 'boolean'],
            $prefix.'worker_notes.people.*.not_supported' => ['sometimes', 'boolean'],
            $prefix.'worker_notes.people.*.follow_up_needed' => ['required', 'boolean'],
            $prefix.'worker_notes.shared_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Called inside the canonical handover transaction, after current authority is locked. */
    public function normalize(Shift $shift, User $actor, mixed $input, ?array $existing = null): array
    {
        $data = Validator::make(['worker_notes' => $input], self::rules())->validate()['worker_notes'];
        $siteId = (int) ($shift->site_id ?? $shift->client?->site_id);
        $clients = Client::query()->whereIn('id', array_column($data['people'], 'client_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $people = [];
        foreach ($data['people'] as $entry) {
            $client = $clients->get((int) $entry['client_id']);
            abort_unless($client && $siteId > 0 && (int) $client->site_id === $siteId
                && $client->status !== 'archived' && Gate::forUser($actor)->allows('view', $client), 404);
            $notes = trim((string) ($entry['notes'] ?? ''));
            $noUpdates = (bool) $entry['no_updates'];
            $notSupported = (bool) ($entry['not_supported'] ?? false);
            if ($notSupported && ($notes !== '' || $noUpdates || $entry['follow_up_needed'])) {
                throw ValidationException::withMessages(['worker_notes' => 'A person marked not supported cannot also have support notes or follow-up selected. Check that person’s section.']);
            }
            if ($notes === '' && ! $noUpdates && ! $entry['follow_up_needed'] && ! $notSupported) {
                continue; // An empty section is not evidence that someone was supported.
            }
            $people[] = [
                'client_id' => (int) $client->id,
                'notes' => $notes,
                'no_updates' => $noUpdates && $notes === '',
                'not_supported' => $notSupported,
                'follow_up_needed' => (bool) $entry['follow_up_needed'],
            ];
        }
        // A privacy change may remove a section from the editor. Keep that
        // saved evidence; absence from a privacy-filtered form is not deletion.
        $requestedIds = array_column($data['people'], 'client_id');
        foreach ($existing['people'] ?? [] as $entry) {
            if (! in_array($entry['client_id'], $requestedIds)) {
                $people[] = $entry;
            }
        }

        return ['people' => $people, 'shared_notes' => trim((string) ($data['shared_notes'] ?? ''))];
    }

    /** The caller must first authorize the parent handover; every person is then rechecked. */
    public function present(ShiftHandover $handover, User $actor): ?array
    {
        $value = $handover->worker_notes;
        if (! is_array($value)) {
            return null;
        }
        $outgoing = $handover->outgoingShift;
        $siteId = (int) (array_key_exists('site_id', $outgoing?->getAttributes() ?? [])
            ? $outgoing->site_id : Shift::query()->whereKey($handover->outgoing_shift_id)->value('site_id'));
        $clients = Client::query()->whereIn('id', array_column($value['people'] ?? [], 'client_id'))->get()->keyBy('id');
        $value['people'] = collect($value['people'] ?? [])->filter(function ($entry) use ($clients, $siteId, $actor) {
            $client = $clients->get($entry['client_id']);

            return $client && (int) $client->site_id === $siteId && Gate::forUser($actor)->allows('view', $client);
        })->map(fn ($entry) => [...$entry, 'name' => trim($clients[$entry['client_id']]->first_name.' '.$clients[$entry['client_id']]->last_name)])
            ->values()->all();

        return $value;
    }

    public function editor(Shift $shift, User $actor): array
    {
        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->first();
        abort_if($handover && ((int) $handover->client_id !== (int) $shift->client_id
            || (int) $handover->outgoing_staff_id !== (int) $shift->user_id), 404);
        $people = app(ShiftTaskWorkService::class)->availableClients($actor, $shift)
            ->map(fn (Client $client) => ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)])->all();
        $notes = $handover ? $this->present($handover, $actor) : null;
        if ($notes === null && $handover) {
            $notes = ['shared_notes' => '', 'people' => [[
                'client_id' => (int) $handover->client_id, 'notes' => $handover->handover_notes ?? '',
                'no_updates' => false, 'follow_up_needed' => count($handover->follow_up_items ?? []) > 0,
            ]]];
        }

        return [
            'people' => $people,
            'handover_id' => $handover?->id,
            'review_url' => $handover ? '/operations/handovers?'.http_build_query([
                'week' => $shift->starts_at->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                'handover' => $handover->id,
            ]) : null,
            'expected_version' => $handover ? (int) $handover->version : null,
            'status' => $handover?->status,
            'saved_at' => $handover?->updated_at?->toIso8601String(),
            'worker_notes' => $notes ?? ['people' => [], 'shared_notes' => ''],
        ];
    }

    public function shiftSummary(?Shift $shift, User $actor): ?array
    {
        if (! $shift || (int) $shift->user_id !== (int) $actor->id
            || ! $shift->client || ! Gate::forUser($actor)->allows('view', $shift->client)) {
            return null;
        }
        $editor = $this->editor($shift, $actor);
        $notes = collect($editor['worker_notes']['people'])->keyBy('client_id');

        return [
            'shift_id' => $shift->id,
            'id' => $editor['handover_id'],
            'status' => $editor['status'],
            'review_url' => $editor['review_url'],
            'people' => array_map(function ($person) use ($notes) {
                $note = $notes->get($person['id']);

                return [...$person, 'state' => ($note['not_supported'] ?? false) ? 'not_supported'
                    : ($note && (trim($note['notes'] ?? '') !== '' || ($note['no_updates'] ?? false) || ($note['follow_up_needed'] ?? false)) ? 'recorded' : 'not_started')];
            }, $editor['people']),
        ];
    }

    public function latestDraft(User $actor): ?array
    {
        $handover = ShiftHandover::query()
            ->tap(fn ($query) => app(UserSiteAccessService::class)->applyHandoverScope($query, $actor))
            ->where('outgoing_staff_id', $actor->id)->where('status', 'draft')
            ->whereHas('outgoingShift', fn ($query) => $query->where('user_id', $actor->id)->where('starts_at', '>=', now()->subDays(7)))
            ->with(['outgoingShift', 'client'])->latest('updated_at')->first();
        if (! $handover || ! Gate::forUser($actor)->allows('view', $handover->client)) {
            return null;
        }

        return [
            'id' => $handover->id,
            'review_url' => '/operations/handovers?'.http_build_query([
                'week' => $handover->outgoingShift->starts_at->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                'handover' => $handover->id,
            ]),
        ];
    }
}

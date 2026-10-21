<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Chat\ChatOffboarding;
use App\Services\Chat\ChatPortalRelationships;
use App\Services\Chat\ChatSettings;
use Illuminate\Http\Request;

/**
 * Chat administration beside the policy form: the settings audit, offboarding
 * of expired access, and the read-only client relationship view. None of
 * these routes opens private conversation content.
 */
final class ChatAdministrationController extends Controller
{
    /** The policy audit. Investigation records live in their own domain and never appear here. */
    public function audit(Request $request)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
        $rows = AuditLog::query()->where(fn ($actions) => $actions->where('action', 'chat.settings.updated')->orWhere('action', 'chat.offboarding.completed'))
            ->with('user:id,name')->orderByDesc('id')->limit(50)->get();
        $people = User::whereIn('id', $rows->where('action', 'chat.offboarding.completed')->map(fn ($row) => $row->meta['person_id'] ?? null)->filter())->pluck('name', 'id');

        return $this->json(['entries' => $rows->map(fn (AuditLog $row) => [
            'id' => $row->id,
            'kind' => $row->action === 'chat.settings.updated' ? 'policy' : 'offboarding',
            'actor' => $row->user?->name ?? 'Former staff member',
            'created_at' => $row->created_at?->toISOString(),
            'changes' => $row->action === 'chat.settings.updated' ? $this->changes($row->meta['before'] ?? [], $row->meta['after'] ?? []) : [],
            'offboarding' => $row->action === 'chat.offboarding.completed' ? [
                'person' => $people[$row->meta['person_id'] ?? 0] ?? 'Former account',
                'memberships_ended' => (int) ($row->meta['memberships_ended'] ?? 0),
                'work_moved' => (int) ($row->meta['callbacks_reassigned'] ?? 0) + (int) ($row->meta['callbacks_returned'] ?? 0)
                    + (int) ($row->meta['family_work_reassigned'] ?? 0) + (int) ($row->meta['family_work_returned'] ?? 0),
            ] : null,
        ])->values()]);
    }

    public function offboardingSearch(Request $request, ChatOffboarding $offboarding)
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return $this->json(['people' => $offboarding->search($request->user(), $data['q'])]);
    }

    public function offboardingPreview(Request $request, User $person, ChatOffboarding $offboarding)
    {
        return $this->json($offboarding->preview($request->user(), $person));
    }

    public function offboardingComplete(Request $request, User $person, ChatOffboarding $offboarding)
    {
        $offboarding->authorize($request->user());
        $data = $request->validate([
            'owner' => ['required', 'in:care_team,person'],
            'owner_id' => ['required_if:owner,person', 'nullable', 'integer'],
        ]);

        return $this->json(['summary' => $offboarding->complete($request->user(), $person, $data), 'preview' => $offboarding->preview($request->user(), $person->fresh())]);
    }

    public function relationshipSearch(Request $request, ChatPortalRelationships $relationships)
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return $this->json(['clients' => $relationships->search($request->user(), $data['q'])]);
    }

    public function relationships(Request $request, Client $client, ChatPortalRelationships $relationships)
    {
        return $this->json($relationships->show($request->user(), $client));
    }

    /** @return list<array{scope: string, field: string, before: mixed, after: mixed}> */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (array_keys(ChatSettings::DEFAULTS) as $field) {
            if ($field === 'site_overrides') {
                continue;
            }
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['scope' => 'organisation', 'field' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }
        $sites = array_unique([...array_keys($before['site_overrides'] ?? []), ...array_keys($after['site_overrides'] ?? [])]);
        foreach ($sites as $site) {
            $old = $before['site_overrides'][$site] ?? [];
            $new = $after['site_overrides'][$site] ?? [];
            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
                if (! array_key_exists($field, $old) || ! array_key_exists($field, $new) || $old[$field] !== $new[$field]) {
                    $changes[] = ['scope' => (string) $site, 'field' => (string) $field,
                        'before' => array_key_exists($field, $old) ? $old[$field] : '(inherited)',
                        'after' => array_key_exists($field, $new) ? $new[$field] : '(inherited)'];
                }
            }
        }

        return $changes;
    }

    private function json(array $body)
    {
        return response()->json($body)->header('Cache-Control', 'private, no-store');
    }
}

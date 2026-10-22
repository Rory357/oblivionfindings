<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Operational attestations, separate from canonical authority and funding approvals. */
final class ReferralChecklists
{
    public function validateConfiguration(array $configuration): void
    {
        $templates = $configuration['checklists'] ?? [];
        Validator::make(['templates' => $templates], [
            'templates' => 'array|max:20',
            'templates.*' => 'required|array:id,label,enabled,audience,role_id,service_ids,gate,items',
            'templates.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'templates.*.label' => 'required|string|max:160', 'templates.*.enabled' => 'required|boolean',
            'templates.*.audience' => ['required', Rule::in(['intake', 'assessment', 'funding'])],
            'templates.*.role_id' => 'present|nullable|integer|exists:roles,id',
            'templates.*.service_ids' => 'present|array|max:30',
            'templates.*.service_ids.*' => ['required', Rule::in([...array_column($configuration['services'], 'id'), 'unsure'])],
            'templates.*.gate' => ['required', Rule::in(['none', 'assessment', 'funding_assistance', 'handover'])],
            'templates.*.items' => 'required|array|min:1|max:30',
            'templates.*.items.*' => 'required|array:id,title,instruction,enabled,required',
            'templates.*.items.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/',
            'templates.*.items.*.title' => 'required|string|max:160',
            'templates.*.items.*.instruction' => 'present|nullable|string|max:1000',
            'templates.*.items.*.enabled' => 'required|boolean', 'templates.*.items.*.required' => 'required|boolean',
        ])->validate();
        foreach ($templates as $template) {
            $ids = array_column($template['items'], 'id');
            if (count($ids) !== count(array_unique($ids)) || count($template['service_ids']) !== count(array_unique($template['service_ids']))) {
                throw ValidationException::withMessages(['configuration.checklists' => 'Use unique item references and service conditions within each checklist.']);
            }
            if ($template['gate'] === 'none' && collect($template['items'])->contains(fn ($item) => $item['enabled'] && $item['required'])) {
                throw ValidationException::withMessages(['configuration.checklists' => 'Choose a stage or handover gate for required checklist steps, or mark the steps optional.']);
            }
        }
    }

    public function stableReferences(array $before, array $after): void
    {
        $next = array_column($after['checklists'] ?? [], null, 'id');
        foreach ($before['checklists'] ?? [] as $template) {
            $candidate = $next[$template['id']] ?? null;
            if (! $candidate || $candidate['audience'] !== $template['audience'] || array_diff(array_column($template['items'], 'id'), array_column($candidate['items'], 'id'))) {
                throw ValidationException::withMessages(['configuration.checklists' => 'Keep published checklist/item references and audiences stable. Archive steps or templates instead of deleting them.']);
            }
        }
    }

    private function applicable(CareReferral $referral): array
    {
        if ($referral->general_enquiry) {
            return [];
        }
        $configuration = ReferralSettingsVersion::findOrFail($referral->settings_version_id)->configuration;

        return array_values(array_filter($configuration['checklists'] ?? [], fn ($template) => $template['enabled']
            && ($template['service_ids'] === [] || in_array($referral->requested_service, $template['service_ids'], true))));
    }

    private function signature(array $template, array $item): string
    {
        return hash('sha256', json_encode([$template['id'], $template['audience'], $template['role_id'], $item['id'], $item['title'], $item['instruction'], $item['required']], JSON_THROW_ON_ERROR));
    }

    private function complete(CareReferral $referral, array $template, array $item): bool
    {
        $decision = $referral->intake['checklist_reviews'][$template['id']][$item['id']] ?? null;

        return $decision && $decision['completed'] && hash_equals($decision['signature'], $this->signature($template, $item));
    }

    public function missing(CareReferral $referral, string $gate): int
    {
        $missing = 0;
        foreach ($this->applicable($referral) as $template) {
            // Later gates include earlier required preparation; a direct handover
            // cannot bypass work simply by skipping the displayed working stages.
            $gates = match ($gate) {
                'handover' => ['assessment', 'funding_assistance', 'handover'],
                'funding_assistance' => ['assessment', 'funding_assistance'],
                default => [$gate],
            };
            if (! in_array($template['gate'], $gates, true)) {
                continue;
            }
            foreach ($template['items'] as $item) {
                if ($item['enabled'] && $item['required'] && ! $this->complete($referral, $template, $item)) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    public function present(User $actor, CareReferral $referral): array
    {
        $access = app(ReferralAccess::class);
        $roles = $actor->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
        $results = [];
        foreach ($this->applicable($referral) as $template) {
            if (! $access->audience($actor, $template['audience'])) {
                continue;
            }
            $editable = $access->can($actor, 'tasks.manage') && $access->audience($actor, $template['audience'], true)
                && ($template['role_id'] === null || in_array((int) $template['role_id'], $roles, true))
                && $referral->stage !== 'closed' && ReferralAssessment::scope($referral, 'collect');
            $items = [];
            foreach ($template['items'] as $item) {
                if (! $item['enabled']) {
                    continue;
                }
                $decision = $referral->intake['checklist_reviews'][$template['id']][$item['id']] ?? null;
                $items[] = [...$item, 'completed' => $this->complete($referral, $template, $item), 'decision' => $decision];
            }
            $results[] = [...$template, 'editable' => $editable, 'items' => $items];
        }

        return $results;
    }

    public function record(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, ['template_id' => 'required|string|max:64', 'item_id' => 'required|string|max:64', 'completed' => 'required|boolean', 'note' => 'required|string|min:5|max:2000'])->validate();
        $command = app(ReferralCommand::class);
        $command->run($actor, $id, $version, 'tasks.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $command): void {
            $template = collect($this->applicable($referral))->firstWhere('id', $data['template_id']);
            abort_unless($template && app(ReferralAccess::class)->audience($actor, $template['audience'], true)
                && app(ReferralAccess::class)->audience($actor, $template['audience']), 403, 'This checklist is not available for updating.');
            if ($template['role_id'] !== null) {
                abort_unless($reads->query($actor->roles()->getQuery()->where('roles.id', $template['role_id']))->exists(), 403, 'The checklist requires its configured responsible role.');
            }
            abort_if($referral->stage === 'closed' || ! ReferralAssessment::scope($referral, 'collect'), 422, 'Review collection authority and the open referral before recording preparation.');
            $item = collect($template['items'])->first(fn ($item) => $item['id'] === $data['item_id'] && $item['enabled']);
            abort_unless($item, 422, 'This checklist item is no longer available.');
            abort_if($data['completed'] && $this->complete($referral, $template, $item), 409, 'This checklist step is already recorded as complete.');
            $reviews = $referral->intake['checklist_reviews'] ?? [];
            $previous = $reviews[$template['id']][$item['id']] ?? null;
            $decision = ['completed' => (bool) $data['completed'], 'note' => $data['note'], 'recorded_at' => now()->toISOString(), 'recorded_by' => $actor->id, 'recorded_by_name' => $actor->name,
                'settings_version_id' => $referral->settings_version_id, 'template_label' => $template['label'], 'item' => $item, 'signature' => $this->signature($template, $item)];
            $reviews[$template['id']][$item['id']] = $decision;
            $referral->intake = [...$referral->intake, 'checklist_reviews' => $reviews];
            $command->record($referral, $actor, 'checklist.review_recorded', $template['audience'], ['template_id' => $template['id'], 'item_id' => $item['id'], 'previous' => $previous, 'decision' => $decision]);
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'checklist');
        });
    }
}

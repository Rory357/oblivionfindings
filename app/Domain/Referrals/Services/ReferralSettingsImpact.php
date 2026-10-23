<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Read-only impact within the reviewer's current record and audience permissions. */
final class ReferralSettingsImpact
{
    public function inspect(User $actor, ReferralSettingsVersion $version): array
    {
        $base = $version->base_version_id
            ? ReferralSettingsVersion::query()->findOrFail($version->base_version_id)->configuration
            : ReferralSettings::defaults();

        return $this->inspectConfiguration($actor, $version->configuration, $base, $version->id);
    }

    /**
     * Counts and plain-language change lines for a configuration compared with the one it replaces.
     * A preview has no version yet, so every open referral keeps its current settings.
     */
    public function inspectConfiguration(User $actor, array $configuration, array $base, ?int $versionId = null): array
    {
        $access = app(ReferralAccess::class);
        $open = $access->query($actor)->whereNotIn('stage', ['closed', 'converted']);
        $services = collect($configuration['services'])->where('enabled', true)->pluck('id')->all();
        $pathways = collect($configuration['pathways'])->where('enabled', true)->pluck('id')->all();
        $funding = $access->can($actor, 'funding.view')
            ? ReferralFundingCase::query()->whereIn('referral_id', $access->query($actor)->select('care_referrals.id'))
                ->whereNotIn('status', ['closed', 'withdrawn'])
            : null;

        return [
            'checked_at' => now()->toISOString(),
            'version_id' => $versionId,
            'scope' => 'Only records you can currently view. These are not organisation-wide totals.',
            'open_referrals' => (clone $open)->count(),
            'without_owner' => (clone $open)->whereNull('owner_id')->count(),
            'outside_enabled_services' => (clone $open)->where('general_enquiry', false)->whereNotIn('requested_service', $services)->count(),
            'retaining_another_version' => $versionId ? (clone $open)->where('settings_version_id', '!=', $versionId)->count() : (clone $open)->count(),
            'funding_outside_enabled_pathways' => $funding ? (clone $funding)->whereNotIn('pathway', $pathways)->count() : null,
            'intake_team' => $configuration['intake']['team'],
            'enabled_services' => collect($configuration['services'])->where('enabled', true)->pluck('label')->values()->all(),
            'public_enabled' => $configuration['public']['enabled'],
            'notice_version' => $configuration['public']['notice_version'],
            'lines' => $this->lines($base, $configuration, $open, $funding),
        ];
    }

    /**
     * What changes for whom. `loosens` marks a weaker safeguard (longer targets, fewer preparation steps,
     * wider service settings, higher approval limits); those changes need governance approval.
     *
     * @return list<array{area: string, affects: string, text: string, loosens: bool}>
     */
    public function lines(array $before, array $after, ?Builder $open = null, ?Builder $funding = null): array
    {
        $lines = [];
        $add = function (string $area, string $affects, string $text, bool $loosens = false) use (&$lines): void {
            $lines[] = ['area' => $area, 'affects' => $affects, 'text' => $text, 'loosens' => $loosens];
        };
        $count = fn (?Builder $query, string $column, string $value): int => $query ? (clone $query)->where($column, $value)->count() : 0;
        $plural = fn (int $n, string $word): string => $n.' '.$word.($n === 1 ? '' : 's');

        // Website and intake
        [$b, $a] = [$before['public'], $after['public']];
        if ($b['enabled'] !== $a['enabled']) {
            $add('intake', 'People enquiring online', $a['enabled']
                ? 'The website starts accepting enquiries.'
                : 'The website stops accepting new enquiries. Staff can still record phone and agency referrals.');
        }
        if ($b['notice'] !== $a['notice'] || $b['notice_version'] !== $a['notice_version']) {
            $add('intake', 'People enquiring online', 'People read information collection notice “'.$a['notice_version'].'” before they send an enquiry.');
        }
        if ($b['provider_name'] !== $a['provider_name'] || $b['contact_phone'] !== $a['contact_phone'] || $b['response_hours'] !== $a['response_hours']) {
            $add('intake', 'People enquiring online', 'The website shows updated provider name, phone or response hours.');
        }
        foreach (ReferralSettings::PUBLIC_CONTACT_DEFAULTS as $key => $blank) {
            if (trim((string) ($b[$key] ?? $blank)) !== trim((string) ($a[$key] ?? $blank))) {
                $add('intake', 'People visiting the website', 'The Contact us page shows updated office address, email or opening hours.');
                break;
            }
        }
        if ($b['acknowledgement_enabled'] !== $a['acknowledgement_enabled']) {
            $add('intake', 'People who choose email contact', $a['acknowledgement_enabled']
                ? 'People who choose email are sent a receipt with their reference.'
                : 'Email receipts stop. People still see their reference on screen when they send an enquiry.');
        }
        $previous = array_column($before['services'], null, 'id');
        foreach ($after['services'] as $service) {
            $old = $previous[$service['id']] ?? null;
            $name = '“'.$service['label'].'”';
            if (! $old) {
                $add('intake', 'People enquiring and intake staff', $name.($service['enabled'] ? ' is added and offered for new enquiries.' : ' is added, turned off.'));

                continue;
            }
            if ($old['enabled'] && ! $service['enabled']) {
                $n = $count($open, 'requested_service', $service['id']);
                $add('intake', 'People enquiring and intake staff', $name.' is no longer offered for new enquiries.'.($n ? ' '.$plural($n, 'open referral').' for it keep their current settings.' : ''));
            } elseif (! $old['enabled'] && $service['enabled']) {
                $add('intake', 'People enquiring and intake staff', $name.' is offered for new enquiries again.');
            }
            if ($old['label'] !== $service['label']) {
                $add('intake', 'Everyone using Referrals', '“'.$old['label'].'” is renamed '.$name.'.');
            }
            $from = $old['context_types'] ?? null;
            $to = $service['context_types'] ?? null;
            if ($from !== $to) {
                $wider = $from !== null && ($to === null || array_diff($to, $from) !== []);
                $add('intake', 'Staff preparing support', 'The kinds of setting '.$name.' can start in change'.($wider ? ' and now include more options.' : '.'), $wider);
            }
        }
        $fields = fn (array $configuration) => array_column($configuration['forms']['fields'] ?? [], null, 'id');
        [$oldFields, $newFields] = [$fields($before), $fields($after)];
        $added = count(array_diff_key($newFields, $oldFields));
        $archived = count(array_filter($newFields, fn ($field, $id) => isset($oldFields[$id]) && $oldFields[$id]['enabled'] && ! $field['enabled'], ARRAY_FILTER_USE_BOTH));
        $edited = count(array_filter($newFields, fn ($field, $id) => isset($oldFields[$id]) && $oldFields[$id] !== $field && ($oldFields[$id]['enabled'] === $field['enabled']), ARRAY_FILTER_USE_BOTH));
        if ($added || $archived || $edited) {
            $add('intake', 'People enquiring and staff recording intake', 'Intake questions change: '.implode(', ', array_filter([
                $added ? $plural($added, 'question').' added' : null, $archived ? $plural($archived, 'question').' archived' : null, $edited ? $plural($edited, 'question').' edited' : null,
            ])).'. Earlier answers are kept.');
        }
        if (($before['document_requests'] ?? ReferralDocumentRequests::defaults()) !== ($after['document_requests'] ?? ReferralDocumentRequests::defaults())) {
            $docs = $after['document_requests'] ?? ReferralDocumentRequests::defaults();
            $old = $before['document_requests'] ?? ReferralDocumentRequests::defaults();
            $add('intake', 'Staff requesting documents and people uploading them', $docs['enabled']
                ? 'Secure document request links can be sent, open for up to '.$plural($docs['maximum_days'], 'day').'.'
                : 'Staff can no longer send secure document request links. Links already sent keep working until they expire.',
                $docs['enabled'] && $old['enabled'] && $docs['maximum_days'] > $old['maximum_days']);
        }

        // Workflow
        [$b, $a] = [$before['intake'], $after['intake']];
        if ($b['team'] !== $a['team']) {
            $add('workflow', 'Intake staff', 'Unassigned work belongs to “'.$a['team'].'” instead of “'.$b['team'].'”.');
        }
        if ($b['first_contact_business_days'] !== $a['first_contact_business_days']) {
            $later = $a['first_contact_business_days'] > $b['first_contact_business_days'];
            $add('workflow', 'People waiting for first contact', 'New referrals are due for first contact within '.$plural($a['first_contact_business_days'], 'working day').' instead of '.$b['first_contact_business_days'].'.', $later);
        }
        if ($b['hold_review_business_days'] !== $a['hold_review_business_days']) {
            $add('workflow', 'Owners of referrals on hold', 'The waiting-case review target changes to '.$plural($a['hold_review_business_days'], 'working day').'.', $a['hold_review_business_days'] > $b['hold_review_business_days']);
        }
        [$b, $a] = [$before['follow_up'] ?? ReferralFollowUpPolicy::defaults(), $after['follow_up'] ?? ReferralFollowUpPolicy::defaults()];
        if ($b !== $a) {
            $add('workflow', 'Owners and managers', 'Overdue follow-ups go to a manager after '.$plural($a['manager_after_days'], $a['day_basis'] === 'business' ? 'working day' : 'day')
                .($a['office_hours_only'] ? ', and reminders wait for opening hours.' : '.'),
                $a['manager_after_days'] > $b['manager_after_days'] || ($a['day_basis'] === 'business' && $b['day_basis'] === 'calendar') || ($a['office_hours_only'] && ! $b['office_hours_only']));
        }
        if ($before['business_calendar'] !== $after['business_calendar']) {
            $add('workflow', 'Everyone with due dates', 'Working days, opening hours or non-working dates change. New due dates use the new calendar; recorded due dates stay.');
        }
        $labels = array_column($before['stages'], 'label', 'id');
        foreach ($after['stages'] as $stage) {
            if (isset($labels[$stage['id']]) && $labels[$stage['id']] !== $stage['label']) {
                $add('workflow', 'Everyone using Referrals', 'The “'.$labels[$stage['id']].'” stage is shown as “'.$stage['label'].'”.');
            }
        }
        if (($before['workflow'] ?? ReferralWorkflow::defaults()) !== ($after['workflow'] ?? ReferralWorkflow::defaults())) {
            $add('workflow', 'Staff moving referrals between stages', 'Allowed stage changes or their required checks change for referrals that start on this version.');
        }
        if (($before['checklists'] ?? []) !== ($after['checklists'] ?? [])) {
            $add('workflow', 'Staff completing checklists', 'Checklist templates change for referrals that start on this version. Completed steps stay recorded.');
        }
        $routing = ReferralSettings::defaults()['routing'];
        [$b, $a] = [$before['routing'] ?? $routing, $after['routing'] ?? $routing];
        if ($b['enabled'] !== $a['enabled']) {
            $add('workflow', 'Staff who receive new enquiries', $a['enabled'] ? 'Website enquiries are assigned automatically using the routing rules.' : 'Website enquiries wait in central intake for someone to assign them.');
        } elseif ($b !== $a) {
            $add('workflow', 'Staff who receive new enquiries', 'Routing rules or fallback owners change for new website enquiries.');
        }
        $groups = ['hold' => ['Hold reasons', 'Staff placing referrals on hold'], 'close' => ['Close reasons', 'Staff closing referrals'], 'priority' => ['Priorities', 'Staff sorting referrals']];
        foreach ($groups as $list => [$title, $affects]) {
            $old = array_column(ReferralSettings::choices($before, $list), null, 'id');
            $new = ReferralSettings::choices($after, $list);
            $notes = [];
            foreach ($new as $item) {
                $was = $old[$item['id']] ?? null;
                if (! $was) {
                    $notes[] = '“'.$item['label'].'” added';
                } elseif ($was['enabled'] && ! $item['enabled']) {
                    $notes[] = '“'.$item['label'].'” turned off';
                } elseif (! $was['enabled'] && $item['enabled']) {
                    $notes[] = '“'.$item['label'].'” turned on';
                }
                if ($was && $was['label'] !== $item['label']) {
                    $notes[] = '“'.$was['label'].'” renamed “'.$item['label'].'”';
                }
            }
            if ($notes !== []) {
                $add('workflow', $affects, $title.': '.implode('; ', $notes).'. Referrals already using a choice keep it.');
            }
        }
        // Staff message templates. Contact safety is checked on every send, so wording changes never loosen it.
        if (($messages = ReferralMessageTemplates::changes($before, $after)) !== []) {
            $add('workflow', 'Staff sending messages to people', 'Message templates: '.implode('; ', $messages).'. Messages already queued or sent keep their wording.');
        }

        // Funding
        $previous = array_column($before['pathways'], null, 'id');
        foreach ($after['pathways'] as $pathway) {
            $old = $previous[$pathway['id']] ?? null;
            $name = '“'.$pathway['label'].'”';
            if (! $old || (! $old['enabled'] && $pathway['enabled'])) {
                if ($pathway['enabled'] || ! $old) {
                    $add('funding', 'Staff preparing funding', $name.($pathway['enabled'] ? ' becomes available for new funding cases.' : ' is added, turned off.'), $pathway['enabled']);
                }

                continue;
            }
            if ($old['enabled'] && ! $pathway['enabled']) {
                $n = $count($funding, 'pathway', $pathway['id']);
                $add('funding', 'Staff preparing funding', $name.' is no longer offered for new funding cases.'.($n ? ' '.$plural($n, 'open funding case').' using it carry on as recorded.' : ''));
            }
            if ($old['label'] !== $pathway['label']) {
                $add('funding', 'Staff preparing funding', '“'.$old['label'].'” is renamed '.$name.'.');
            }
            $removed = array_values(array_diff($old['evidence_checklist'], $pathway['evidence_checklist']));
            $addedSteps = array_values(array_diff($pathway['evidence_checklist'], $old['evidence_checklist']));
            if ($addedSteps !== []) {
                $add('funding', 'Staff preparing funding', $name.' adds preparation steps for new cases: '.implode('; ', $addedSteps).'.');
            }
            if ($removed !== []) {
                $add('funding', 'Staff preparing funding', $name.' no longer asks for: '.implode('; ', $removed).'.', true);
            }
            if (array_diff($pathway['service_ids'], $old['service_ids']) !== [] || $old['personal_income'] !== $pathway['personal_income']) {
                $add('funding', 'Staff preparing funding', $name.' can now be used for more services or changes its income-support purpose.', true);
            }
            [$wasPayer, $payer] = [ReferralSettings::payerKind($old), ReferralSettings::payerKind($pathway)];
            if ($wasPayer !== $payer) {
                $add('funding', 'Staff preparing funding', $payer === 'private'
                    ? 'New cases on '.$name.' are paid by the person or their family. They skip funder verification and use the person’s agreement instead.'
                    : 'New cases on '.$name.' are paid by an external funder and need the funder’s decision verified.', $payer === 'private');
            }
            if (($old['contact'] ?? null) !== ($pathway['contact'] ?? null)) {
                $add('funding', 'Staff preparing funding', 'Contact details for '.$name.' change. Staff see them on funding work; nothing is sent automatically.');
            }
            if ([$old['source_url'], $old['verified_on'], $old['review_on']] !== [$pathway['source_url'], $pathway['verified_on'], $pathway['review_on']]) {
                $add('funding', 'Staff preparing funding', 'Guidance link or review dates for '.$name.' change.');
            }
        }
        [$b, $a] = [$before['proposals'], $after['proposals']];
        if ($b['approval_limit_cents'] !== $a['approval_limit_cents']) {
            $add('funding', 'Proposal approvers', 'The general weekly approval limit changes to $'.number_format($a['approval_limit_cents'] / 100, 2).' NZD.', $a['approval_limit_cents'] > $b['approval_limit_cents']);
        }
        if (($b['approval_limits'] ?? []) !== ($a['approval_limits'] ?? [])) {
            $raised = collect($a['approval_limits'] ?? [])->contains(fn ($limit) => ! collect($b['approval_limits'] ?? [])->contains(
                fn ($old) => [$old['currency'], $old['period'], $old['role_id']] === [$limit['currency'], $limit['period'], $limit['role_id']] && $old['limit_cents'] >= $limit['limit_cents']));
            $add('funding', 'Proposal approvers', 'Approval delegations by role change.', $raised);
        }
        if ($b['temporary_funding_enabled'] !== $a['temporary_funding_enabled']) {
            $add('funding', 'Proposal approvers', $a['temporary_funding_enabled']
                ? 'Temporary company funding can be proposed, with a named payer, cap and independent approval.'
                : 'Temporary company funding can no longer be proposed. Arrangements already approved stay recorded.', $a['temporary_funding_enabled']);
        }

        return $lines;
    }
}

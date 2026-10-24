<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralConfiguration;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Enums\ServiceType;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReferralSettings
{
    public const STAGES = ['new', 'triage', 'assessment', 'funding_assistance', 'ready_for_handover', 'on_hold', 'converted', 'closed'];

    /** Configurable choice lists. Built-in keys are stable references: relabel or turn off, never remove. */
    public const CHOICE_LISTS = ['hold', 'close', 'priority'];

    public const CHOICE_LABELS = [
        'hold' => [
            'awaiting_person' => 'Waiting to hear from the person or whānau',
            'awaiting_evidence' => 'Waiting for documents or evidence',
            'awaiting_assessment' => 'Waiting for an assessment',
            'awaiting_funding' => 'Waiting for a funding decision',
            'capacity_waitlist' => 'On the waitlist for a place',
            'person_requested_pause' => 'Paused at the person’s request',
        ],
        'close' => [
            'information_only' => 'Information only',
            'person_withdrew' => 'The person withdrew',
            'another_provider' => 'Chose another provider',
            'outside_scope' => 'Service unavailable or outside scope',
            'unable_to_contact' => 'Unable to make contact',
            'duplicate' => 'Duplicate referral',
            'test' => 'Test entry',
            'spam' => 'Spam',
            'other' => 'Other documented reason',
        ],
        'priority' => [
            'urgent' => 'Urgent',
            'high' => 'High',
            'standard' => 'Standard',
        ],
    ];

    /** Always available: duplicate review and the documented fallback close, and every referral's default priority. */
    public const PROTECTED_CHOICES = ['hold' => [], 'close' => ['duplicate', 'other'], 'priority' => ['standard']];

    /** Public Contact us details (office address, contact email, opening hours); older versions read as blank. */
    public const PUBLIC_CONTACT_DEFAULTS = ['office_address' => '', 'contact_email' => '', 'opening_hours' => ''];

    public static function defaults(): array
    {
        return [
            'public' => [
                'enabled' => false,
                'provider_name' => 'Oblivion Care',
                'contact_phone' => '',
                'response_hours' => 'Monday to Friday, during office hours',
                'notice_version' => 'draft',
                'notice' => '',
                'acknowledgement_enabled' => false,
                // Optional details for the public Contact us page; blank ones are simply not shown.
                ...self::PUBLIC_CONTACT_DEFAULTS,
            ],
            'services' => [['id' => 'supported_living', 'label' => 'Supported living', 'enabled' => true]],
            'intake' => ['team' => 'Central intake', 'first_contact_business_days' => 2, 'hold_review_business_days' => 5],
            'follow_up' => ReferralFollowUpPolicy::defaults(),
            'document_requests' => ReferralDocumentRequests::defaults(),
            'routing' => ['enabled' => false, 'fallback_owner_ids' => [], 'fallback_max_open' => 50, 'rules' => []],
            'workflow' => ReferralWorkflow::defaults(),
            'forms' => ['fields' => []],
            'checklists' => [],
            'business_calendar' => ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => [], 'opening_time' => '09:00', 'closing_time' => '17:00'],
            'stages' => array_map(fn ($id) => ['id' => $id, 'label' => ucfirst(str_replace('_', ' ', $id))], self::STAGES),
            'pathways' => array_map(fn ($id, $label) => [
                'id' => $id, 'label' => $label, 'enabled' => true,
                'personal_income' => $id === 'personal_income',
                'service_ids' => $id === 'personal_income' ? [] : ['supported_living'],
                'evidence_checklist' => [], 'source_url' => null, 'verified_on' => null, 'review_on' => null,
                'payer_kind' => $id === 'private' ? 'private' : 'external',
            ], ['dss_nasc', 'egl', 'individualised_funding', 'acc', 'health_nz', 'private', 'insurance', 'charitable', 'other', 'personal_income'],
                ['DSS / NASC', 'Enabling Good Lives', 'Individualised Funding', 'ACC', 'Health New Zealand', 'Private payment', 'Insurance', 'Charitable assistance', 'Other grant or contract', 'Personal income assistance']),
            'proposals' => ['approval_limit_cents' => 0, 'approval_limits' => [], 'temporary_funding_enabled' => false],
            'choices' => array_combine(self::CHOICE_LISTS, array_map(fn (string $list) => array_map(
                fn (string $id) => ['id' => $id, 'label' => self::CHOICE_LABELS[$list][$id] ?? ucfirst(str_replace('_', ' ', $id)), 'enabled' => true],
                self::builtInChoices($list),
            ), self::CHOICE_LISTS)),
            // Staff message templates (safe merge fields only); see ReferralMessageTemplates.
            'communications' => ReferralMessageTemplates::defaults(),
        ];
    }

    /** Built-in keys for a choice list. Hold and close keys follow the lifecycle's stable references. */
    public static function builtInChoices(string $list): array
    {
        return match ($list) {
            'hold' => ReferralLifecycle::HOLD_REASONS,
            'close' => ReferralLifecycle::CLOSE_REASONS,
            'priority' => array_keys(self::CHOICE_LABELS['priority']),
        };
    }

    /**
     * Read method for hold reasons, close reasons and priorities of a published configuration. Versions
     * published before these lists were configurable keep the built-in labels, all available.
     *
     * @return list<array{id: string, label: string, enabled: bool, built_in: bool, protected: bool}>
     */
    public static function choices(?array $configuration, string $list): array
    {
        abort_unless(in_array($list, self::CHOICE_LISTS, true), 500, 'Unknown referral choice list.');
        $configured = $configuration['choices'][$list] ?? [];
        $builtIn = self::builtInChoices($list);
        $items = [];
        foreach ($configured as $item) {
            $items[$item['id']] = ['id' => $item['id'], 'label' => $item['label'], 'enabled' => (bool) $item['enabled']];
        }
        foreach ($builtIn as $id) {
            $items[$id] ??= ['id' => $id, 'label' => self::CHOICE_LABELS[$list][$id] ?? ucfirst(str_replace('_', ' ', $id)), 'enabled' => true];
        }
        $protected = self::PROTECTED_CHOICES[$list];

        return array_values(array_map(fn (array $item) => [...$item, 'enabled' => $item['enabled'] || in_array($item['id'], $protected, true),
            'built_in' => in_array($item['id'], $builtIn, true), 'protected' => in_array($item['id'], $protected, true)], $items));
    }

    /** Keys staff may choose for new holds, closures or priorities under a configuration. */
    public static function enabledChoiceKeys(?array $configuration, string $list): array
    {
        return array_values(array_column(array_filter(self::choices($configuration, $list), fn (array $item) => $item['enabled']), 'id'));
    }

    /** The configured label for a recorded key; unknown historical keys keep a readable fallback. */
    public static function choiceLabel(?array $configuration, string $list, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return collect(self::choices($configuration, $list))->firstWhere('id', $key)['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Who pays under a pathway: an external funder (verified decision) or the person or their family
     * (a private agreement). Pathways saved before this was configurable keep the template rule.
     */
    public static function payerKind(array $pathway): string
    {
        return $pathway['payer_kind'] ?? (($pathway['id'] ?? null) === 'private' ? 'private' : 'external');
    }

    /** Fills settings that older publications did not store, so every version reads the same way. */
    public static function presentable(array $configuration): array
    {
        foreach (self::PUBLIC_CONTACT_DEFAULTS as $key => $blank) {
            $configuration['public'][$key] ??= $blank;
        }
        $configuration['choices'] = array_combine(self::CHOICE_LISTS, array_map(
            fn (string $list) => array_map(fn (array $item) => ['id' => $item['id'], 'label' => $item['label'], 'enabled' => $item['enabled']], self::choices($configuration, $list)),
            self::CHOICE_LISTS,
        ));
        $configuration['pathways'] = array_map(fn (array $pathway) => [...$pathway, 'payer_kind' => self::payerKind($pathway)], $configuration['pathways'] ?? []);
        $configuration['communications'] = ['templates' => ReferralMessageTemplates::templates($configuration)];

        return $configuration;
    }

    /** Defaults are drafts; enabling public intake always requires a deliberate publication. */
    public function draft(User $actor, array $configuration, ?int $baseVersionId, string $reason, ?string $requestKey = null, ?int $restoredFrom = null): ReferralSettingsVersion
    {
        $configuration = $this->validate($configuration);
        $requestKey ??= (string) Str::uuid();
        Validator::make(['reason' => $reason, 'request_key' => $requestKey], ['reason' => 'required|string|min:5|max:2000', 'request_key' => 'required|uuid'])->validate();
        $hash = hash('sha256', json_encode($restoredFrom === null ? [$configuration, $baseVersionId, $reason] : [$configuration, $baseVersionId, $reason, $restoredFrom], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $configuration, $baseVersionId, $reason, $requestKey, $hash, $restoredFrom): ReferralSettingsVersion {
            $pointer = $this->lockPointer();
            $actor = $this->actor($actor, ['settings.manage']);
            if ($existing = ReferralSettingsVersion::query()->where('request_key', $requestKey)->lockForUpdate()->first()) {
                abort_unless((int) $existing->created_by === (int) $actor->id && hash_equals($existing->request_hash, $hash), 409, 'This request was already used for different settings. Review the saved draft.');

                return $existing;
            }
            abort_unless($pointer->published_version_id === $baseVersionId, 409, 'Settings have changed. Compare with the latest published version.');
            if ($baseVersionId) {
                $base = ReferralSettingsVersion::findOrFail($baseVersionId)->configuration;
                app(ReferralForms::class)->stableReferences($base, $configuration);
                app(ReferralChecklists::class)->stableReferences($base, $configuration);
                foreach (['services', 'pathways'] as $collection) {
                    if (array_diff(array_column($base[$collection], 'id'), array_column($configuration[$collection], 'id')) !== []) {
                        throw ValidationException::withMessages(['configuration.'.$collection => 'Keep published references stable. Disable an option instead of removing or renaming its reference.']);
                    }
                }
                foreach (self::CHOICE_LISTS as $list) {
                    if (array_diff(array_column(self::choices($base, $list), 'id'), array_column(self::choices($configuration, $list), 'id')) !== []) {
                        throw ValidationException::withMessages(['configuration.choices.'.$list => 'Keep published reasons and priorities. Turn one off instead of removing it, because referrals may already use it.']);
                    }
                }
                ReferralMessageTemplates::stableReferences($base, $configuration);
                if ($base['public']['notice'] !== $configuration['public']['notice'] && $base['public']['notice_version'] === $configuration['public']['notice_version']) {
                    throw ValidationException::withMessages(['configuration.public.notice_version' => 'Use a new notice version when changing the information collection notice.']);
                }
            }
            if ($restoredFrom !== null) {
                abort_unless(ReferralSettingsVersion::query()->whereKey($restoredFrom)->where('state', 'published')->exists(), 422, 'Only a published version can be restored.');
            }
            $version = ReferralSettingsVersion::query()->create([
                'version' => (int) ReferralSettingsVersion::query()->orderByDesc('version')->lockForUpdate()->value('version') + 1,
                'state' => 'draft',
                'base_version_id' => $baseVersionId,
                'restored_from_version_id' => $restoredFrom,
                'configuration' => $configuration,
                'reason' => $reason,
                'created_by' => $actor->id,
                'request_key' => $requestKey,
                'request_hash' => $hash,
            ]);
            $this->record($version, $actor, 'settings.drafted');
            if ($restoredFrom !== null) {
                $this->record($version, $actor, 'settings.restored', ['restored_from_version_id' => $restoredFrom,
                    'restored_from_version' => ReferralSettingsVersion::query()->whereKey($restoredFrom)->value('version')]);
            }

            return $version;
        }, 3);
    }

    /**
     * Starts a new draft from an earlier publication. History is never edited: options published since then
     * are carried into the draft turned off, because published references must stay stable.
     */
    public function restore(User $actor, int $sourceId, string $reason, string $requestKey): ReferralSettingsVersion
    {
        $source = ReferralSettingsVersion::query()->findOrFail($sourceId);
        abort_unless($source->state === 'published', 422, 'Only a published version can be restored.');
        $pointerId = ReferralConfiguration::query()->whereKey(1)->value('published_version_id');
        abort_if($pointerId !== null && (int) $pointerId === (int) $source->id, 422, 'This version is already the latest publication.');
        $current = $pointerId ? ReferralSettingsVersion::query()->findOrFail($pointerId)->configuration : null;
        $configuration = $current ? $this->restorable($current, $source->configuration) : $source->configuration;

        return $this->draft($actor, $configuration, $pointerId ? (int) $pointerId : null, $reason, $requestKey, $source->id);
    }

    /** The restored configuration with every later stable reference kept, turned off. */
    public function restorable(array $current, array $restored): array
    {
        foreach (['services', 'pathways'] as $collection) {
            $known = array_column($restored[$collection], 'id');
            foreach ($current[$collection] as $item) {
                if (! in_array($item['id'], $known, true)) {
                    $restored[$collection][] = [...$item, 'enabled' => false];
                }
            }
        }
        $fields = $restored['forms']['fields'] ?? [];
        $known = array_column($fields, 'id');
        foreach ($current['forms']['fields'] ?? [] as $field) {
            if (! in_array($field['id'], $known, true)) {
                $fields[] = [...$field, 'enabled' => false];
            }
        }
        if ($fields !== [] || isset($restored['forms'])) {
            $restored['forms'] = ['fields' => $fields];
        }
        $templates = array_column($restored['checklists'] ?? [], null, 'id');
        foreach ($current['checklists'] ?? [] as $template) {
            if (! isset($templates[$template['id']])) {
                $templates[$template['id']] = [...$template, 'enabled' => false];

                continue;
            }
            $items = array_column($templates[$template['id']]['items'], 'id');
            foreach ($template['items'] as $item) {
                if (! in_array($item['id'], $items, true)) {
                    $templates[$template['id']]['items'][] = [...$item, 'enabled' => false];
                }
            }
        }
        if ($templates !== [] || isset($restored['checklists'])) {
            $restored['checklists'] = array_values($templates);
        }
        $restored['communications'] = ReferralMessageTemplates::restorable($current, $restored);
        $restored = self::presentable($restored);
        foreach (self::CHOICE_LISTS as $list) {
            $known = array_column($restored['choices'][$list], 'id');
            foreach (self::choices($current, $list) as $item) {
                if (! in_array($item['id'], $known, true)) {
                    $restored['choices'][$list][] = ['id' => $item['id'], 'label' => $item['label'], 'enabled' => false];
                }
            }
        }

        return $restored;
    }

    /** An unpublished draft can be set aside; it stays in history with who discarded it and why. */
    public function discard(User $actor, int $versionId, string $reason): ReferralSettingsVersion
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $versionId, $reason): ReferralSettingsVersion {
            $this->lockPointer();
            $version = ReferralSettingsVersion::query()->lockForUpdate()->findOrFail($versionId);
            $actor = $this->actor($actor, ['settings.manage', 'settings.publish']);
            if ($version->state === 'discarded' && (int) $version->discarded_by === (int) $actor->id) {
                return $version;
            }
            abort_unless($version->state === 'draft', 409, 'Only an unpublished draft can be discarded.');
            $version->update(['state' => 'discarded', 'discarded_by' => $actor->id, 'discarded_at' => now(), 'discard_reason' => $reason]);
            $this->record($version, $actor, 'settings.discarded');

            return $version;
        }, 3);
    }

    public function publish(User $actor, int $versionId, string $effectiveAt, ?string $note = null): ReferralSettingsVersion
    {
        Validator::make(['effective_at' => $effectiveAt, 'note' => $note], ['effective_at' => 'required|date', 'note' => 'nullable|string|min:5|max:2000'])->validate();
        $effectiveAt = CarbonImmutable::parse($effectiveAt)->utc()->startOfSecond()->toDateTimeString();

        return DB::transaction(function () use ($actor, $versionId, $effectiveAt, $note): ReferralSettingsVersion {
            $pointer = $this->lockPointer();
            $version = ReferralSettingsVersion::query()->lockForUpdate()->findOrFail($versionId);
            $actor = $this->actor($actor, ['settings.publish']);
            $configuration = $this->validate($version->configuration);
            $base = $version->base_version_id ? ReferralSettingsVersion::query()->findOrFail($version->base_version_id)->configuration : self::defaults();
            app(ReferralForms::class)->stableReferences($base, $configuration);
            app(ReferralChecklists::class)->stableReferences($base, $configuration);
            ReferralMessageTemplates::stableReferences($base, $configuration);
            if ($this->requiresGovernance($base, $configuration)) {
                abort_unless(app(ReferralAccess::class)->can($actor, 'settings.governance'), 403, 'This change requires settings governance approval.');
            }
            if ($version->state === 'published' && (int) $version->published_by === (int) $actor->id && $version->effective_at->equalTo($effectiveAt)) {
                return $version;
            }
            abort_unless($version->state === 'draft', 409, $version->state === 'discarded'
                ? 'This draft was discarded. Start a new draft from the current settings.' : 'This version has already been published.');
            abort_unless($pointer->published_version_id === $version->base_version_id, 409, 'This draft is based on an older publication. Create an updated draft.');
            abort_if((int) $version->created_by === (int) $actor->id, 422, 'Another authorized staff member must review and publish this draft.');
            $version->update(['state' => 'published', 'published_by' => $actor->id, 'published_at' => now(), 'effective_at' => $effectiveAt, 'publication_note' => $note]);
            $pointer->update(['published_version_id' => $version->id, 'lock_version' => $pointer->lock_version + 1]);
            $this->record($version, $actor, 'settings.published');

            return $version;
        }, 3);
    }

    /** Scheduled publications do not replace the effective intake version early. */
    public function effective(?CurrentAuthorizationReads $reads = null): ?ReferralSettingsVersion
    {
        $query = ReferralSettingsVersion::query()->where('state', 'published')
            ->where('effective_at', '<=', now())->orderByDesc('version');

        return ($reads ? $reads->query($query) : $query)->first();
    }

    public function validate(array $configuration): array
    {
        $keys = array_keys(self::defaults());
        $services = is_array($configuration['services'] ?? null) ? array_filter($configuration['services'], 'is_array') : [];
        $pathways = is_array($configuration['pathways'] ?? null) ? array_filter($configuration['pathways'], 'is_array') : [];
        $data = Validator::make(['configuration' => $configuration], [
            'configuration' => ['required', 'array:'.implode(',', $keys)],
            'configuration.public' => 'required|array:enabled,provider_name,contact_phone,response_hours,notice_version,notice,acknowledgement_enabled,office_address,contact_email,opening_hours',
            // Shown on the public Contact us page only when filled in.
            'configuration.public.office_address' => 'sometimes|nullable|string|max:300',
            'configuration.public.contact_email' => 'sometimes|nullable|email:rfc|max:254',
            'configuration.public.opening_hours' => 'sometimes|nullable|string|max:200',
            'configuration.public.enabled' => 'required|boolean',
            'configuration.public.provider_name' => 'required|string|max:120',
            'configuration.public.contact_phone' => 'present|nullable|string|max:80',
            'configuration.public.response_hours' => 'required|string|max:300',
            'configuration.public.notice_version' => 'required|string|max:80',
            'configuration.public.notice' => 'present|nullable|string|max:8000',
            'configuration.public.acknowledgement_enabled' => 'required|boolean',
            'configuration.services' => 'required|array|min:1|max:30',
            'configuration.services.*' => 'required|array:id,label,enabled,context_types',
            'configuration.services.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'configuration.services.*.label' => 'required|string|max:120',
            'configuration.services.*.enabled' => 'required|boolean',
            'configuration.services.*.context_types' => 'sometimes|nullable|array|min:1|max:30',
            'configuration.services.*.context_types.*' => ['required', 'string', Rule::in(array_column(ServiceType::cases(), 'value'))],
            'configuration.intake' => 'required|array:team,first_contact_business_days,hold_review_business_days',
            'configuration.forms' => 'sometimes|array:fields',
            'configuration.checklists' => 'sometimes|array|max:20',
            'configuration.forms.fields' => 'present_with:configuration.forms|array|max:40',
            'configuration.intake.team' => 'required|string|max:120',
            'configuration.follow_up' => 'sometimes|array:manager_after_days,day_basis,office_hours_only',
            'configuration.follow_up.manager_after_days' => 'required_with:configuration.follow_up|integer|min:1|max:30',
            'configuration.follow_up.day_basis' => ['required_with:configuration.follow_up', Rule::in(['calendar', 'business'])],
            'configuration.follow_up.office_hours_only' => 'required_with:configuration.follow_up|boolean',
            'configuration.document_requests' => 'sometimes|array:enabled,maximum_days',
            'configuration.document_requests.enabled' => 'required_with:configuration.document_requests|boolean',
            'configuration.document_requests.maximum_days' => 'required_with:configuration.document_requests|integer|min:1|max:14',
            'configuration.intake.first_contact_business_days' => 'required|integer|min:1|max:30',
            'configuration.intake.hold_review_business_days' => 'required|integer|min:1|max:90',
            'configuration.routing' => 'sometimes|array:enabled,fallback_owner_ids,fallback_max_open,rules',
            'configuration.routing.enabled' => 'required_with:configuration.routing|boolean',
            'configuration.routing.fallback_owner_ids' => 'present_with:configuration.routing|array|max:30',
            'configuration.routing.fallback_owner_ids.*' => 'required|integer|min:1|distinct|exists:users,id',
            'configuration.routing.fallback_max_open' => 'required_with:configuration.routing|integer|min:1|max:1000',
            'configuration.routing.rules' => 'present_with:configuration.routing|array|max:30',
            'configuration.routing.rules.*' => 'required|array:id,label,enabled,service_ids,regions,funding_situations,pathway_ids,site_id,team,owner_ids,max_open',
            'configuration.routing.rules.*.funding_situations' => 'sometimes|array|max:6',
            'configuration.routing.rules.*.funding_situations.*' => ['required', Rule::in(ReferralIntakeFunding::SITUATIONS)],
            'configuration.routing.rules.*.pathway_ids' => 'sometimes|array|max:40',
            'configuration.routing.rules.*.pathway_ids.*' => ['required', Rule::in(array_column($pathways, 'id'))],
            'configuration.routing.rules.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'configuration.routing.rules.*.label' => 'required|string|max:120',
            'configuration.routing.rules.*.enabled' => 'required|boolean',
            'configuration.routing.rules.*.service_ids' => 'present|array|max:30',
            'configuration.routing.rules.*.service_ids.*' => ['required', Rule::in([...array_column($services, 'id'), 'unsure'])],
            'configuration.routing.rules.*.regions' => 'present|array|max:30',
            'configuration.routing.rules.*.regions.*' => 'required|string|max:120',
            'configuration.routing.rules.*.site_id' => 'present|nullable|integer|exists:sites,id',
            'configuration.routing.rules.*.team' => 'required|string|max:120',
            'configuration.routing.rules.*.owner_ids' => 'required|array|min:1|max:30',
            'configuration.routing.rules.*.owner_ids.*' => 'required|integer|min:1|exists:users,id',
            'configuration.routing.rules.*.max_open' => 'required|integer|min:1|max:1000',
            'configuration.business_calendar' => 'required|array:timezone,weekdays,holidays,opening_time,closing_time',
            'configuration.business_calendar.timezone' => ['required', Rule::in(['Pacific/Auckland', 'Pacific/Chatham'])],
            'configuration.business_calendar.weekdays' => 'required|array|min:1|max:7',
            'configuration.business_calendar.weekdays.*' => 'required|integer|min:1|max:7|distinct',
            'configuration.business_calendar.holidays' => 'present|array|max:366',
            'configuration.business_calendar.holidays.*' => 'required|date_format:Y-m-d|distinct',
            'configuration.business_calendar.closing_time' => 'required|date_format:H:i',
            'configuration.business_calendar.opening_time' => 'sometimes|nullable|date_format:H:i',
            'configuration.stages' => 'required|array|size:8',
            'configuration.workflow' => 'sometimes|array:transitions',
            'configuration.workflow.transitions' => 'required_with:configuration.workflow|array|min:1|max:18',
            'configuration.workflow.transitions.*' => 'required|array:from,to,role_id,evidence',
            'configuration.workflow.transitions.*.from' => ['required', Rule::in(ReferralWorkflow::SOURCES)],
            'configuration.workflow.transitions.*.to' => ['required', Rule::in(ReferralLifecycle::WORK_STAGES)],
            'configuration.workflow.transitions.*.role_id' => 'present|nullable|integer|exists:roles,id',
            'configuration.workflow.transitions.*.evidence' => 'present|array|max:6',
            'configuration.workflow.transitions.*.evidence.*' => ['required', Rule::in(ReferralWorkflow::EVIDENCE)],
            'configuration.stages.*' => 'required|array:id,label',
            'configuration.stages.*.id' => ['required', 'distinct', Rule::in(self::STAGES)],
            'configuration.stages.*.label' => 'required|string|max:80',
            'configuration.pathways' => 'required|array|min:1|max:40',
            'configuration.pathways.*' => 'required|array:id,label,enabled,personal_income,service_ids,evidence_checklist,source_url,verified_on,review_on,contact,payer_kind',
            'configuration.pathways.*.payer_kind' => 'sometimes|in:external,private',
            // Directory details for staff only: displayed on funding work, never used to send anything.
            'configuration.pathways.*.contact' => 'sometimes|nullable|array:organisation,phone,email,website,region_notes',
            'configuration.pathways.*.contact.organisation' => 'nullable|string|max:160',
            'configuration.pathways.*.contact.phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+() \-]{5,40}$/'],
            'configuration.pathways.*.contact.email' => 'nullable|email|max:254',
            'configuration.pathways.*.contact.website' => 'nullable|url:https|max:2000',
            'configuration.pathways.*.contact.region_notes' => 'nullable|string|max:500',
            'configuration.choices' => 'sometimes|array:'.implode(',', self::CHOICE_LISTS),
            'configuration.choices.hold' => 'required_with:configuration.choices|array|min:1|max:30',
            'configuration.choices.close' => 'required_with:configuration.choices|array|min:1|max:30',
            'configuration.choices.priority' => 'required_with:configuration.choices|array|min:1|max:12',
            'configuration.choices.*.*' => 'required|array:id,label,enabled',
            'configuration.choices.*.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/',
            'configuration.choices.*.*.label' => 'required|string|max:120',
            'configuration.choices.*.*.enabled' => 'required|boolean',
            // Staff message templates: wording, merge fields and text length are checked by ReferralMessageTemplates.
            'configuration.communications' => 'sometimes|array:templates',
            'configuration.communications.templates' => 'required_with:configuration.communications|array|max:30',
            'configuration.communications.templates.*' => 'required|array:id,label,channel,audience,subject,body,enabled',
            'configuration.communications.templates.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'configuration.communications.templates.*.label' => 'required|string|max:120',
            'configuration.communications.templates.*.channel' => ['required', Rule::in(ReferralMessageTemplates::CHANNELS)],
            'configuration.communications.templates.*.audience' => ['required', Rule::in(ReferralMessageTemplates::AUDIENCES)],
            'configuration.communications.templates.*.subject' => 'present|nullable|string|max:'.ReferralMessageTemplates::EMAIL_SUBJECT_MAX,
            'configuration.communications.templates.*.body' => 'required|string|max:'.ReferralMessageTemplates::EMAIL_BODY_MAX,
            'configuration.communications.templates.*.enabled' => 'required|boolean',
            'configuration.pathways.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{1,63}$/|distinct',
            'configuration.pathways.*.label' => 'required|string|max:120',
            'configuration.pathways.*.enabled' => 'required|boolean',
            'configuration.pathways.*.personal_income' => 'required|boolean',
            'configuration.pathways.*.service_ids' => 'present|array|max:30',
            'configuration.pathways.*.service_ids.*' => ['required', Rule::in(array_column($services, 'id'))],
            'configuration.pathways.*.evidence_checklist' => 'present|array|max:40',
            'configuration.pathways.*.evidence_checklist.*' => 'required|string|max:200',
            'configuration.pathways.*.source_url' => 'present|nullable|url:https|max:2000',
            'configuration.pathways.*.verified_on' => 'present|nullable|date_format:Y-m-d',
            'configuration.pathways.*.review_on' => 'present|nullable|date_format:Y-m-d',
            'configuration.proposals' => 'required|array:approval_limit_cents,approval_limits,temporary_funding_enabled',
            'configuration.proposals.approval_limit_cents' => 'required|integer|min:0|max:1000000000',
            'configuration.proposals.approval_limits' => 'sometimes|array|max:50',
            'configuration.proposals.approval_limits.*' => 'required|array:currency,period,role_id,limit_cents',
            'configuration.proposals.approval_limits.*.currency' => 'required|string|regex:/^[A-Z]{3}$/',
            'configuration.proposals.approval_limits.*.period' => ['required', Rule::in(['week', 'fortnight', 'month', 'year', 'total'])],
            'configuration.proposals.approval_limits.*.role_id' => 'present|nullable|integer|exists:roles,id',
            'configuration.proposals.approval_limits.*.limit_cents' => 'required|integer|min:0|max:1000000000',
            'configuration.proposals.temporary_funding_enabled' => 'required|boolean',
        ])->validate()['configuration'];

        $data['business_calendar']['weekdays'] = array_map('intval', $data['business_calendar']['weekdays']);

        if ((($data['follow_up']['day_basis'] ?? 'calendar') === 'business' || ($data['follow_up']['office_hours_only'] ?? false))
            && empty($data['business_calendar']['opening_time'])) {
            throw ValidationException::withMessages(['configuration.business_calendar.opening_time' => 'Record opening time before using working-day escalation or office-hours-only reminders.']);
        }

        if (! empty($data['business_calendar']['opening_time']) && $data['business_calendar']['opening_time'] >= $data['business_calendar']['closing_time']) {
            throw ValidationException::withMessages(['configuration.business_calendar.closing_time' => 'Choose a closing time after the opening time. The working calendar uses one daytime window per selected working day.']);
        }

        Validator::make($data, [
            'public.contact_phone' => [Rule::requiredIf((bool) $data['public']['enabled'])],
            'public.notice' => [Rule::requiredIf((bool) $data['public']['enabled'])],
        ])->validate();
        foreach ($data['pathways'] as $pathway) {
            abort_if($pathway['personal_income'] && $pathway['service_ids'] !== [], 422, 'Personal income assistance cannot authorize provider services.');
            abort_if($pathway['id'] === 'personal_income' && ! $pathway['personal_income'], 422, 'Personal income assistance must retain its separate purpose.');
            abort_if($pathway['verified_on'] && $pathway['review_on'] && $pathway['review_on'] < $pathway['verified_on'], 422, 'The pathway review date cannot precede its verification date.');
        }

        foreach (self::CHOICE_LISTS as $list) {
            if (! isset($data['choices'][$list])) {
                continue;
            }
            $items = $data['choices'][$list];
            $ids = array_column($items, 'id');
            $labels = array_map(fn ($item) => mb_strtolower(trim($item['label'])), $items);
            $enabled = array_column(array_filter($items, fn ($item) => $item['enabled']), 'id');
            $message = match (true) {
                count($ids) !== count(array_unique($ids)) || count($labels) !== count(array_unique($labels)) => 'Give each choice its own name.',
                array_diff(self::builtInChoices($list), $ids) !== [] => 'Keep every standard choice. Turn one off instead of removing it.',
                array_diff(self::PROTECTED_CHOICES[$list], $enabled) !== [] => 'Keep “'.implode('” and “', array_map(fn ($id) => self::CHOICE_LABELS[$list][$id], self::PROTECTED_CHOICES[$list])).'” turned on. Other parts of the referral process rely on it.',
                $enabled === [] => 'Keep at least one choice turned on.',
                default => null,
            };
            if ($message !== null) {
                throw ValidationException::withMessages(['configuration.choices.'.$list => $message]);
            }
        }

        foreach ($data['routing']['rules'] ?? [] as $index => $rule) {
            foreach (['owner_ids', 'regions', 'service_ids', 'funding_situations', 'pathway_ids'] as $key) {
                $values = $key === 'regions' ? array_map(fn ($value) => mb_strtolower(trim($value)), $rule[$key]) : ($rule[$key] ?? []);
                if (count(array_unique($values)) !== count($values)) {
                    throw ValidationException::withMessages(['configuration.routing.rules.'.$index.'.'.$key => 'Do not repeat a value within the same routing rule. Values may be reused by other rules.']);
                }
            }
        }
        app(ReferralRouting::class)->validateCandidates($data);
        app(ReferralWorkflow::class)->validate($data);
        app(ReferralForms::class)->validateConfiguration($data);
        app(ReferralChecklists::class)->validateConfiguration($data);
        ReferralMessageTemplates::validateConfiguration($data);

        return $data;
    }

    public function requiresGovernance(array $before, array $after): bool
    {
        foreach ($after['services'] as $service) {
            $previous = collect($before['services'])->firstWhere('id', $service['id']);
            $allowedBefore = $previous['context_types'] ?? null;
            $allowedAfter = $service['context_types'] ?? null;
            if ($service['enabled'] && $allowedBefore !== null
                && ($allowedAfter === null || array_diff($allowedAfter, $allowedBefore) !== [])) {
                return true;
            }
        }
        $previousPathways = array_column($before['pathways'], null, 'id');
        foreach ($after['pathways'] as $pathway) {
            $previous = $previousPathways[$pathway['id']] ?? null;
            if ($pathway['enabled'] && (! $previous || ! $previous['enabled'])) {
                return true;
            }
            if ($previous && (
                $previous['personal_income'] !== $pathway['personal_income']
                || array_diff($previous['evidence_checklist'], $pathway['evidence_checklist']) !== []
                || array_diff($pathway['service_ids'], $previous['service_ids']) !== []
                // A private arrangement skips external funder verification.
                || (self::payerKind($previous) === 'external' && self::payerKind($pathway) === 'private')
            )) {
                return true;
            }
        }

        return $before['public']['notice'] !== $after['public']['notice']
            || ($before['document_requests'] ?? ReferralDocumentRequests::defaults()) !== ($after['document_requests'] ?? ReferralDocumentRequests::defaults())
            || ($before['follow_up'] ?? ReferralFollowUpPolicy::defaults()) !== ($after['follow_up'] ?? ReferralFollowUpPolicy::defaults())
            || (($after['follow_up']['day_basis'] ?? 'calendar') === 'business' || ($after['follow_up']['office_hours_only'] ?? false))
                && $before['business_calendar'] !== $after['business_calendar']
            || ($before['forms'] ?? ['fields' => []]) !== ($after['forms'] ?? ['fields' => []])
            || ($before['checklists'] ?? []) !== ($after['checklists'] ?? [])
            || ($before['routing'] ?? self::defaults()['routing']) !== ($after['routing'] ?? self::defaults()['routing'])
            || ($before['workflow'] ?? ReferralWorkflow::defaults()) !== ($after['workflow'] ?? ReferralWorkflow::defaults())
            || ($before['proposals']['approval_limits'] ?? []) !== ($after['proposals']['approval_limits'] ?? [])
            || $after['intake']['first_contact_business_days'] > $before['intake']['first_contact_business_days']
            || $after['intake']['hold_review_business_days'] > $before['intake']['hold_review_business_days']
            || $after['proposals']['approval_limit_cents'] > $before['proposals']['approval_limit_cents']
            || (! $before['proposals']['temporary_funding_enabled'] && $after['proposals']['temporary_funding_enabled']);
    }

    private function lockPointer(): ReferralConfiguration
    {
        DB::table('care_referral_configuration')->insertOrIgnore(['id' => 1, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ReferralConfiguration::query()->lockForUpdate()->findOrFail(1);
    }

    /** @param list<string> $actions any one of these settings actions is enough */
    private function actor(User $actor, array $actions): User
    {
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['referrals.settings.view', 'referrals.settings.manage', 'referrals.settings.publish', 'referrals.settings.governance']);
        $access = app(ReferralAccess::class);
        abort_unless($actor->approved_at && collect($actions)->contains(fn (string $action) => $access->can($actor, $action)), 403);
        CurrentAuthorizationReads::within(function ($reads) use ($actor): void {
            $staff = app(UserSiteAccessService::class)->applyStaffScope(User::query()->whereKey($actor->id), $actor, ['referrals.settings.view']);
            abort_unless($reads->query($staff)->exists(), 403, 'Current approved staff access is required for referral settings.');
        });

        return $actor;
    }

    private function record(ReferralSettingsVersion $version, User $actor, string $kind, array $extra = []): void
    {
        ReferralEvent::query()->create([
            'actor_id' => $actor->id, 'kind' => $kind, 'audience' => 'settings',
            'subject_type' => 'settings_version', 'subject_id' => $version->id,
            'payload' => ['version' => $version->version, 'base_version_id' => $version->base_version_id, ...$extra], 'occurred_at' => now(),
        ]);
    }
}

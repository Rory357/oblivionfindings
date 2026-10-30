<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Configurable working-stage restrictions cannot replace canonical conversion or activation gates. */
final class ReferralWorkflow
{
    public const SOURCES = ['new', 'triage', 'assessment', 'funding_assistance', 'ready_for_handover', 'on_hold'];

    public const EVIDENCE = ['assessment:authority', 'assessment:identity', 'assessment:assessment', 'funding:application', 'funding:external_decision', 'funding:agreement'];

    public static function defaults(): array
    {
        $rules = [];
        foreach (self::SOURCES as $from) {
            foreach (ReferralLifecycle::WORK_STAGES as $to) {
                if ($from !== $to) {
                    $rules[] = ['from' => $from, 'to' => $to, 'role_id' => null, 'evidence' => []];
                }
            }
        }

        return ['transitions' => $rules];
    }

    public function validate(array $configuration): void
    {
        $rules = ($configuration['workflow'] ?? self::defaults())['transitions'];
        $keys = [];
        foreach ($rules as $rule) {
            if (count(array_unique($rule['evidence'])) !== count($rule['evidence'])) {
                throw ValidationException::withMessages(['configuration.workflow' => 'List each evidence requirement only once within a transition. Other transitions may require the same evidence.']);
            }
            $key = $rule['from'].':'.$rule['to'];
            if ($rule['from'] === $rule['to'] || in_array($key, $keys, true)) {
                throw ValidationException::withMessages(['configuration.workflow' => 'Each transition must connect different stages and appear only once.']);
            }
            $keys[] = $key;
            if ($key === 'new:triage' && $rule['evidence'] !== []) {
                throw ValidationException::withMessages(['configuration.workflow' => 'Initial triage must remain possible for process enquiries without collecting personal evidence. Add evidence requirements to later transitions.']);
            }
        }
        if (! in_array('new:triage', $keys, true)) {
            throw ValidationException::withMessages(['configuration.workflow' => 'Keep the new-to-triage route for process enquiries.']);
        }
        $reachable = ['new'];
        do {
            $before = count($reachable);
            foreach ($rules as $rule) {
                if (in_array($rule['from'], $reachable, true) && ! in_array($rule['to'], $reachable, true)) {
                    $reachable[] = $rule['to'];
                }
            }
        } while (count($reachable) !== $before);
        if (array_diff(ReferralLifecycle::WORK_STAGES, $reachable)) {
            throw ValidationException::withMessages(['configuration.workflow' => 'Triage, assessment and funding assistance must all be reachable from new referrals.']);
        }
    }

    public function configuration(CareReferral $referral): array
    {
        return ReferralSettingsVersion::findOrFail($referral->settings_version_id)->configuration;
    }

    public function targets(User $actor, CareReferral $referral): array
    {
        // Internal preparations are linked to their existing person from the start.
        if (! app(ReferralAccess::class)->can($actor, 'transition') || in_array($referral->stage, ['closed', 'converted'], true)
            || ($referral->client_id && $referral->entry_kind !== InternalSupportPreparation::KIND)) {
            return [];
        }
        $configuration = $this->configuration($referral);
        $roles = $actor->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

        $targets = collect(($configuration['workflow'] ?? self::defaults())['transitions'])
            ->filter(fn ($rule) => $rule['from'] === $referral->stage && ($rule['role_id'] === null || in_array((int) $rule['role_id'], $roles, true))
                && (! $referral->general_enquiry || $rule['to'] === 'triage'))
            ->map(function ($rule) use ($configuration, $actor) {
                $visible = array_values(array_filter($rule['evidence'], fn ($requirement) => app(ReferralAccess::class)->audience($actor, explode(':', $requirement)[0])));

                return [
                    'id' => $rule['to'],
                    'label' => collect($configuration['stages'])->firstWhere('id', $rule['to'])['label'] ?? ucfirst(str_replace('_', ' ', $rule['to'])),
                    // Policy requirements only: never reveal document existence, IDs or restricted categories.
                    'required_evidence' => $visible,
                    'restricted_requirements' => count($visible) !== count($rule['evidence']),
                ];
            })->values()->all();
        // Ready for handover is offered from working stages; the server checks Gate A readiness on submit.
        $access = app(ReferralAccess::class);
        if (in_array($referral->stage, ReferralLifecycle::WORK_STAGES, true) && ! $referral->general_enquiry
            && $access->can($actor, 'convert') && $access->can($actor, 'assessment.view') && $access->can($actor, 'funding.view')
            && ! in_array(ReferralLifecycle::READY, array_column($targets, 'id'), true)) {
            $targets[] = ['id' => ReferralLifecycle::READY, 'label' => collect($configuration['stages'])->firstWhere('id', ReferralLifecycle::READY)['label'] ?? 'Ready for handover',
                'required_evidence' => [], 'restricted_requirements' => false, 'requires_readiness' => true];
        }

        return $targets;
    }

    public function enforce(User $actor, CareReferral $referral, string $to, CurrentAuthorizationReads $reads): array
    {
        $configuration = $this->configuration($referral);
        $rule = collect(($configuration['workflow'] ?? self::defaults())['transitions'])->first(fn ($rule) => $rule['from'] === $referral->stage && $rule['to'] === $to);
        abort_unless($rule, 422, 'This transition is not allowed by the referral’s recorded workflow. Arrange an authorized configuration review if the workflow needs changing.');
        if ($rule['role_id'] !== null) {
            abort_unless($reads->query($actor->roles()->getQuery()->where('roles.id', $rule['role_id']))->exists(), 403, 'This transition requires its configured responsible role as well as referral permissions.');
        }
        abort_if(app(ReferralChecklists::class)->missing($referral, $to) > 0, 422, 'Complete the required preparation checklists before changing to this stage. A colleague may need to review items in another information audience.');
        $proof = [];
        foreach ($rule['evidence'] as $requirement) {
            [$audience, $category] = explode(':', $requirement);
            abort_unless(app(ReferralAccess::class)->audience($actor, $audience), 403, 'A colleague with access to the required workflow evidence must review this transition.');
            $document = ReferralEvidence::where('referral_id', $referral->id)->where('audience', $audience)->where('state', 'available')->lockForUpdate()->get()
                ->first(fn ($document) => ($document->metadata['category'] ?? '') === $category && empty($document->metadata['superseded_by_id'])
                    && (empty($document->metadata['expires_at']) || CarbonImmutable::parse($document->metadata['expires_at'])->isFuture())
                    && app(ReferralEvidenceFiles::class)->bytes($document) !== null);
            abort_unless($document, 422, 'Add current available '.str_replace('_', ' ', $category).' evidence in the '.$audience.' audience before this transition. Document availability does not replace separate authority or funding verification.');
            $proof[$audience][] = ['category' => $category, 'evidence_id' => $document->id];
        }

        return $proof;
    }
}

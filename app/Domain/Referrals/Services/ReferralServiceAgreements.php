<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralServiceAgreement;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementStatusChange;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\NotificationService;
use App\Services\UserSiteAccessService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Turns the approved and accepted support proposal into the person's DRAFT service agreement,
 * so nobody retypes the lines. It follows Finance's quote-to-agreement pattern: one locked
 * transaction, a draft that Finance's own submit → independent approval makes active, and an
 * idempotent replay of the same source. Nothing here activates, approves, invoices or consumes
 * an agreement, and a newer proposal version never edits an agreement made from an older one.
 */
final class ReferralServiceAgreements
{
    /** Canonical keys read inside the referral command (person access, agreement access and Site scope). */
    public const PERMISSIONS = [...ReferralConversionService::CANONICAL_PERMISSIONS, 'service_agreements.create', 'service_agreements.viewAny', 'reports.viewAny'];

    /** Agreement units the service agreement screens accept (ServiceAgreementController line items). */
    public const UNITS = ['hour', 'night', 'day', 'visit', 'km', 'trip', 'flat'];

    /** Referral pathway → the agreement type Finance already uses. Unlisted pathways are "other". */
    private const AGREEMENT_TYPES = [
        'dss_nasc' => 'nasc', 'egl' => 'egl_if', 'individualised_funding' => 'egl_if', 'acc' => 'acc',
        'health_nz' => 'te_whatu_ora', 'private' => 'private', 'charitable' => 'charitable', 'insurance' => 'other', 'other' => 'other',
    ];

    /** What happens after the draft exists, in the order people do it. */
    public const APPROVAL_STEPS = [
        ['title' => 'Finance is notified', 'detail' => 'Finance gets a notice that a draft from this referral needs review.'],
        ['title' => 'Check the draft', 'detail' => 'Review the lines, funder, terms and signatures in Service agreements. Edits happen there, not on the referral.'],
        ['title' => 'Submit for approval', 'detail' => 'A provider manager (or anyone who can update service agreements) submits the draft.'],
        ['title' => 'Independent approval', 'detail' => 'A different provider manager approves it: not the person who created or submitted it. Only then does it become active.'],
        ['title' => 'Approve the service start', 'detail' => 'On the referral, a manager who did not create the agreement approves the start date.'],
    ];

    /**
     * Who may create and approve drafts made from referrals (Stephan, 21 Oct 2026): provider
     * managers. Finance and coordinators were not chosen. Applied by the deploy grant migration
     * 2026_10_21_130000_grant_provider_manager_service_agreements and mirrored in RbacSeeder.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_GRANTS = [
        'provider_manager' => ['service_agreements.viewAny', 'service_agreements.create', 'service_agreements.update'],
    ];

    /**
     * Finance reads agreements so the "draft needs review" notice it receives opens (Stephan, 23 Oct 2026).
     * Read-only: no create or update. Kept apart from ROLE_GRANTS so the already-deployed provider-manager
     * grant migration keeps its exact behaviour; 2026_10_23_090000 applies this one.
     */
    public const READ_ONLY_ROLE_GRANTS = [
        'finance' => ['service_agreements.viewAny'],
    ];

    /** The canonical agreement keys (OperationsPermissionsSeeder), so a grant never depends on seed order. */
    public const PERMISSION_DEFINITIONS = [
        ['key' => 'service_agreements.viewAny', 'description' => 'View Service Agreements'],
        ['key' => 'service_agreements.create', 'description' => 'Create Service Agreements'],
        ['key' => 'service_agreements.update', 'description' => 'Update Service Agreements'],
    ];

    /** Notification event (config/notification_routing.php + notification_events.php): routed to the finance role group. */
    public const FINANCE_EVENT = 'service_agreements.referral_draft_created';

    public function __construct(
        private readonly ReferralCommand $command,
        private readonly ReferralAccess $access,
        private readonly ReferralProposals $proposals,
        private readonly ReferralProposalPricing $pricing,
        private readonly ReferralAgreementTerms $terms,
    ) {}

    /** Read model for the profile's Proposal section and the handover "Service agreement" check. */
    public function inspect(User $actor, CareReferral $referral): array
    {
        $latest = $this->proposals->latest($referral);
        $links = ReferralServiceAgreement::query()->where('referral_id', $referral->id)->orderByDesc('proposal_version')->orderByDesc('id')->get();
        $live = ServiceAgreement::query()->whereIn('id', $links->pluck('service_agreement_id'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        // The check follows the newest agreement that still exists. A draft Finance deleted stays in
        // history as "Deleted", and its proposal version may be used again for a fresh draft.
        $link = $links->first(fn (ReferralServiceAgreement $candidate) => in_array((int) $candidate->service_agreement_id, $live, true));
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->first();
        $person = $conversion ? Client::query()->find($conversion->client_id) : null;
        $personVisible = $person && app(ClientPolicy::class)->view($actor, $person);

        $state = 'ready';
        $blockers = [];
        $plan = null;
        if (! $latest) {
            $state = 'no_proposal';
        } elseif (! $conversion || ! $person) {
            $state = 'not_converted';
        } elseif ($link && (int) $link->proposal_id === (int) $latest->id) {
            $state = 'created';
        } else {
            $plan = $this->plan($referral, $latest);
            $blockers = [...$this->blockers($referral, $latest), ...$plan['blockers']];
            $state = $blockers === [] ? 'ready' : 'blocked';
        }

        return [
            'state' => $state,
            'version' => $referral->lock_version,
            'proposal' => $latest ? ['id' => $latest->id, 'version' => $latest->version, 'title' => $latest->proposal['title'] ?? null] : null,
            'blockers' => array_values(array_unique($blockers)),
            'can_create' => ($reason = $this->permissionGap($actor, $person)) === null,
            'permission_reason' => $reason,
            'person' => $personVisible ? ['id' => $person->id, 'name' => $person->full_name] : null,
            'preview' => $plan ? $this->present($plan) : null,
            'agreement' => $link ? $this->summary($actor, $link, $latest) : null,
            'earlier' => $links->reject(fn (ReferralServiceAgreement $other) => $link !== null && $other->id === $link->id)
                ->map(fn (ReferralServiceAgreement $earlier) => $this->summary($actor, $earlier, $latest))->values()->all(),
            'approval_steps' => self::APPROVAL_STEPS,
        ];
    }

    /** Create the draft agreement for the current proposal version, or return the one already made from it. */
    public function create(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, [
            'proposal_id' => 'required|integer|min:1', 'proposal_version' => 'required|integer|min:1',
            'acknowledge_draft' => 'accepted', 'acknowledge_earlier_agreement' => 'sometimes|boolean',
        ])->validate();

        // A retry of the same proposal version returns its live agreement; permission is still rechecked.
        // The referral row lock in ReferralCommand serialises creates, so one live draft exists per version.
        $replay = function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): ?array {
            $existing = $this->liveLink(ReferralServiceAgreement::query()->where('referral_id', $referral->id)->where('proposal_id', $data['proposal_id']));
            if (! $existing) {
                return null;
            }
            $person = Client::query()->findOrFail($existing->client_id);
            $this->authorizeCanonical($actor, $person, $reads);

            return $this->receipt($actor, $existing, true, $reads);
        };

        return $this->command->run($actor, $id, $version, 'proposals.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data): array {
            abort_if($referral->stage === 'closed' || $referral->general_enquiry, 422, 'Reopen the referral before creating its service agreement.');
            $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->lockForUpdate()->first();
            abort_unless($conversion, 422, 'Complete the onboarding handover first. The service agreement belongs to the person’s record.');
            $person = Client::query()->lockForUpdate()->findOrFail($conversion->client_id);
            $this->authorizeCanonical($actor, $person, $reads);

            $proposal = $this->proposals->current($referral, (int) $data['proposal_id']);
            abort_unless($proposal->version === (int) $data['proposal_version'], 409, 'This proposal has been revised. Review the current version first.');
            $plan = $this->plan($referral, $proposal, $reads);
            $blockers = array_values(array_unique([...$this->blockers($referral, $proposal, $reads), ...$plan['blockers']]));
            abort_unless($blockers === [], 422, implode(' ', $blockers));

            // Only an agreement that still exists needs the "leave it unchanged" confirmation.
            $earlier = $this->liveLink(ReferralServiceAgreement::query()->where('referral_id', $referral->id));
            // An explicit check: abort_if() would build this message, reading $earlier, even when there is none.
            if ($earlier && ! ($data['acknowledge_earlier_agreement'] ?? false)) {
                abort(422, 'An agreement from proposal version '.$earlier->proposal_version.' already exists and will not be changed. Confirm that you are creating a separate draft for version '.$proposal->version.'.');
            }
            // A draft Finance deleted keeps its link as history; this version may then get a fresh draft.
            $replaced = ReferralServiceAgreement::query()->where('referral_id', $referral->id)->where('proposal_id', $proposal->id)->lockForUpdate()->orderByDesc('id')->first();

            $header = [...$plan['header'], 'client_id' => $person->id, 'status' => 'draft', 'budget_used' => '0.00', 'created_by' => $actor->id,
                'source_referral_id' => $referral->id, 'source_referral_proposal_id' => $proposal->id,
                'notes' => 'Draft created from referral '.$referral->reference.', support proposal version '.$proposal->version.', on '.today('Pacific/Auckland')->format('j F Y')
                    .'. The proposal lines were copied without changes. Check the terms, funder details and signatures before submitting it for approval.'];
            $agreement = ServiceAgreement::query()->create($header);
            foreach ($plan['lines'] as $index => $line) {
                $agreement->lineItems()->create([
                    'item_number' => (string) ($index + 1), 'description' => $line['description'], 'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'], 'unit' => $line['unit'], 'budget_allocated' => $line['budget'], 'budget_used' => '0.00',
                    'category' => $line['service'], 'funding_contract_reference' => $line['funding_reference'],
                ]);
            }
            // Finance's lifecycle history starts at the draft, so its approval trail shows where it came from.
            ServiceAgreementStatusChange::query()->create(['service_agreement_id' => $agreement->id, 'from_status' => null, 'to_status' => 'draft', 'changed_by' => $actor->id,
                'reason' => 'Created from a referral support proposal', 'notes' => 'Referral '.$referral->reference.', proposal version '.$proposal->version.'.']);

            $receipt = ['header' => Arr::except($header, ['notes']), 'lines' => $plan['lines'], 'earlier_agreement_id' => $earlier?->service_agreement_id,
                'replaces_deleted_agreement_id' => $replaced?->service_agreement_id];
            $link = ReferralServiceAgreement::query()->create([
                'referral_id' => $referral->id, 'proposal_id' => $proposal->id, 'proposal_version' => $proposal->version, 'client_id' => $person->id,
                'service_agreement_id' => $agreement->id, 'created_by' => $actor->id, 'created_at' => now(),
                'mapping_digest' => hash('sha256', json_encode(Arr::sortRecursive($receipt), JSON_THROW_ON_ERROR)), 'receipt' => $receipt,
            ]);
            $this->command->record($referral, $actor, 'proposal.agreement_created', 'funding', [
                'proposal_id' => $proposal->id, 'proposal_version' => $proposal->version, 'service_agreement_id' => $agreement->id, 'status' => 'draft',
                'total_budget' => $plan['header']['total_budget'], 'prices' => $plan['header']['gst_inclusive'] ? 'Including GST' : 'Excluding GST', 'lines' => count($plan['lines']),
                'summary' => 'Draft service agreement created from support proposal version '.$proposal->version.'. It needs Finance approval before it is active.',
                ...($replaced ? ['replaces_deleted_agreement_id' => $replaced->service_agreement_id] : []),
            ]);
            // Tell Finance only once the draft is committed; a failed notice never undoes the draft.
            DB::afterCommit(fn () => $this->notifyFinance($actor, $agreement, $referral, $proposal));

            return $this->receipt($actor, $link, false, $reads);
        }, self::PERMISSIONS, $replay);
    }

    /** The newest link from the query whose agreement still exists (Finance may soft-delete a draft). */
    private function liveLink(Builder $query): ?ReferralServiceAgreement
    {
        foreach ($query->lockForUpdate()->orderByDesc('proposal_version')->orderByDesc('id')->get() as $link) {
            if (ServiceAgreement::query()->whereKey($link->service_agreement_id)->lockForUpdate()->exists()) {
                return $link;
            }
        }

        return null;
    }

    /**
     * "Draft service agreement from referral REF… needs review", to the finance role group only
     * (include_managers false, so the manager roles are not added). The notice names the referral
     * reference, never the person, and links to the agreement.
     */
    private function notifyFinance(User $actor, ServiceAgreement $agreement, CareReferral $referral, ReferralProposal $proposal): void
    {
        try {
            app(NotificationService::class)->notifyCrud($actor, 'referral_draft_created', 'service agreement', $agreement, null, [
                'event_key' => self::FINANCE_EVENT,
                'include_managers' => false, 'include_assigned_workers' => false, 'include_entity_user' => false,
                'title' => 'Draft service agreement from referral '.$referral->reference.' needs review',
                'body' => 'Made from support proposal version '.$proposal->version.'. Check it, then a provider manager submits it for approval in Service agreements.',
                'url' => url('/operations/service-agreements/'.$agreement->id),
                'context' => ['Module' => 'Referrals', 'Status' => 'Draft', 'Referral' => $referral->reference, 'Proposal version' => (string) $proposal->version],
                'data' => ['service_agreement_id' => $agreement->id, 'referral_reference' => $referral->reference],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Why this proposal version cannot become an agreement yet, in plain language. */
    private function blockers(CareReferral $referral, ReferralProposal $proposal, ?CurrentAuthorizationReads $reads = null): array
    {
        $blockers = [];
        $version = $proposal->version;
        if ($referral->stage === 'closed') {
            $blockers[] = 'Reopen the referral before creating its service agreement.';
        }
        $ends = CarbonImmutable::parse($proposal->proposal['ends_on'], 'Pacific/Auckland')->startOfDay();
        if (CarbonImmutable::now('Pacific/Auckland')->startOfDay()->gt($ends)) {
            $blockers[] = 'Proposal version '.$version.' ended on '.$ends->format('j F Y').'. Prepare a current version first.';
        }
        $readiness = app(ReferralProposalReadiness::class)->inspect($referral, $proposal, null, $reads);
        if (! $readiness['internal_approval_current']) {
            $blockers[] = 'Proposal version '.$version.' needs current independent internal approval.';
        }
        if (! $readiness['person_acceptance_current']) {
            $blockers[] = 'Proposal version '.$version.' needs the person’s current acceptance.';
        }
        foreach ($readiness['private_payers'] as $payer) {
            if (! $payer['accepted']) {
                $blockers[] = 'Proposal version '.$version.' needs the private payer’s acceptance.';
            }
        }

        return $blockers;
    }

    /**
     * Map each proposal line to one agreement line with the same unit, rate and GST basis that the
     * service-start check (ReferralAgreementTerms::match) later expects. Quantities and budgets cover
     * every stated period in the proposal dates, never prorated. A line without a price is reported,
     * never written as zero.
     */
    private function plan(CareReferral $referral, ReferralProposal $proposal, ?CurrentAuthorizationReads $reads = null): array
    {
        $terms = $proposal->proposal;
        $start = CarbonImmutable::parse($terms['starts_on'], 'Pacific/Auckland');
        $end = CarbonImmutable::parse($terms['ends_on'], 'Pacific/Auckland');
        $settings = app(ReferralSettings::class)->effective($reads);
        $services = collect($settings?->configuration['services'] ?? [])->pluck('label', 'id');
        $funding = $this->funding($referral, $terms['allocations'] ?? [], $settings?->configuration['pathways'] ?? []);
        $lines = [];
        $bases = [];
        $total = BigDecimal::zero();
        foreach ($terms['lines'] as $line) {
            $estimate = $line['estimate'] ?? ['known' => false];
            $unit = $this->unit($line);
            $periods = $this->terms->periods($start, $end, $line['period']);
            $references = array_values(array_unique(array_filter($funding['lines'][$line['key']] ?? [])));
            $row = ['key' => $line['key'], 'description' => $line['description'], 'service' => $services[$line['service_id']] ?? null,
                'proposal_quantity' => $line['quantity'], 'proposal_unit' => $line['unit'] === 'other' ? ($line['unit_label'] ?? 'other') : $line['unit'],
                'period' => $line['period'], 'periods' => $periods, 'currency' => $line['currency'], 'unit' => $unit, 'unit_price' => null, 'quantity' => null,
                'budget' => null, 'price_basis' => $line['price_basis'], 'funding_reference' => count($references) === 1 && mb_strlen($references[0]) <= 100 ? $references[0] : null, 'issue' => null];
            $row['issue'] = match (true) {
                ! ($estimate['known'] ?? false) || $line['unit_rate'] === null || $line['quantity'] === null => 'No price yet. Revise the proposal with a quantity, rate and GST treatment; an agreement line is never created at $0.',
                $line['currency'] !== 'NZD' => 'Priced in '.$line['currency'].'. Service agreements are recorded in NZD and no exchange rate is assumed.',
                $unit === null => 'The unit “'.($line['unit_label'] ?? $line['unit']).'” has no agreement unit to match (hour, night, day, visit, km, trip or flat). Revise the proposal unit.',
                ! $this->pricing->taxCurrent($line, $reads) => 'The GST rate changed after this version was priced. Prepare a revised proposal.',
                BigDecimal::of($line['unit_rate'])->stripTrailingZeros()->getScale() > 2 || BigDecimal::of($line['unit_rate'])->isGreaterThan('999999.99') => 'The rate must be in whole cents and under $1,000,000 to fit an agreement line.',
                $periods === null => 'The proposal dates are too long to count its budget periods. Ask Finance to review them.',
                mb_strlen($line['description']) > 255 => 'The description is longer than an agreement line allows (255 characters). Shorten it in a revised proposal.',
                default => null,
            };
            if ($row['issue'] === null) {
                $quantity = BigDecimal::of($line['quantity'])->multipliedBy($periods);
                $budget = BigDecimal::of($estimate[$line['price_basis'] === 'inclusive' ? 'gross' : 'net'])->multipliedBy($periods);
                if ($quantity->stripTrailingZeros()->getScale() > 2 || $quantity->isGreaterThan('99999999.99') || $budget->isGreaterThan('9999999999.99')) {
                    $row['issue'] = 'The total quantity or budget does not fit an agreement line (two decimal places). Ask Finance to review it.';
                } else {
                    $bases[$line['price_basis']] = true;
                    $row = [...$row, 'unit_price' => (string) BigDecimal::of($line['unit_rate'])->toScale(2), 'quantity' => (string) $quantity->toScale(2), 'budget' => (string) $budget->toScale(2)];
                    $total = $total->plus($budget);
                }
            }
            $lines[] = $row;
        }

        $blockers = [];
        foreach ($lines as $row) {
            if ($row['issue'] !== null) {
                $blockers[] = $row['description'].': '.$row['issue'];
            }
        }
        if (count($bases) > 1) {
            $blockers[] = 'Some lines are priced including GST and others excluding GST. One agreement records one GST basis, so revise the proposal to use the same basis for every line.';
        }
        if ($total->isGreaterThan('9999999999.99')) {
            $blockers[] = 'The total budget is larger than an agreement can record. Ask Finance to review it.';
        }

        $priced = array_filter($lines, fn (array $row) => $row['issue'] === null);
        $hours = array_values(array_filter($priced, fn (array $row) => $row['unit'] === 'hour'));
        $days = array_values(array_filter($priced, fn (array $row) => $row['unit'] === 'day'));
        $single = fn (array $rows): ?string => count(array_unique(array_column($rows, 'unit_price'))) === 1 ? $rows[0]['unit_price'] : null;
        // Header summaries only; left blank (never truncated) when they would not fit the column.
        $sum = function (array $values, string $max): ?string {
            $total = array_reduce($values, fn (BigDecimal $carry, string $value) => $carry->plus($value), BigDecimal::zero());

            return $total->stripTrailingZeros()->getScale() > 2 || $total->isGreaterThan($max) ? null : (string) $total->toScale(2);
        };
        $weekly = $hours !== [] && collect($hours)->every(fn (array $row) => $row['period'] === 'week');
        $agreed = ReferralServiceStart::query()->where('referral_id', $referral->id)->whereIn('status', ['scheduled', 'commenced'])->orderByDesc('id')->first();

        return [
            'blockers' => $blockers,
            'lines' => array_values(array_map(fn (array $row) => Arr::except($row, ['issue']), $priced)),
            'all_lines' => $lines,
            'header' => [
                'title' => mb_substr((string) ($terms['title'] ?? 'Support agreement'), 0, 255),
                'agreement_type' => $funding['agreement_type'],
                'funding_type' => $funding['funding_type'],
                'funding_body' => $funding['funding_body'],
                'funding_reference' => $funding['funding_reference'],
                // The agreement covers the whole proposal period so the service-start check can match it.
                'starts_at' => $start->toDateString(),
                'ends_at' => $end->toDateString(),
                'first_service_date' => $agreed?->agreed_date?->toDateString(),
                'total_budget' => (string) $total->toScale(2),
                'gst_inclusive' => isset($bases['inclusive']),
                'hourly_rate' => $hours ? $single($hours) : null,
                'daily_rate' => $days ? $single($days) : null,
                'total_hours' => $hours ? $sum(array_column($hours, 'quantity'), '99999999.99') : null,
                'allocated_hours_per_week' => $weekly ? $sum(array_column($hours, 'proposal_quantity'), '999999.99') : null,
            ],
        ];
    }

    /** Funder and payer details from the funding cases each line is allocated to. */
    private function funding(CareReferral $referral, array $allocations, array $pathways): array
    {
        $labels = collect($pathways)->keyBy('id');
        $cases = ReferralFundingCase::query()->where('referral_id', $referral->id)
            ->whereIn('id', array_map('intval', array_column($allocations, 'funding_case_id')))->get()->keyBy('id');
        $byLine = [];
        $bodies = [];
        $references = [];
        $types = [];
        $pathwayIds = [];
        foreach ($allocations as $allocation) {
            $case = $cases->get((int) $allocation['funding_case_id']);
            if (! $case) {
                continue;
            }
            $decision = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->find($allocation['decision_id']);
            $roles = $case->details['roles'] ?? [];
            $pathway = $labels->get($case->pathway) ?? ($case->details['guide'] ?? []);
            $body = collect([$roles['funding_body'] ?? null, $roles['invoice_payer'] ?? null, $decision?->decision['decision_maker'] ?? null,
                $case->details['pathway_label'] ?? null, $pathway['label'] ?? null])->map(fn ($value) => trim((string) $value))->first(fn ($value) => $value !== '');
            $reference = trim((string) ($decision?->decision['external_reference'] ?? ''));
            $bodies[] = $body;
            $references[] = $reference;
            $byLine[$allocation['line_key']][] = $reference;
            $pathwayIds[] = $case->pathway;
            $types[] = self::AGREEMENT_TYPES[$case->pathway] ?? (ReferralSettings::payerKind(['id' => $case->pathway, ...$pathway]) === 'private' ? 'private' : 'other');
        }
        $join = fn (array $values): ?string => ($text = implode('; ', array_values(array_unique(array_filter($values))))) === '' ? null : mb_substr($text, 0, 255);
        $types = array_values(array_unique($types));
        $pathwayIds = array_values(array_unique($pathwayIds));

        return [
            'lines' => $byLine,
            'funding_body' => $join($bodies),
            'funding_reference' => $join($references),
            'agreement_type' => count($types) === 1 ? $types[0] : 'other',
            'funding_type' => $pathwayIds === ['individualised_funding'] ? 'if' : null,
        ];
    }

    /** The agreement unit for a proposal unit, or null when there is no exact match. */
    private function unit(array $line): ?string
    {
        $unit = match ($line['unit']) {
            'hours' => 'hour', 'days' => 'day', 'visits' => 'visit', default => mb_strtolower(trim((string) ($line['unit_label'] ?? ''))),
        };

        return in_array($unit, self::UNITS, true) ? $unit : null;
    }

    /** Referral handover rights plus Finance's agreement rights and access to the person's record. */
    private function authorizeCanonical(User $actor, Client $person, CurrentAuthorizationReads $reads): void
    {
        abort_unless($this->access->can($actor, 'proposals.manage') && $this->access->can($actor, 'convert'), 403,
            'Creating the agreement needs the referral permissions to prepare proposals and hand over to onboarding.');
        abort_unless($actor->canDo('service_agreements.create') && $actor->canDo('service_agreements.viewAny'), 403, 'Creating the agreement needs permission to create service agreements.');
        abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $person, $reads), 404);
        abort_unless($person->site_id && in_array((int) $person->site_id, app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['reports.viewAny'], $reads), true), 403,
            'The person’s site is outside your service agreement access.');
    }

    /** The first missing right, worded for the person who lacks it; null when they can create the draft. */
    private function permissionGap(User $actor, ?Client $person): ?string
    {
        return match (true) {
            ! $this->access->can($actor, 'proposals.manage') || ! $this->access->can($actor, 'convert') => 'Creating the agreement needs the referral permissions to prepare proposals and hand over to onboarding.',
            ! $actor->canDo('service_agreements.create') || ! $actor->canDo('service_agreements.viewAny') => 'Creating the agreement needs permission to create service agreements.',
            $person !== null && ! app(ClientPolicy::class)->view($actor, $person) => 'Creating the agreement needs access to the person’s record.',
            $person !== null && ! in_array((int) $person->site_id, app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['reports.viewAny']), true) => 'The person’s site is outside your service agreement access.',
            default => null,
        };
    }

    private function canOpen(User $actor, ?ServiceAgreement $agreement, ?CurrentAuthorizationReads $reads = null): bool
    {
        if (! $agreement || $agreement->trashed() || ! $actor->canDo('service_agreements.viewAny')) {
            return false;
        }
        $siteId = Client::query()->whereKey($agreement->client_id)->value('site_id');

        return $siteId !== null && in_array((int) $siteId, app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['reports.viewAny'], $reads), true);
    }

    private function summary(User $actor, ReferralServiceAgreement $link, ?ReferralProposal $latest): array
    {
        $agreement = ServiceAgreement::withTrashed()->find($link->service_agreement_id);
        $outdated = $latest !== null && $latest->version > $link->proposal_version;

        return [
            'id' => $link->service_agreement_id,
            'title' => $agreement?->title,
            'status' => $agreement === null || $agreement->trashed() ? 'deleted' : $agreement->status,
            'total_budget' => $agreement?->total_budget,
            'gst_inclusive' => $agreement?->gst_inclusive,
            'starts_on' => $agreement?->starts_at?->toDateString(),
            'ends_on' => $agreement?->ends_at?->toDateString(),
            'proposal_id' => $link->proposal_id,
            'proposal_version' => $link->proposal_version,
            'created_at' => $link->created_at->toISOString(),
            'created_by_name' => $link->created_by ? User::query()->whereKey($link->created_by)->value('name') : null,
            'outdated' => $outdated,
            'warning' => $outdated ? 'Agreement is from version '.$link->proposal_version.'; review it. Proposal version '.$latest->version.' has different terms, and the agreement has not been changed.' : null,
            'can_open' => $this->canOpen($actor, $agreement),
            'href' => '/operations/service-agreements/'.$link->service_agreement_id,
        ];
    }

    private function receipt(User $actor, ReferralServiceAgreement $link, bool $replayed, CurrentAuthorizationReads $reads): array
    {
        $agreement = ServiceAgreement::withTrashed()->find($link->service_agreement_id);

        return ['saved' => true, 'replayed' => $replayed, 'proposal_version' => $link->proposal_version, 'agreement' => [
            'id' => $link->service_agreement_id, 'title' => $agreement?->title, 'status' => $agreement?->status ?? 'deleted',
            'href' => '/operations/service-agreements/'.$link->service_agreement_id, 'can_open' => $this->canOpen($actor, $agreement, $reads),
        ]];
    }

    /** The review pop-up's view of the mapped agreement. */
    private function present(array $plan): array
    {
        return [
            'header' => Arr::except($plan['header'], ['hourly_rate', 'daily_rate', 'total_hours', 'allocated_hours_per_week']),
            'lines' => array_map(fn (array $row) => Arr::only($row, ['key', 'description', 'service', 'proposal_quantity', 'proposal_unit', 'period', 'periods',
                'unit', 'unit_price', 'quantity', 'budget', 'price_basis', 'funding_reference', 'issue']), $plan['all_lines']),
            'currency' => 'NZD',
        ];
    }
}

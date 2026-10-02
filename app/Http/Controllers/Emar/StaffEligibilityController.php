<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\StaffEligibilityRegister;
use App\Services\Medication\WitnessPinResetAuthority;
use App\Services\UserSiteAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Safety & oversight › Staff eligibility (eMAR P11 chunk 6): the competency
 * register, renewals and exemptions for the houses this person can read. It
 * replaces Medication › Competency; assessments are still recorded, changed
 * and deleted through EmarController's competency actions, exemptions through
 * CompetencyExemptionController.
 */
class StaffEligibilityController extends Controller
{
    private const SECOND_PERSON_PERMISSIONS = [
        'medications.controlled.witness',
        'medications.administer.record',
        'medications.orders.verify',
    ];

    public function __construct(
        private readonly MedicationGovernanceScopeService $governanceScope,
        private readonly StaffEligibilityRegister $register,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * The old Medication › Competency address. A link naming a Site is
     * checked like any eMAR reader first (B2 C1 review, direct-object denial):
     * a Site this person can't read — another house, or none — is 404, the
     * same as before the move. Then it lands on Staff eligibility at that
     * house.
     */
    public function legacyCompetency(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteId = $request->filled('site_id') ? $request->integer('site_id') : null;
        if ($siteId !== null) {
            $this->governanceScope->readerSiteIds($actor, MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY, $siteId);
        }

        return redirect()->route('emar.safety.eligibility', array_filter(['house' => $siteId ?: null]));
    }

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $readerSiteIds = array_values(array_map('intval', $this->governanceScope->readerSiteIds(
            $actor,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
        )));
        $canAssess = $actor->canDo('medications.orders.manage');
        $assessSiteIds = $canAssess
            ? array_values(array_map('intval', $this->governanceScope->mutationSiteIds($actor, 'medications.orders.manage')))
            : [];
        $canExempt = $actor->canDo('medications.competency.exempt');
        $exemptSiteIds = $canExempt
            ? array_values(array_map('intval', $this->siteAccess->accessibleSiteIds($actor, ['sites.viewAll'])))
            : [];
        $canResetPins = $actor->canDo('medications.witness_pin.reset');
        $resetAuthority = app(WitnessPinResetAuthority::class);

        $people = $this->register->rows($readerSiteIds);
        $users = User::query()->whereKey($people->pluck('id')->all())->get()->keyBy('id');
        $people = $people->map(function (array $row) use ($actor, $assessSiteIds, $exemptSiteIds, $canResetPins, $resetAuthority, $users): array {
            $self = (int) $row['id'] === (int) $actor->id;
            $target = $users->get($row['id']);
            $atHouse = fn (array $siteIds): bool => $row['house_id'] !== null && in_array((int) $row['house_id'], $siteIds, true);

            return $row + ['can' => [
                // Assessed by someone else, at a house where they manage orders.
                'assess' => ! $self && $atHouse($assessSiteIds),
                // For someone else at their own house, when no assessment covers them.
                'exempt' => ! $self && $atHouse($exemptSiteIds) && ! in_array($row['st'], ['current', 'restricted'], true) && $row['prev_valid'] === null && $row['exemption'] === null,
                'reset_pin' => $canResetPins && ! $self && $target !== null
                    && collect(self::SECOND_PERSON_PERMISSIONS)->contains(fn (string $key): bool => $target->canDo($key))
                    && $resetAuthority->allows($actor, $target)
                    && ! in_array($row['pin'], ['not_set', 'reset'], true),
            ]];
        })->values();

        $houses = $this->governanceScope->sitePicker($readerSiteIds)
            ->map(fn ($site) => ['id' => (int) $site->id, 'name' => (string) $site->name])
            ->values();
        return Inertia::render('emar/StaffEligibility', [
            'people' => $people,
            'exemptions' => $this->register->exemptions($readerSiteIds)
                ->map(fn (array $e): array => $e + ['can_end' => $canExempt && $e['status'] === 'active' && in_array($e['house_id'], $exemptSiteIds, true)])
                ->values(),
            'houses' => $houses,
            'policy' => $this->register->policy(),
            'areas' => $this->register->areas(),
            'can' => [
                'assess' => $canAssess && $assessSiteIds !== [],
                'exempt' => $canExempt && $exemptSiteIds !== [],
                'reset_pins' => $canResetPins,
            ],
            // People at the houses where they assess, for logging observed administrations.
            'clients' => $assessSiteIds === [] ? [] : Client::query()
                ->whereIn('site_id', $assessSiteIds)
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'preferred_name', 'site_id'])
                ->map(fn (Client $c): array => [
                    'id' => (int) $c->id,
                    'name' => trim(($c->preferred_name ?: $c->first_name).' '.$c->last_name),
                    'house_id' => (int) $c->site_id,
                ])
                ->values(),
            'me' => ['id' => (int) $actor->id, 'name' => (string) $actor->name],
            'loaded_at' => now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
        ]);
    }
}

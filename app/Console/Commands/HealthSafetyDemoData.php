<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AppSetting;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HazardousSubstance;
use App\Models\HsRiskAssessment;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandService;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use App\Services\HealthSafety\FirstAidService;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\RestraintService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Realistic New Zealand example data for the Health and Safety module, for the
 * demo/test server (29 Oct 2026: H&S looked empty after the test fixtures were
 * cleaned out).
 *
 * Every record is written through the module's own domain services (receipts,
 * versions and audits included), so it reads exactly like a record a person
 * entered — nothing on screen says "demo".
 *
 * How our records are found again (never by name or wording):
 *  - each record has a fixed name ("first-aid-1"), turned into a UUIDv5 under
 *    NAMESPACE; that UUID is the service's own command key
 *    (first aid / restraint `creation_key`, chemical command intent key);
 *  - risk assessments have no key column, so the UUID → id pairs are kept in
 *    the private app_settings row REGISTRY_KEY.
 *
 * A prepare step runs first, because first aid needs a first aider on staff
 * and exposures need a substance stored at the Site:
 *  - up to 3 Sites without a first aider get one (a team lead or coordinator
 *    first) through the HR profile model; only profiles that were off are
 *    changed, and their ids are kept in FIRST_AIDERS_KEY;
 *  - two chemicals (bleach, caustic oven cleaner) are added to the register
 *    through ChemicalCommandService::create under UUIDv5 command keys; the
 *    exact commands are kept in CHEMICALS_KEY so a re-run replays them. No
 *    SDS is added: the SDS command only accepts an uploaded document;
 *  - up to 2 Sites without stored substances get a storage location through
 *    ChemicalCommandService::createStorage under a UUIDv5 command key; the
 *    exact command is kept in STORAGE_KEY so a re-run replays it.
 *
 * Re-running skips anything already there. `--remove` archives what the
 * services can archive (first aid, risk assessments, storage locations), marks
 * its two chemicals "Removed" through the register's status command, turns
 * off exactly the first-aider flags this command turned on, and leaves
 * restraint and chemical exposure records (no archive command) in place.
 */
final class HealthSafetyDemoData extends Command
{
    protected $signature = 'health-safety:demo
        {--remove : Remove the demo records this command created}
        {--dry-run : Say what would be created or removed, write nothing}';

    protected $description = 'Add (or remove) realistic NZ example records in Health and Safety, through the module\'s own services.';

    /** Fixed UUIDv5 namespace: changing it orphans every record made so far. */
    public const NAMESPACE = '5f3c2b8e-7a41-4d6e-9c0b-2e8f1a6d4c93';

    /** Private app_settings row holding risk assessment key → id pairs. */
    public const REGISTRY_KEY = 'health_safety.demo_data.risk_assessments';

    /** Private app_settings row: HR profile ids whose first-aider flag this command turned on. */
    public const FIRST_AIDERS_KEY = 'health_safety.demo_data.first_aiders';

    /** Private app_settings row: storage command key → substance id and the exact command sent. */
    public const STORAGE_KEY = 'health_safety.demo_data.storage_locations';

    /** Private app_settings row: chemical create command key → the exact command sent. */
    public const CHEMICALS_KEY = 'health_safety.demo_data.chemicals';

    /** Sites that get a first aider / a storage location when they have none. */
    private const FIRST_AIDER_SITES = 3;

    private const STORAGE_SITES = 2;

    /** Sites whose name contains any of these are test fixtures, not demo houses. */
    public const EXCLUDED_SITE_WORDS = ['Playwright', 'RELEASE', 'Codex', 'E2E', 'demo'];

    /** A record removed with --remove is recreated under its next generation key. */
    private const MAX_GENERATIONS = 25;

    private const KINDS = ['first_aider' => 'First aiders (HR flag)', 'substance' => 'Chemicals (register)', 'storage' => 'Substance storage locations', 'first_aid' => 'First aid', 'chemical' => 'Chemical exposures', 'restraint' => 'Restraints', 'risk' => 'Risk assessments'];

    /** @var array<string, array<string, int>> */
    private array $summary = [];

    private bool $dryRun = false;

    private User $actor;

    public static function key(string $name, int $generation = 1): string
    {
        return Uuid::uuid5(self::NAMESPACE, $generation === 1 ? $name : $name.'#'.$generation)->toString();
    }

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        foreach (array_keys(self::KINDS) as $kind) {
            $this->summary[$kind] = ['created' => 0, 'present' => 0, 'removed' => 0, 'left' => 0, 'skipped' => 0];
        }

        $actor = $this->resolveActor();
        if (! $actor) {
            $this->error('No usable admin found. The command acts as admin@demo.test (or else the first admin), who must be approved, current staff (an active HR profile) and allowed to manage Health and Safety. Nothing was changed.');

            return self::FAILURE;
        }
        $this->actor = $actor;
        $this->line(($this->dryRun ? '[dry run] ' : '').'Acting as '.$actor->name.' (user '.$actor->id.').');

        if ($this->option('remove')) {
            $this->removeFirstAid();
            $this->removeRiskAssessments();
            $this->removeStorage();
            $this->removeChemicals();
            $this->restoreFirstAiders();
            $this->countLeftInPlace();
        } else {
            $places = $this->places();
            if ($places->isEmpty()) {
                $this->warn('No active demo Sites this user can reach. Nothing was created.');
            } else {
                $places = $this->prepareFirstAiders($places);
                $planned = $this->prepareChemicals();
                $places = $this->prepareStorage($places, $planned);
                $this->noteGaps($places);
                $this->seedFirstAid($places);
                $this->seedChemicalExposures($places);
                $this->seedRestraints($places);
                $this->seedRiskAssessments($places);
            }
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    // ── Who acts, and where ────────────────────────────────────────────

    private function resolveActor(): ?User
    {
        $staff = app(HrCurrentStaffService::class);
        $candidates = User::query()->where('email', 'admin@demo.test')->get()->concat(
            User::query()
                ->where(fn ($q) => $q->where('role', 'admin')->orWhereHas('roles', fn ($r) => $r->where('name', 'admin')))
                ->orderBy('id')
                ->get(),
        );
        foreach ($candidates->unique('id') as $user) {
            if ($user->approved_at !== null && $staff->isCurrent($user) && $user->canDo('hazards.manage')) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Real active Sites with the people and substances found there.
     *
     * @return Collection<int, array{site: Site, staff: Collection<int, User>, firstAiders: Collection<int, User>, clients: Collection<int, Client>, substances: Collection<int, HazardousSubstance>}>
     */
    private function places(): Collection
    {
        $reachable = app(UserSiteAccessService::class)->accessibleSiteIds($this->actor, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        $sites = Site::query()->active()->notArchived()->whereNull('archived_at')->whereIn('id', $reachable)
            ->where(function ($query): void {
                foreach (self::EXCLUDED_SITE_WORDS as $word) {
                    $query->where('name', 'not like', '%'.$word.'%');
                }
            })
            ->orderBy('id')->get();

        $staffBySite = [];
        $currentStaff = app(HrCurrentStaffService::class)->currentUsersQuery()
            ->where('external_clinical_account', false)->with('hrEmployeeProfile')->orderBy('id')->get();
        foreach ($currentStaff as $user) {
            $profile = $user->hrEmployeeProfile;
            $siteIds = collect([$profile?->primary_site_id, ...(is_array($profile?->secondary_site_ids) ? $profile->secondary_site_ids : [])])
                ->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id)->unique();
            foreach ($siteIds as $siteId) {
                $staffBySite[$siteId][] = $user;
            }
        }
        $clientsBySite = Client::query()->whereIn('site_id', $sites->pluck('id'))->where('status', 'active')
            ->orderBy('id')->get()->groupBy('site_id');

        return $sites->map(function (Site $site) use ($staffBySite, $clientsBySite): array {
            $staff = collect($staffBySite[$site->id] ?? []);
            // The acting admin is only used as a subject when nobody else works there.
            $others = $staff->reject(fn (User $user) => (int) $user->id === (int) $this->actor->id)->values();
            $staff = $others->isNotEmpty() ? $others : $staff;
            $substances = HazardousSubstance::query()->where('status', 'active')
                ->whereIn('id', SubstanceStorageLocation::query()->where('site_id', $site->id)->select('hazardous_substance_id'))
                ->orderBy('id')->get();
            return [
                'site' => $site,
                'staff' => $staff,
                'firstAiders' => $staff->filter(fn (User $user) => $user->hrEmployeeProfile?->is_first_aider === true)->values(),
                'clients' => collect($clientsBySite[$site->id] ?? [])->values(),
                'substances' => $substances,
            ];
        })->values();
    }

    private function noteGaps(Collection $places): void
    {
        foreach ($places as $place) {
            $missing = array_keys(array_filter([
                'no current staff' => $place['staff']->isEmpty(),
                'no first aider on staff' => $place['firstAiders']->isEmpty(),
                'no active clients' => $place['clients']->isEmpty(),
                'no stored hazardous substances' => $place['substances']->isEmpty(),
            ]));
            if ($missing !== []) {
                $this->line('  Note: '.$place['site']->name.' has '.implode(', ', $missing).' — records that need them go to other Sites.');
            }
        }
    }

    // ── Prepare: first aiders ─────────────────────────────────────────

    /** Gives up to FIRST_AIDER_SITES Sites a first aider, changing only profiles that were off. */
    private function prepareFirstAiders(Collection $places): Collection
    {
        $places = $places->all();
        $flagged = $this->setting(self::FIRST_AIDERS_KEY);
        $targets = collect($places)->filter(fn (array $p) => $p['staff']->count() > 1)
            ->sortBy(fn (array $p) => sprintf('%d-%010d', $p['clients']->isEmpty() ? 1 : 0, $p['site']->id))
            ->keys()->take(self::FIRST_AIDER_SITES);
        foreach ($targets as $index) {
            $place = $places[$index];
            if ($place['firstAiders']->isNotEmpty()) {
                $this->count('first_aider', 'present');

                continue;
            }
            $site = $place['site'];
            $candidate = $place['staff']
                ->filter(fn (User $user) => $user->hrEmployeeProfile !== null)
                ->sortBy(fn (User $user) => sprintf('%d-%d-%010d', $this->leadRank($user),
                    (int) $user->hrEmployeeProfile->primary_site_id === (int) $site->id ? 0 : 1, $user->id))
                ->first();
            if (! $candidate) {
                $this->skip('first_aider', $site->name, 'no staff member with an HR profile');

                continue;
            }
            $profile = $candidate->hrEmployeeProfile;
            $label = "{$candidate->name}".($profile->position_title ? " ({$profile->position_title})" : '')." as a first aider at {$site->name}";
            if ($this->dryRun) {
                $this->line("  + would mark {$label}");
            } else {
                try {
                    // The HR profile model, as the HR profile edit screen saves it.
                    $profile->update(['is_first_aider' => true]);
                    $flagged = array_values(array_unique([...$flagged, (int) $profile->id]));
                    $this->saveSetting(self::FIRST_AIDERS_KEY, $flagged);
                    $this->line("  + marked {$label}");
                } catch (Throwable $error) {
                    $this->skip('first_aider', $site->name, $this->reason($error));

                    continue;
                }
            }
            $this->count('first_aider', 'created');
            $places[$index]['firstAiders'] = collect([$candidate]);
        }

        return collect($places);
    }

    /** 0 = team lead / coordinator by access role, 1 = by HR job title only, 2 = anyone else. */
    private function leadRank(User $user): int
    {
        $roles = [$user->role, ...$user->roles()->pluck('name')->all()];
        if (array_intersect(['team_lead', 'coordinator'], $roles) !== []) {
            return 0;
        }
        $title = $user->hrEmployeeProfile?->position_title.' '.$user->hrEmployeeProfile?->position_role;

        return preg_match('/team ?lead|coordinator|senior support/i', $title) === 1 ? 1 : 2;
    }

    private function restoreFirstAiders(): void
    {
        $flagged = $this->setting(self::FIRST_AIDERS_KEY);
        $kept = [];
        foreach ($flagged as $id) {
            $profile = HrEmployeeProfile::query()->find($id);
            if (! $profile || $profile->is_first_aider !== true) {
                continue; // Already off (or gone): nothing of ours left to undo.
            }
            $name = $profile->user?->name ?? 'HR profile '.$id;
            if ($this->dryRun) {
                $this->line("  - would turn off the first-aider flag for {$name}");
                $this->count('first_aider', 'removed');
                $kept[] = $id;

                continue;
            }
            try {
                $profile->update(['is_first_aider' => false]);
                $this->line("  - turned off the first-aider flag for {$name}");
                $this->count('first_aider', 'removed');
            } catch (Throwable $error) {
                $kept[] = $id;
                $this->count('first_aider', 'left');
                $this->line("  · {$name}: first-aider flag left on — ".$this->reason($error));
            }
        }
        if (! $this->dryRun && $flagged !== []) {
            $this->saveSetting(self::FIRST_AIDERS_KEY, $kept);
        }
    }

    // ── Prepare: chemical register ────────────────────────────────────

    private function substanceSpecs(): array
    {
        return [
            ['substance-1', [
                'name' => 'Sodium hypochlorite bleach', 'common_name' => 'Bleach', 'physical_form' => 'liquid',
                'un_number' => 'UN1791', 'hsno_approval' => 'Cleaning Products (Corrosive) Group Standard 2020',
                'hsno_classification' => 'Skin corrosion 1B; serious eye damage 1; hazardous to the aquatic environment, acute 1',
                'hazard_classifications' => ['Corrosive', 'Eco-toxic'], 'ghs_pictograms' => ['GHS05', 'GHS09'], 'signal_word' => 'Danger',
                'hazard_statements' => 'H314 Causes severe skin burns and eye damage. H400 Very toxic to aquatic life. EUH031 Contact with acids liberates toxic gas. Household-strength sodium hypochlorite solution, about 3–5%.',
                'precautionary_statements' => 'P260 Do not breathe mist or vapour. P280 Wear protective gloves and eye protection. P301+P330+P331 If swallowed: rinse mouth, do not induce vomiting. P305+P351+P338 If in eyes: rinse cautiously with water for several minutes; remove contact lenses if easy to do. P273 Avoid release to the environment.',
                'ppe_required' => 'Waterproof gloves (nitrile or PVC) and safety glasses. Add a plastic apron when decanting or mixing.',
                'storage_requirements' => 'Keep in the original labelled container with the cap closed, upright, in a cool locked cupboard out of direct sun. Keep apart from acids (e.g. toilet and descaling cleaners) and ammonia-based cleaners — mixing releases toxic chlorine or chloramine gas.',
                'handling_precautions' => 'Use in a well-ventilated room. Never mix with other cleaning products. Dilute by adding bleach to water, not water to bleach.',
                'first_aid_measures' => 'Eyes: rinse immediately with clean running water for at least 15 minutes, holding the eyelids open, and get medical advice. Skin: remove contaminated clothing and wash the skin well with water. Swallowed: rinse the mouth and give a glass of water; do not induce vomiting. Breathed in: move to fresh air. For advice call the National Poisons Centre on 0800 POISON (0800 764 766); in an emergency call 111.',
                'firefighting_measures' => 'Not flammable. Use an extinguishing agent suited to the surrounding fire. Heating can release chlorine gas — keep containers cool and stay upwind.',
                'spill_procedures' => 'Ventilate the area and wear gloves and eye protection. Contain with absorbent material or old towels, then rinse the area with plenty of water. Keep spills out of drains and waterways where possible.',
                'requires_tracking' => true, 'is_controlled_substance' => false,
            ]],
            ['substance-2', [
                'name' => 'Caustic oven cleaner', 'common_name' => 'Oven cleaner (aerosol)', 'physical_form' => 'aerosol',
                'un_number' => 'UN1950', 'hsno_approval' => 'Aerosols (Flammable) Group Standard 2020',
                'hsno_classification' => 'Aerosol 1; skin corrosion 1A; serious eye damage 1',
                'hazard_classifications' => ['Flammable', 'Corrosive'], 'ghs_pictograms' => ['GHS02', 'GHS05'], 'signal_word' => 'Danger',
                'hazard_statements' => 'H222 Extremely flammable aerosol. H229 Pressurised container: may burst if heated. H314 Causes severe skin burns and eye damage. Contains sodium hydroxide.',
                'precautionary_statements' => 'P210 Keep away from heat, sparks and open flames — no smoking. P211 Do not spray on an open flame or a hot oven. P251 Do not pierce or burn, even after use. P280 Wear protective gloves and eye protection. P410+P412 Protect from sunlight; do not expose to temperatures above 50 °C.',
                'ppe_required' => 'Chemical-resistant gloves with long cuffs and safety glasses or goggles. Keep forearms covered.',
                'storage_requirements' => 'Store below 50 °C in a locked cupboard away from the stove, heaters and direct sun. Keep apart from acids and aluminium. Keep the cap on.',
                'handling_precautions' => 'Only use on a cold oven with the kitchen window open and the rangehood on. Spray away from the face; do not use near people with breathing difficulties.',
                'exposure_limit_type' => 'WES-Ceiling', 'exposure_limit_value' => '2 mg/m³ (sodium hydroxide)',
                'first_aid_measures' => 'Eyes: rinse immediately with clean running water for at least 15 minutes and get urgent medical help. Skin: wash off with plenty of water for 15 minutes and remove contaminated clothing. Swallowed: rinse the mouth and give a glass of water; do not induce vomiting. Breathed in: move to fresh air and rest. For advice call the National Poisons Centre on 0800 POISON (0800 764 766); in an emergency call 111.',
                'firefighting_measures' => 'Extremely flammable aerosol. Containers may burst when heated — cool them with water spray from a distance. Use dry chemical, foam or carbon dioxide on the surrounding fire.',
                'spill_procedures' => 'Ventilate the area and remove ignition sources. Wear gloves and eye protection, wipe up residue with damp paper towels and rinse the surface with water. Put leaking cans in a sealed bag outside for hazardous waste collection.',
                'requires_tracking' => true, 'is_controlled_substance' => false,
            ]],
        ];
    }

    /**
     * Adds the two chemicals to the register (or replays them if already there).
     *
     * @return Collection<int, HazardousSubstance> in a dry run, the unsaved chemicals that would be added
     */
    private function prepareChemicals(): Collection
    {
        $registry = $this->setting(self::CHEMICALS_KEY);
        $planned = collect();
        $added = false;
        foreach ($this->substanceSpecs() as [$name, $fields]) {
            [$state, $key] = $this->substanceSlot($name);
            if ($state === 'present') {
                $this->count('substance', 'present');
                $stored = $registry[$key] ?? null;
                if (! $this->dryRun && is_array($stored)) {
                    try {
                        app(ChemicalCommandService::class)->create($this->actor, $stored);
                        $this->line("  = {$name}: already there (command replayed)");
                    } catch (Throwable $error) {
                        $this->line("  = {$name}: already there (replay not accepted: ".$this->reason($error).')');
                    }
                }

                continue;
            }
            if ($state === 'exhausted') {
                $this->skip('substance', $name, 'too many earlier copies were removed');

                continue;
            }
            $command = ['idempotency_key' => $key, ...$fields];
            $this->attempt('substance', $name, "{$fields['name']} in the chemical register", function () use ($command, $key, &$registry, &$added) {
                app(ChemicalCommandService::class)->create($this->actor, $command);
                $registry[$key] = $command;
                $this->saveSetting(self::CHEMICALS_KEY, $registry);
                $added = true;
            });
            if ($this->dryRun) {
                $planned->push((new HazardousSubstance)->forceFill([...$fields, 'status' => 'active', 'source_revision' => 0]));
                $added = true;
            }
        }
        if ($added) {
            $this->line('  · No safety data sheet added: the SDS command only accepts an uploaded document.');
        }

        return $planned;
    }

    /** @return array{0: string, 1: ?string} [create|present|exhausted, key] — "present" means an active chemical of ours. */
    private function substanceSlot(string $name): array
    {
        for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
            $key = self::key($name, $generation);
            $receipt = ChemicalCommandReceipt::query()->where('idempotency_key', $key)->where('source_type', 'catalogue')->first(['root_id']);
            if (! $receipt) {
                return ChemicalCommandIntent::query()->where('idempotency_key', $key)->exists() ? ['present', $key] : ['create', $key];
            }
            $row = HazardousSubstance::withTrashed()->find($receipt->root_id, ['id', 'status', 'deleted_at']);
            if ($row && $row->deleted_at === null && $row->status === 'active') {
                return ['present', $key];
            }
        }

        return ['exhausted', null];
    }

    /** Marks our chemicals "Removed" through the register's status command (run after their storage is archived). */
    private function removeChemicals(): void
    {
        foreach ($this->substanceSpecs() as [$name]) {
            for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
                $receipt = ChemicalCommandReceipt::query()->where('idempotency_key', self::key($name, $generation))->where('source_type', 'catalogue')->first(['root_id']);
                if (! $receipt) {
                    break;
                }
                $row = HazardousSubstance::withTrashed()->find($receipt->root_id);
                if (! $row || $row->deleted_at !== null || $row->status !== 'active') {
                    continue;
                }
                $command = ['idempotency_key' => self::key($name.':remove', $generation), 'expected_version' => (int) $row->source_revision,
                    'status' => 'removed', 'reason' => 'No longer used at our houses.'];
                $this->removal('substance', $name, 'mark "'.$row->name.'" as Removed in the chemical register',
                    fn () => app(ChemicalCommandService::class)->status((int) $row->id, $this->actor, $command));
            }
        }
    }

    // ── Prepare: substance storage ────────────────────────────────────

    private function storageSpecs(): array
    {
        return [
            ['storage-1', ['bleach', 'hypochlorite', 'chlorine'], [
                'location_description' => 'Locked cleaning cupboard, laundry', 'current_quantity' => '4', 'maximum_quantity' => '10',
                'quantity_unit' => 'litres', 'container_type' => '2 L bottles', 'properly_labelled' => true, 'segregation_compliant' => true,
                'storage_notes' => 'Top shelf, away from the ammonia-based cleaners. Gloves and safety glasses hang inside the door.',
            ]],
            ['storage-2', ['oven', 'caustic', 'degreaser'], [
                'location_description' => 'Child-locked cupboard under the kitchen sink', 'current_quantity' => '2', 'maximum_quantity' => '4',
                'quantity_unit' => 'cans', 'container_type' => '500 g aerosol cans', 'properly_labelled' => true, 'segregation_compliant' => true,
                'storage_notes' => 'Only used with the window open and the rangehood on.',
            ]],
        ];
    }

    /** Gives up to STORAGE_SITES Sites without stored substances a storage location. */
    private function prepareStorage(Collection $places, Collection $planned): Collection
    {
        $places = $places->all();
        $registry = $this->setting(self::STORAGE_KEY);
        $needy = collect($places)->filter(fn (array $p) => $p['staff']->isNotEmpty() && $p['substances']->isEmpty())->keys()->take(self::STORAGE_SITES)->values();
        // In a dry run the chemicals prepareChemicals() would add are not saved yet; they stand in here.
        $catalogue = HazardousSubstance::query()->where('status', 'active')->orderBy('id')->get()->concat($planned)->values();
        foreach ($this->storageSpecs() as $index => [$name, $words, $fields]) {
            [$state, $key] = $this->storageSlot($name);
            if ($state === 'present') {
                $this->count('storage', 'present');
                $this->replayStorage($name, $key, $registry[$key] ?? null);

                continue;
            }
            if ($state === 'exhausted') {
                $this->skip('storage', $name, 'too many earlier copies were removed');

                continue;
            }
            $placeIndex = $needy[$index] ?? null;
            if ($placeIndex === null) {
                $this->line("  · {$name}: not needed — no other Site is missing a stored substance.");

                continue;
            }
            if ($catalogue->isEmpty()) {
                $this->skip('storage', $name, 'no active hazardous substance in the register');

                continue;
            }
            $site = $places[$placeIndex]['site'];
            $substance = $catalogue->first(fn (HazardousSubstance $s) => collect($words)->contains(fn ($w) => stripos($s->name.' '.$s->common_name, $w) !== false))
                ?? $catalogue[$index % $catalogue->count()];
            $zone = (string) (config('app.worker_timezone') ?: 'Pacific/Auckland');
            $revision = (int) HazardousSubstance::query()->whereKey($substance->id)->value('source_revision');
            $command = ['idempotency_key' => $key, 'parent_expected_version' => $revision, 'site_id' => (int) $site->id,
                ...$fields, 'last_audit_date' => CarbonImmutable::now($zone)->subDays(12 + 9 * $index)->toDateString()];
            $label = "{$substance->name} — {$fields['location_description']}, {$site->name}";
            $added = false;
            $this->attempt('storage', $name, $label, function () use ($substance, $command, $key, &$registry, &$added) {
                app(ChemicalCommandService::class)->createStorage($substance->id, $this->actor, $command);
                $registry[$key] = ['substance_id' => (int) $substance->id, 'command' => $command];
                $this->saveSetting(self::STORAGE_KEY, $registry);
                $added = true;
            });
            if ($added || $this->dryRun) {
                $places[$placeIndex]['substances'] = collect([$substance]);
            }
        }

        return collect($places);
    }

    /** @return array{0: string, 1: ?string} [create|present|exhausted, key] */
    private function storageSlot(string $name): array
    {
        for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
            $key = self::key($name, $generation);
            $receipt = ChemicalCommandReceipt::query()->where('idempotency_key', $key)->where('source_type', 'storage')->first(['source_id']);
            if (! $receipt) {
                // A claimed key without a receipt is never reused for different work.
                return ChemicalCommandIntent::query()->where('idempotency_key', $key)->exists() ? ['present', $key] : ['create', $key];
            }
            $row = SubstanceStorageLocation::withTrashed()->find($receipt->source_id, ['id', 'deleted_at']);
            if ($row && $row->deleted_at === null) {
                return ['present', $key];
            }
        }

        return ['exhausted', null];
    }

    /** Sends the identical original command again: the service answers from its receipt. */
    private function replayStorage(string $name, string $key, ?array $stored): void
    {
        if ($this->dryRun || ! is_array($stored) || ! isset($stored['substance_id'], $stored['command'])) {
            return;
        }
        try {
            app(ChemicalCommandService::class)->createStorage((int) $stored['substance_id'], $this->actor, $stored['command']);
            $this->line("  = {$name}: already there (command replayed)");
        } catch (Throwable $error) {
            $this->line("  = {$name}: already there (replay not accepted: ".$this->reason($error).')');
        }
    }

    private function removeStorage(): void
    {
        foreach ($this->storageSpecs() as [$name]) {
            for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
                $receipt = ChemicalCommandReceipt::query()->where('idempotency_key', self::key($name, $generation))->where('source_type', 'storage')->first(['source_id']);
                if (! $receipt) {
                    break;
                }
                $row = SubstanceStorageLocation::withTrashed()->find($receipt->source_id);
                if (! $row || $row->deleted_at !== null) {
                    continue;
                }
                $parent = HazardousSubstance::withTrashed()->find($row->hazardous_substance_id);
                $command = ['idempotency_key' => self::key($name.':archive', $generation), 'expected_version' => (int) $row->source_revision,
                    'parent_expected_version' => (int) $parent?->source_revision, 'reason' => 'No longer kept at this location.'];
                $this->removal('storage', $name, 'archive storage location "'.$row->location_description.'"',
                    fn () => app(ChemicalCommandService::class)->archiveStorage((int) $row->hazardous_substance_id, (int) $row->id, $this->actor, $command));
            }
        }
    }

    /** The n-th eligible place, so each record keeps landing on the same Site. */
    private function placeFor(Collection $places, int $index, callable $eligible): ?array
    {
        $fits = $places->filter($eligible)->values();

        return $fits->isEmpty() ? null : $fits[$index % $fits->count()];
    }

    private function at(int $daysAgo, string $wallTime): CarbonImmutable
    {
        $zone = (string) (config('app.worker_timezone') ?: 'Pacific/Auckland');
        $now = CarbonImmutable::now($zone);
        $local = $now->subDays($daysAgo)->setTimeFromTimeString($wallTime);
        if ($local->greaterThan($now)) {
            $local = $local->subDay();
        }

        return $local->utc();
    }

    private static function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function clientName(Client $client): string
    {
        return trim(($client->preferred_name ?: $client->first_name).' '.$client->last_name);
    }

    // ── First aid ─────────────────────────────────────────────────────

    private function firstAidSpecs(): array
    {
        return [
            ['first-aid-1', 'staff', 3, '17:40', 'cut', 'Cut to the left index finger while slicing kūmara for dinner.', 'Left index finger',
                'Washed with saline, firm pressure until the bleeding stopped, covered with a waterproof plaster.', 'returned_to_activity', 'Clean cut, about 1 cm. Gloves offered for the rest of meal prep.'],
            ['first-aid-2', 'client', 11, '08:15', 'fall', 'Slipped on the wet path coming back from the letterbox after overnight rain and landed on the right hip. Got up with help from two staff.', 'Right hip',
                'Falls check completed, no deformity or loss of movement. Ice pack for 15 minutes, observations every 30 minutes for two hours.', 'ongoing_monitoring', 'Some bruising expected. Path flagged for a non-slip treatment.'],
            ['first-aid-3', 'client', 24, '13:30', 'sting', 'Stung by a bee on the right forearm during the afternoon walk around Hamilton Gardens.', 'Right forearm',
                'Sting scraped out, cold pack applied, watched for 30 minutes for any allergic reaction — none seen.', 'returned_to_activity', 'No known bee allergy on file. Whānau told at pick-up.'],
            ['first-aid-4', 'staff', 37, '15:05', 'sprain', 'Rolled the left ankle stepping down from the van in the car park at Raglan.', 'Left ankle',
                'Rest, ice, compression bandage and elevation. Unable to bear full weight after 20 minutes.', 'sent_home', 'Shift covered by the on-call worker. Advised to see their GP if not improving in 48 hours.'],
            ['first-aid-5', 'client', 52, '19:20', 'cut', 'Skin tear on the back of the left hand, caught on the bedroom door frame while walking with the walker.', 'Back of left hand',
                'Cleaned with saline, flap eased back into place with steri-strips, non-stick dressing applied.', 'medical_centre', 'Booked with the practice nurse at the local medical centre the next morning for a wound check.'],
            ['first-aid-6', 'staff', 68, '11:45', 'burn', 'Minor burn to the right wrist from a hot oven tray while baking scones.', 'Right wrist',
                'Cooled under cool running water for 20 minutes, covered loosely with cling film.', 'returned_to_activity', 'Superficial, about 2 cm. Long oven mitts now kept by the oven.'],
        ];
    }

    private function seedFirstAid(Collection $places): void
    {
        foreach ($this->firstAidSpecs() as $index => [$name, $subject, $daysAgo, $time, $type, $what, $bodyPart, $treatment, $outcome, $notes]) {
            [$state, $key] = $this->firstAidSlot($name);
            if ($state === 'present') {
                $this->count('first_aid', 'present');

                continue;
            }
            $place = $this->placeFor($places, $index, fn (array $p) => $p['firstAiders']->isNotEmpty()
                && ($subject === 'client' ? $p['clients']->isNotEmpty() : $p['staff']->count() > 1));
            if (! $place || $state === 'exhausted') {
                $this->skip('first_aid', $name, $place ? 'too many earlier copies were removed' : 'no Site has a first aider and a '.($subject === 'client' ? 'client' : 'second staff member'));

                continue;
            }
            $firstAider = $place['firstAiders'][$index % $place['firstAiders']->count()];
            $data = ['site_id' => $place['site']->id, 'treated_person_type' => $subject, 'treatment_date' => self::iso($this->at($daysAgo, $time)),
                'injury_illness_type' => $type, 'injury_illness_description' => $what, 'body_part' => $bodyPart, 'treatment_given' => $treatment,
                'treatment_outcome' => $outcome, 'ambulance_called' => false, 'first_aider_id' => $firstAider->id, 'first_aider_notes' => $notes,
                'incident_reported' => false, 'idempotency_key' => $key];
            if ($subject === 'client') {
                $client = $place['clients'][$index % $place['clients']->count()];
                $data += ['client_id' => $client->id, 'treated_person_id' => null, 'treated_person_name' => self::clientName($client)];
            } else {
                $others = $place['staff']->reject(fn (User $user) => (int) $user->id === (int) $firstAider->id)->values();
                $person = $others[$index % $others->count()];
                $data += ['client_id' => null, 'treated_person_id' => $person->id, 'treated_person_name' => $person->name];
            }
            $label = "{$type} — {$data['treated_person_name']} at {$place['site']->name}, {$daysAgo} days ago";
            $this->attempt('first_aid', $name, $label, fn () => app(FirstAidService::class)->create($this->actor, $data));
        }
    }

    /** @return array{0: string, 1: ?string} [create|present|exhausted, key to create with] */
    private function firstAidSlot(string $name): array
    {
        for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
            $key = self::key($name, $generation);
            $row = FirstAidRecord::withoutGlobalScopes()->where('creation_key', $key)->first(['id', 'deleted_at']);
            if (! $row) {
                return ['create', $key];
            }
            if ($row->deleted_at === null) {
                return ['present', null];
            }
        }

        return ['exhausted', null];
    }

    private function removeFirstAid(): void
    {
        foreach ($this->firstAidSpecs() as [$name]) {
            for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
                $row = FirstAidRecord::withoutGlobalScopes()->where('creation_key', self::key($name, $generation))->first();
                if (! $row) {
                    break;
                }
                if ($row->deleted_at !== null) {
                    continue;
                }
                $command = ['expected_version' => (int) $row->lock_version, 'idempotency_key' => self::key($name.':archive', $generation)];
                $this->removal('first_aid', $name, 'archive first aid '.$row->reference_number,
                    fn () => app(FirstAidService::class)->archive($row, $this->actor, $command));
            }
        }
    }

    // ── Chemical exposures ────────────────────────────────────────────

    private function chemicalSpecs(): array
    {
        return [
            ['chemical-exposure-1', ['bleach', 'hypochlorite', 'chlorine'], 6, '10:20', 'eye_contact', 'A few seconds',
                'Splash of %s to the right eye while wiping down the shower walls during the morning clean. Safety glasses were in the cupboard but not worn.',
                'Stinging and watering of the right eye, mild redness.', 'Eye flushed with clean running water at the eyewash station for 15 minutes.',
                'first_aid', false, 'Redness settled within the hour. Reminder about eye protection given at the team meeting.'],
            ['chemical-exposure-2', ['oven', 'caustic', 'degreaser'], 29, '14:40', 'inhalation', 'About 5 minutes',
                'Breathed in fumes from %s while cleaning the oven with the kitchen window shut and the rangehood off.',
                'Coughing, sore throat and a headache.', 'Moved outside into fresh air, given water, kitchen ventilated.',
                'medical', true, 'Saw the GP that afternoon — no lasting effects. Now only used with the window open and the rangehood on.'],
            ['chemical-exposure-3', ['dish', 'detergent', 'floor', 'sanitiser', 'disinfectant'], 58, '20:10', 'skin_contact', 'About 10 minutes',
                'Diluted %s soaked through a split in the left glove while mopping the bathroom floor.',
                'Dry, itchy skin and mild redness on the left hand.', 'Glove removed, hand rinsed under running water for 10 minutes, moisturiser applied.',
                'first_aid', false, 'Settled by the next shift. Gloves stock checked and replaced.'],
        ];
    }

    private function seedChemicalExposures(Collection $places): void
    {
        foreach ($this->chemicalSpecs() as $index => [$name, $words, $daysAgo, $time, $type, $duration, $circumstances, $symptoms, $firstAid, $treatment, $sought, $outcome]) {
            $key = self::key($name);
            if (ChemicalCommandIntent::query()->where('idempotency_key', $key)->exists()) {
                $this->count('chemical', 'present');

                continue;
            }
            $place = $this->placeFor($places, $index, fn (array $p) => $p['staff']->isNotEmpty() && $p['substances']->isNotEmpty());
            if (! $place) {
                $this->skip('chemical', $name, 'no Site has both staff and a stored hazardous substance');

                continue;
            }
            $substance = $place['substances']->first(fn (HazardousSubstance $s) => collect($words)->contains(fn ($w) => stripos($s->name.' '.$s->common_name, $w) !== false))
                ?? $place['substances'][$index % $place['substances']->count()];
            $worker = $place['staff'][$index % $place['staff']->count()];
            $data = ['idempotency_key' => $key, 'user_id' => $worker->id, 'site_id' => $place['site']->id,
                'exposed_at' => self::iso($this->at($daysAgo, $time)), 'exposure_type' => $type, 'exposure_duration' => $duration,
                'circumstances' => sprintf($circumstances, $substance->common_name ?: $substance->name), 'symptoms' => $symptoms,
                'first_aid_given' => $firstAid, 'medical_treatment' => $treatment, 'medical_attention_sought' => $sought,
                'medical_outcome' => $outcome, 'incident_reported' => false];
            $label = "{$type} — {$worker->name} with {$substance->name} at {$place['site']->name}, {$daysAgo} days ago";
            $this->attempt('chemical', $name, $label, fn () => app(ChemicalExposureSourceService::class)->create($substance->id, $this->actor, $data));
        }
    }

    // ── Restraints ────────────────────────────────────────────────────

    /**
     * All three are recorded within the support plan: an out-of-plan or
     * injury restraint also raises a live Control Room alert, which would
     * be misleading for an event weeks old.
     */
    private function restraintSpecs(): array
    {
        return [
            ['restraint-1', 9, '16:05', 4, 'physical', 'medium',
                'Became very upset when the planned visit from whānau was cancelled at short notice, and started hitting their head against the hallway wall.',
                'Offered the quiet lounge and their sensory box, played a favourite waiata, spoke slowly and calmly, and gave space as set out in the plan.',
                'Two staff used the approved two-person arm hold to prevent a head injury, for about four minutes, and released it as soon as they were calm.',
                'Settled with a cup of tea and a weighted blanket in the lounge. Whānau phoned that evening to rearrange the visit.'],
            ['restraint-2', 33, '17:30', 20, 'environmental', 'low',
                'Kept trying to use the stove on high heat while staff were cooking dinner.',
                'Redirected to setting the table and offered a snack and a drink at the bench.',
                'Kitchen door locked for 20 minutes while dinner was plated, as allowed by the environmental restriction in the support plan.',
                'Happy to help carry the plates through once the door was unlocked.'],
            ['restraint-3', 61, '11:10', 2, 'physical', 'medium',
                'On the walk along the river path, ran towards the road when a bus went past.',
                'Verbal prompts using their name, and offered to walk to the café for a hot chocolate instead.',
                'One staff member used the escort hold in the plan for about two minutes to guide them back onto the footpath, then let go.',
                'Calm again by the café. Talked through it together on the walk home.'],
        ];
    }

    private function seedRestraints(Collection $places): void
    {
        foreach ($this->restraintSpecs() as $index => [$name, $daysAgo, $time, $minutes, $type, $severity, $trigger, $deEscalation, $description, $response]) {
            $key = self::key($name);
            if (RestraintEvent::withoutGlobalScopes()->where('creation_key', $key)->exists()) {
                $this->count('restraint', 'present');

                continue;
            }
            $place = $this->placeFor($places, $index, fn (array $p) => $p['clients']->isNotEmpty());
            if (! $place) {
                $this->skip('restraint', $name, 'no Site has an active client');

                continue;
            }
            $client = $place['clients'][$index % $place['clients']->count()];
            $start = $this->at($daysAgo, $time);
            $staffInvolved = $place['staff']->take($type === 'physical' && $index === 0 ? 2 : 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $data = ['idempotency_key' => $key, 'client_id' => $client->id, 'site_id' => $place['site']->id,
                'started_at' => self::iso($start), 'ended_at' => self::iso($start->addMinutes($minutes)),
                'restraint_type' => $type, 'severity' => $severity, 'trigger_description' => $trigger,
                'de_escalation_attempted' => $deEscalation, 'restraint_description' => $description,
                'staff_involved' => $staffInvolved, 'person_response' => $response,
                'injury_occurred' => false, 'within_support_plan' => true];
            $label = "{$type} — ".self::clientName($client)." at {$place['site']->name}, {$daysAgo} days ago";
            $this->attempt('restraint', $name, $label, fn () => app(RestraintService::class)->create($this->actor, $data));
        }
    }

    // ── Risk assessments ──────────────────────────────────────────────

    private function riskSpecs(): array
    {
        return [
            ['risk-assessment-1', true, [
                'title' => 'Manual handling — bed-to-chair transfers for a resident',
                'risk_description' => 'One resident needs help with every transfer between bed, wheelchair and shower chair. Staff could strain their back or shoulders, and the resident could slip or fall during a transfer.',
                'likelihood' => 3, 'consequence' => 4,
                'existing_controls' => 'Ceiling hoist in the bedroom and a mobile hoist for the bathroom. Two-person transfers as set out in the moving and handling plan. Slide sheets on every bed.',
                'additional_controls' => 'Moving and handling refresher with the physiotherapist for all staff this year. Check sling sizes at each six-monthly review.',
                'residual_likelihood' => 2, 'residual_consequence' => 3, 'risk_acceptable' => true, 'review_frequency_days' => 180,
            ], 'Approved at the house meeting with the physiotherapist’s plan attached.'],
            ['risk-assessment-2', true, [
                'title' => 'Hot water and kitchen burns',
                'risk_description' => 'Residents use the kitchen and bathrooms with support. Scalds from hot taps, the kettle or the stove could cause serious burns, especially for residents with reduced feeling in their hands.',
                'likelihood' => 3, 'consequence' => 3,
                'existing_controls' => 'Tempering valve on the hot water cylinder so taps deliver no hotter than 45 °C. Kettle kept at the back of the bench. Staff supervise stove use.',
                'additional_controls' => 'Monthly hot-tap temperature check recorded on the house checklist. Induction hob to be priced for the next kitchen upgrade.',
                'residual_likelihood' => 1, 'residual_consequence' => 3, 'risk_acceptable' => true, 'review_frequency_days' => 365,
            ], 'Approved. Tap temperatures added to the monthly checklist.'],
            ['risk-assessment-3', false, [
                'title' => 'Lone working on night shifts',
                'risk_description' => 'One sleepover staff member covers the house overnight. If they are unwell, injured or facing an aggressive situation there may be no one nearby to help.',
                'likelihood' => 2, 'consequence' => 4,
                'existing_controls' => 'Lone-worker check-in on the app every two hours, on-call manager available all night, duress pendant worn on shift.',
                'additional_controls' => 'Trial a second waking staff member when the house is at full occupancy. Add the neighbour house’s night staff as a back-up contact.',
                'residual_likelihood' => 2, 'residual_consequence' => 3, 'risk_acceptable' => null, 'review_frequency_days' => 90,
            ], null],
        ];
    }

    private function seedRiskAssessments(Collection $places): void
    {
        $registry = $this->registry();
        foreach ($this->riskSpecs() as $index => [$name, $approve, $fields, $approverNote]) {
            [$state, $key] = $this->riskSlot($name, $registry);
            if ($state === 'present') {
                $this->count('risk', 'present');

                continue;
            }
            if ($state === 'exhausted') {
                $this->skip('risk', $name, 'too many earlier copies were removed');

                continue;
            }
            $place = $this->placeFor($places, $index, fn () => true);
            $label = "\"{$fields['title']}\" at {$place['site']->name}".($approve ? ', approved' : ', draft');
            $this->attempt('risk', $name, $label, function () use ($fields, $place, $approve, $approverNote, $key, &$registry) {
                $service = app(HsRiskAssessmentService::class);
                $assessment = $service->create([...$fields, 'assessable_type' => Site::class, 'assessable_id' => $place['site']->id], $this->actor);
                $registry[$key] = (int) $assessment->id;
                $this->saveRegistry($registry);
                if ($approve) {
                    $service->activate($assessment, ['approver_note' => $approverNote], $this->actor);
                }

                return $assessment;
            });
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function riskSlot(string $name, array $registry): array
    {
        for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
            $key = self::key($name, $generation);
            $id = $registry[$key] ?? null;
            if ($id === null) {
                return ['create', $key];
            }
            $row = HsRiskAssessment::withoutGlobalScopes()->find($id, ['id', 'status', 'deleted_at']);
            if ($row && $row->deleted_at === null && $row->status !== HsRiskAssessment::STATUS_ARCHIVED) {
                return ['present', null];
            }
        }

        return ['exhausted', null];
    }

    private function removeRiskAssessments(): void
    {
        $registry = $this->registry();
        $ours = [];
        foreach ($this->riskSpecs() as [$name]) {
            for ($generation = 1; $generation <= self::MAX_GENERATIONS; $generation++) {
                $key = self::key($name, $generation);
                if (! isset($registry[$key])) {
                    break;
                }
                $ours[$registry[$key]] = $name;
            }
        }
        foreach ($ours as $id => $name) {
            $row = HsRiskAssessment::withoutGlobalScopes()->find($id);
            if (! $row || $row->deleted_at !== null || $row->status === HsRiskAssessment::STATUS_ARCHIVED) {
                continue;
            }
            if (! in_array($row->status, [HsRiskAssessment::STATUS_DRAFT, HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW], true)) {
                $this->count('risk', 'left');
                $this->line("  · {$name}: left in place (status {$row->status} cannot be archived)");

                continue;
            }
            $this->removal('risk', $name, 'archive risk assessment '.$row->reference_number,
                fn () => app(HsRiskAssessmentService::class)->archive($row, $this->actor));
        }
    }

    private function registry(): array
    {
        return array_map('intval', $this->setting(self::REGISTRY_KEY));
    }

    private function saveRegistry(array $registry): void
    {
        $this->saveSetting(self::REGISTRY_KEY, $registry);
    }

    private function setting(string $key): array
    {
        $value = AppSetting::query()->where('key', $key)->value('value');
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? $value : [];
    }

    private function saveSetting(string $key, array $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    // ── Records with no archive command ───────────────────────────────

    private function countLeftInPlace(): void
    {
        $restraintKeys = array_map(fn (array $spec) => self::key($spec[0]), $this->restraintSpecs());
        $restraints = RestraintEvent::withoutGlobalScopes()->whereIn('creation_key', $restraintKeys)->count();
        $chemicalKeys = array_map(fn (array $spec) => self::key($spec[0]), $this->chemicalSpecs());
        $intentIds = ChemicalCommandIntent::query()->whereIn('idempotency_key', $chemicalKeys)->pluck('id');
        $exposures = ChemicalExposureCommandReceipt::query()->whereIn('intent_id', $intentIds)->distinct()->count('exposure_id');
        $this->summary['restraint']['left'] += $restraints;
        $this->summary['chemical']['left'] += $exposures;
        if ($restraints + $exposures > 0) {
            $this->line("  · Left in place: {$restraints} restraint and {$exposures} chemical exposure record(s) — those registers have no archive command.");
        }
    }

    // ── Bookkeeping ───────────────────────────────────────────────────

    private function attempt(string $kind, string $name, string $label, callable $create): void
    {
        if ($this->dryRun) {
            $this->line("  + would add {$label}");
            $this->count($kind, 'created');

            return;
        }
        try {
            $create();
            $this->line("  + added {$label}");
            $this->count($kind, 'created');
        } catch (Throwable $error) {
            $this->skip($kind, $name, $this->reason($error));
        }
    }

    private function removal(string $kind, string $name, string $label, callable $remove): void
    {
        if ($this->dryRun) {
            $this->line("  - would {$label}");
            $this->count($kind, 'removed');

            return;
        }
        try {
            $remove();
            $this->line("  - {$label}");
            $this->count($kind, 'removed');
        } catch (Throwable $error) {
            $this->count($kind, 'left');
            $this->line("  · {$name}: left in place — ".$this->reason($error));
        }
    }

    private function reason(Throwable $error): string
    {
        return match (true) {
            $error instanceof ValidationException => 'not accepted: '.collect($error->errors())->flatten()->implode(' '),
            $error instanceof HttpExceptionInterface => 'not allowed for this user ('.$error->getStatusCode().($error->getMessage() !== '' ? ': '.$error->getMessage() : '').')',
            default => class_basename($error).': '.$error->getMessage(),
        };
    }

    private function skip(string $kind, string $name, string $why): void
    {
        $this->count($kind, 'skipped');
        $this->line("  · {$name}: skipped — {$why}");
    }

    private function count(string $kind, string $what): void
    {
        $this->summary[$kind][$what]++;
    }

    private function printSummary(): void
    {
        $removing = (bool) $this->option('remove');
        $prefix = $this->dryRun ? 'Would be ' : '';
        $headers = $removing
            ? ['Kind', $prefix.'Removed', 'Left in place']
            : ['Kind', $prefix.'Created', 'Already there', 'Skipped'];
        $rows = [];
        foreach (self::KINDS as $kind => $label) {
            $row = $this->summary[$kind];
            $rows[] = $removing ? [$label, $row['removed'], $row['left']] : [$label, $row['created'], $row['present'], $row['skipped']];
        }
        $this->table($headers, $rows);
        if ($this->dryRun) {
            $this->info('Dry run: nothing was written.');
        }
    }
}

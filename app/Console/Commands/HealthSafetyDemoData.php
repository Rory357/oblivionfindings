<?php

namespace App\Console\Commands;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AppSetting;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HazardousSubstance;
use App\Models\HsRiskAssessment;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
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
 * Re-running skips anything already there. `--remove` archives what the
 * services can archive (first aid, risk assessments); restraint and chemical
 * exposure records have no archive command and are left in place.
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

    /** Sites whose name contains any of these are test fixtures, not demo houses. */
    public const EXCLUDED_SITE_WORDS = ['Playwright', 'RELEASE', 'Codex', 'E2E', 'demo'];

    /** A record removed with --remove is recreated under its next generation key. */
    private const MAX_GENERATIONS = 25;

    private const KINDS = ['first_aid' => 'First aid', 'chemical' => 'Chemical exposures', 'restraint' => 'Restraints', 'risk' => 'Risk assessments'];

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
            $this->countLeftInPlace();
        } else {
            $places = $this->places();
            if ($places->isEmpty()) {
                $this->warn('No active demo Sites this user can reach. Nothing was created.');
            } else {
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
            $place = [
                'site' => $site,
                'staff' => $staff,
                'firstAiders' => $staff->filter(fn (User $user) => $user->hrEmployeeProfile?->is_first_aider === true)->values(),
                'clients' => collect($clientsBySite[$site->id] ?? [])->values(),
                'substances' => $substances,
            ];
            $missing = array_keys(array_filter([
                'no current staff' => $place['staff']->isEmpty(),
                'no first aider on staff' => $place['firstAiders']->isEmpty(),
                'no active clients' => $place['clients']->isEmpty(),
                'no stored hazardous substances' => $substances->isEmpty(),
            ]));
            if ($missing !== []) {
                $this->line('  Note: '.$site->name.' has '.implode(', ', $missing).' — records that need them go to other Sites.');
            }

            return $place;
        })->values();
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
        $value = AppSetting::query()->where('key', self::REGISTRY_KEY)->value('value');
        $value = is_string($value) ? json_decode($value, true) : $value;

        return is_array($value) ? array_map('intval', $value) : [];
    }

    private function saveRegistry(array $registry): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::REGISTRY_KEY], ['value' => $registry]);
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

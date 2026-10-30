<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Models\MedicationCompetencyExemption;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationCompetencyExemptionService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Medication competency exemptions (eMAR P11, NF-03): one person, one house,
 * a reason and an end date within the organisation's longest exemption
 * (Settings › Staff & PINs). They end by themselves, or early with a reason.
 *
 * Granting needs medications.competency.exempt, access to the house, and
 * someone other than yourself; the service re-checks all of it under locks
 * and audits each grant and early end.
 */
class CompetencyExemptionController extends Controller
{
    public function __construct(
        private readonly MedicationCompetencyExemptionService $exemptions,
        private readonly MedicationAdministratorCompetencyPolicy $competency,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'site_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
        ], [
            'reason.min' => 'Say why, in at least 10 characters.',
            'ends_on.after' => 'The end date must be after the start date.',
        ]);

        // Someone outside the houses this person can reach isn't there to grant.
        $site = Site::query()->whereKey((int) $validated['site_id'])->first();
        abort_unless($site !== null && in_array((int) $site->id, $this->reachableSiteIds($actor), true), 404);
        $subject = User::query()->staff()->whereKey((int) $validated['user_id'])->whereNotNull('approved_at')->first();
        abort_unless($subject !== null && $this->worksAt($subject, (int) $site->id), 404);

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $startsAt = CarbonImmutable::parse($validated['starts_on'], $timezone)->startOfDay();
        $now = CarbonImmutable::now($timezone);
        if ($startsAt->lessThan($now->startOfDay())) {
            throw ValidationException::withMessages(['starts_on' => 'An exemption can’t start in the past.']);
        }
        $startsAt = $startsAt->greaterThan($now) ? $startsAt : $now;
        $endsAt = CarbonImmutable::parse($validated['ends_on'], $timezone)->endOfDay();

        // An exemption is for someone without a current assessment, once.
        $decision = $this->competency->evaluate($subject, (int) $site->id, now());
        if ($decision['state'] === 'valid') {
            throw ValidationException::withMessages(['user_id' => $subject->name.' has a current assessment — no exemption is needed.']);
        }
        if ($decision['state'] === 'exempt') {
            throw ValidationException::withMessages(['user_id' => $subject->name.' already has an exemption at '.$site->name.'.']);
        }

        try {
            // NZ days, stored as UTC instants: Eloquent writes a Carbon's own
            // wall-clock time, so an NZ-zoned end date would be saved 13 hours late.
            $exemption = $this->exemptions->approve($subject, $site, $actor, (string) $validated['reason'], $startsAt->utc(), $endsAt->utc());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $invalid) {
            // The service names its end date expires_at; the form calls it ends_on.
            throw ValidationException::withMessages(collect($invalid->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$key === 'expires_at' ? 'ends_on' : $key => $messages])
                ->all());
        }

        return redirect()->back()->with('success', sprintf(
            'Exemption granted. %s can record doses as given at %s until %s. It ends by itself.',
            $subject->name,
            $site->name,
            $exemption->expires_at->copy()->timezone($timezone)->format('j M Y'),
        ));
    }

    public function end(Request $request, MedicationCompetencyExemption $exemption): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.min' => 'Say why, in at least 10 characters.',
        ]);
        abort_unless(in_array((int) $exemption->site_id, $this->reachableSiteIds($actor), true), 404);

        try {
            $this->exemptions->revoke($exemption, $actor, (string) $validated['reason']);
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()->back()->with('success', 'Exemption ended. They can’t record doses as given until they have a current assessment.');
    }

    /** @return list<int> */
    private function reachableSiteIds(User $actor): array
    {
        return array_values(array_map('intval', $this->siteAccess->accessibleSiteIds($actor, ['sites.viewAll'])));
    }

    private function worksAt(User $subject, int $siteId): bool
    {
        $profile = HrEmployeeProfile::query()->where('user_id', $subject->id)->where('is_active', true)->first();

        return $profile !== null && collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
            ->contains(fn (mixed $id): bool => (int) $id === $siteId);
    }
}

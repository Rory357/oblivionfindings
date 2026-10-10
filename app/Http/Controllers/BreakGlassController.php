<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BreakGlassController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly EmergencyAccessService $grants,
    ) {}

    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);
        $this->authorize('breakGlass', $client);
        $this->assertClientSiteAccess($user, $client, false);
        $policy = BreakGlassPolicy::current();
        $data = $request->validate([
            'reason' => [$policy->reason_required ? 'required' : 'nullable', 'string', 'max:255'],
            'reason_category' => ['required', 'string', 'max:100'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.$policy->max_minutes],
            'authorization_mode' => ['required', Rule::in(['self', 'co_sign'])],
            'co_signed_by' => ['nullable', 'integer', 'required_if:authorization_mode,co_sign'],
            'co_signer_pin' => ['nullable', 'string', 'required_if:authorization_mode,co_sign'],
            'acknowledged_min_necessary' => ['accepted'],
            // Legacy storage key now represents acknowledgement that use is reviewed.
            'acknowledged_incident_report' => ['accepted'],
        ]);
        $this->grants->start($user, $client, $data);

        return back()->with('success', 'Emergency access started. Only this person’s medication record is covered.');
    }

    public function extend(Request $request, Client $client, ClientBreakGlassAccess $access)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);
        $this->assertClientSiteAccess($user, $client, false);
        abort_unless((int) $access->client_id === (int) $client->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->grants->extend($user, $access, $data['reason']);

        return back()->with('success', 'Emergency access extended. Reviewers can see why.');
    }

    public function destroy(Request $request, Client $client, ClientBreakGlassAccess $access)
    {
        $user = $request->user();
        $own = $user && (int) $access->user_id === (int) $user->id;
        abort_unless($user && ($own ? $user->canDo('medications.breakglass') : $user->canDo('medications.breakglass.end')), 403);
        $this->assertClientSiteAccess($user, $client, ! $own);
        abort_unless((int) $access->client_id === (int) $client->id, 404);
        $data = $request->validate(['reason' => [$own ? 'nullable' : 'required', 'string', 'min:10', 'max:1000']]);
        $this->grants->end($user, $access, $data['reason'] ?? null);

        return back()->with('success', 'Emergency access ended. It is waiting for independent review.');
    }

    public function review(Request $request, Client $client, string $access)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);
        $this->authorize('reviewBreakGlass', $client);
        $this->assertClientSiteAccess($user, $client);
        $grant = ClientBreakGlassAccess::withTrashed()->where('client_id', $client->id)->findOrFail((int) $access);
        $data = $request->validate([
            'review_outcome' => ['required', Rule::in(['justified', 'not_justified'])],
            'review_notes' => ['required_if:review_outcome,not_justified', 'nullable', 'string', 'min:10', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'corrects_review_id' => ['nullable', 'integer'],
            'incident_report_id' => ['nullable', 'integer', Rule::exists('client_incidents', 'id')->where('client_id', $client->id)],
            'medication_error_id' => ['nullable', 'integer', Rule::exists('medication_errors', 'id')->where('client_id', $client->id)->whereNull('deleted_at')],
        ]);
        $this->grants->review($user, $grant, $data);

        return back()->with('success', 'Independent review saved. Earlier reviews stay visible.');
    }

    public function updatePolicy(Request $request)
    {
        abort_unless($request->user()?->canDo('medications.settings.manage') || $request->user()?->canDo('medications.emergency_policy.manage'), 403);
        abort(409, 'Review and save emergency access policy changes in Medication settings.');
    }

    public function dismissFlag(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['repeat'])],
            'key' => ['required', 'regex:/^[1-9][0-9]*:[1-9][0-9]*$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        [$houseId, $staffId] = array_map('intval', explode(':', $data['key']));
        abort_if($staffId === (int) $user->id, 403);
        $this->grants->acknowledgeRepeat($user, $houseId, $staffId, $data['reason']);

        return back()->with('success', 'Repeat use acknowledged. New use brings it back.');
    }

    /**
     * Oversight (review, ending someone else's access) is Site-scoped with
     * the eMAR Site bypass only, never audit.view or breakglass.end
     * (EA-030 / EA-065). An out-of-scope house answers "not found" (EA-203).
     */
    private function assertClientSiteAccess(User $user, Client $client, bool $oversight = true): void
    {
        abort_unless(in_array(
            (int) $client->site_id,
            $this->siteAccess->accessibleSiteIds($user, $oversight ? MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS : []),
            true,
        ), 404);
    }
}

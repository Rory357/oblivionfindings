<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\SiteRoom;
use App\Services\Assets\AssetProfileService;
use App\Services\Fleet\VehicleFinanceService;
use Illuminate\Http\Request;

class AssetProfileController extends Controller
{
    public function financeReview(Request $request, Asset $asset, VehicleFinanceService $finance)
    {
        $this->authorize('view', $asset);
        $review = $finance->createRequest($request->user(), $asset->id, $request->only(['request_type', 'source', 'amount', 'note', 'existing_document_id']), (string) $request->input('request_key', ''), true);

        return response()->json(['message' => 'Finance review '.$review->reference_number.' submitted. No expenditure has been approved.', 'id' => $review->id]);
    }

    public function options(Request $request, Asset $asset, SecurityDevicesAccessService $access)
    {
        $this->authorize('view', $asset);
        $kind = $request->input('kind', 'staff');
        $this->authorize(match ($kind) {
            'components' => 'update', 'owners' => 'manageOwnership', default => 'manageAssignments'
        }, $asset);
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 120);
        $site = $request->integer('site_id');
        if ($site) {
            abort_unless(in_array($site, $access->accessibleSiteIds($request->user()), true), 404);
        }
        if ($kind === 'assignees') {
            $sites = collect([$asset->site_id, $asset->home_site_id, $asset->client?->site_id])->filter()->map(fn ($id) => (int) $id)->unique()->values();
            abort_unless($sites->count() === 1 && in_array($sites[0], $access->accessibleSiteIds($request->user()), true), 404);
            $site = $sites[0];
            $options = $access->assignableStaffAtSite($request->user(), $site, $search)->map(fn ($person) => ['id' => 'staff:'.$person->id, 'name' => $person->name.' · Staff'])
                ->concat($access->assignableClientsAtSite($request->user(), $site, $search)->map(fn ($person) => ['id' => 'client:'.$person->id, 'name' => trim($person->first_name.' '.$person->last_name).' · Client']))
                ->concat($access->assignableWhanauAtSite($request->user(), $site, $search)->map(fn ($person) => ['id' => 'whanau:'.$person->id, 'name' => $person->name.' · Whānau']));

            return response()->json(['options' => $options->values()]);
        }
        $options = match ($kind) {
            'owners' => $access->assignableClients($request->user(), $search)->filter(fn ($client) => (int) $client->site_id === (int) $asset->site_id)->map(fn ($client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)])->values(),
            'staff' => $access->assignableStaff($request->user())->when($site, fn ($query) => $query->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where(fn ($placement) => $placement->where('primary_site_id', $site)->orWhereJsonContains('secondary_site_ids', $site))))->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->get(['id', 'name']),
            'components' => $access->accessibleAssets($request->user())->whereKeyNot($asset->id)->where('site_id', $asset->site_id)->where('client_id', $asset->client_id)->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('asset_tag', 'like', '%'.$search.'%'))->orderBy('name')->limit(50)->get()->map(fn ($item) => ['id' => $item->id, 'name' => $item->asset_tag.' · '.$item->name])->values(),
            'rooms' => SiteRoom::whereIn('site_id', $access->accessibleSiteIds($request->user()))->where('site_id', $request->integer('site_id'))->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->get(['id', 'name']),
            default => abort(422),
        };

        return response()->json(['options' => $options]);
    }

    public function command(Request $request, Asset $asset, AssetProfileService $profiles)
    {
        $this->authorize('view', $asset);
        $data = $request->validate([
            'action' => ['required', 'in:dispatch,receive,return,cancel_movement,exception,kit_add,kit_remove,verify_location,retire,assign,release,confirm_assignment_receipt,check,set_photo,remove_photo,generate_qr,ownership'],
            'assignee_type' => ['nullable', 'in:staff,client,whanau'],
            'assignee_id' => ['required_with:assignee_type', 'nullable', 'integer', 'min:1'],
            'verified_received' => ['required_if:action,confirm_assignment_receipt', 'accepted_if:action,confirm_assignment_receipt'],
            'owner_type' => ['required_if:action,ownership', 'nullable', 'in:site,client'],
            'owner_id' => ['required_if:action,ownership', 'nullable', 'integer', 'min:1'],
            'document_id' => ['required_if:action,set_photo', 'nullable', 'integer', 'min:1'],
            'condition' => ['nullable', 'in:good,worn,damaged,unknown'],
            'result' => ['required_if:action,check', 'nullable', 'in:pass,fail,needs_followup'],
            'next_due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'assignment_id' => ['required_if:action,release,confirm_assignment_receipt', 'nullable', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'min:8', 'max:80'],
            'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:'.($request->input('action') === 'confirm_assignment_receipt' ? '500' : '2000'), 'not_regex:/^\s*$/'],
            'kind' => ['nullable', 'in:transfer,loan'], 'movement_id' => ['nullable', 'integer', 'min:1'],
            'destination_site_id' => ['nullable', 'integer', 'min:1'], 'destination_room_id' => ['nullable', 'integer', 'min:1'],
            'recipient_user_id' => ['nullable', 'integer', 'min:1'], 'return_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'outcome' => ['nullable', 'in:acknowledged,incomplete,disputed'], 'received_kit' => ['nullable', 'array', 'max:200'], 'received_kit.*' => ['integer', 'min:1'],
            'kit_item_id' => ['nullable', 'integer', 'min:1'], 'component_asset_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required_if:action,kit_add', 'nullable', 'string', 'max:160'],
            'location' => ['required_if:action,verify_location', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json($profiles->command($request->user(), $asset, $data));
    }
}

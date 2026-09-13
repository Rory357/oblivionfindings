<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItCatalogAccessService;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\SearchItCatalogFieldOptionsRequest;
use App\Models\ItCatalogItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Reuses the scoped catalogue directory and canonical intake eligibility. */
final class ItCatalogRequestedForOptionController extends Controller
{
    public function __invoke(SearchItCatalogFieldOptionsRequest $request, int $catalogItem,
        ItCatalogAccessService $access, ItCatalogFieldOptionService $options,
        ItTicketIntakeService $intake, ItWorkAccessService $workAccess): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->canDo('it.manage'), 403);
        $input = $request->validated();
        $contract = ItCatalogItem::query()->published()->with('publishedVersion')
            ->findOrFail($catalogItem)->publishedContract();
        abort_unless($access->canDiscover($actor, $contract), 404);
        abort_unless((int) $input['schema_version'] === (int) $contract->form_schema_version, 409,
            'The published form has changed. Reopen the request to review it.');
        $siteId = isset($input['site_id']) ? (int) $input['site_id'] : null;
        if ($contract->outcome_type !== 'provisioning') {
            abort_unless($siteId !== null && $access->allowsSite($contract, $siteId)
                && $workAccess->canAssignScope($actor, $siteId, false), 404);
        }

        $page = $options->search($actor, 'user', trim($input['query'] ?? ''), isset($input['after']) ? (int) $input['after'] : null);
        $selectedId = isset($input['selected_id']) ? (int) $input['selected_id'] : null;
        $selected = $selectedId === null ? null : $options->find($actor, 'user', $selectedId);
        $ids = array_unique([...array_column($page['options'], 'id'), ...($selected ? [$selected['id']] : [])]);
        $users = User::query()->whereKey($ids)->get()->keyBy('id');
        $profiles = $contract->outcome_type === 'provisioning'
            ? HrEmployeeProfile::query()->whereIn('user_id', $ids)->where('is_active', true)->get()->keyBy('user_id') : collect();
        $eligible = function (array $option) use ($actor, $contract, $siteId, $intake, $access, $users, $profiles): bool {
            $user = $users->get($option['id']);
            if (! $user || ! ($person = $intake->requesterOption($actor, $user))) {
                return false;
            }
            if ($contract->outcome_type === 'provisioning') {
                $profile = $profiles->get($user->id);

                return $profile && $access->allowsSite($contract, $profile->primary_site_id);
            }

            return in_array($siteId, $person['site_ids'], true);
        };

        return response()->json([
            'viewer_user_id' => (int) $actor->id, 'query_uuid' => $input['query_uuid'],
            'catalog_item_id' => (int) $contract->id, 'schema_version' => (int) $contract->form_schema_version,
            'purpose' => 'requested-for', 'field_key' => 'requested_for_user_id', 'site_id' => $siteId,
            'options' => array_values(array_filter($page['options'], $eligible)),
            'next_cursor' => $page['next_cursor'], 'selected_id' => $selectedId,
            'selected' => $selected && $eligible($selected) ? $selected : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItCatalogAccessService;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\SearchItCatalogFieldOptionsRequest;
use App\Models\ItCatalogItem;
use Illuminate\Http\JsonResponse;

/** Private read endpoint; the published field defines which directory may be searched. */
class ItCatalogFieldOptionController extends Controller
{
    public function __invoke(
        SearchItCatalogFieldOptionsRequest $request,
        int $catalogItem,
        string $field,
        ItCatalogAccessService $access,
        ItCatalogFieldOptionService $options,
    ): JsonResponse {
        $actor = $request->user();
        $input = $request->validated();
        $contract = ItCatalogItem::query()->published()->with('publishedVersion')
            ->findOrFail($catalogItem)->publishedContract();
        abort_unless($access->canDiscover($actor, $contract), 404);
        $definition = collect($contract->discoveryPayload($actor->canDo('it.manage'))['form_schema']['fields'] ?? [])
            ->firstWhere('key', $field);
        abort_unless(is_array($definition)
            && in_array($definition['type'] ?? null, ItCatalogFieldOptionService::TYPES, true), 404);
        abort_unless((int) $input['schema_version'] === (int) $contract->form_schema_version, 409, 'The published form has changed. Reopen the request to review it.');

        $page = $options->search($actor, $definition['type'], trim($input['query'] ?? ''), isset($input['after']) ? (int) $input['after'] : null);
        $selectedId = isset($input['selected_id']) ? (int) $input['selected_id'] : null;

        return response()->json([
            'viewer_user_id' => (int) $actor->id,
            'query_uuid' => $input['query_uuid'],
            'catalog_item_id' => (int) $contract->id,
            'schema_version' => (int) $contract->form_schema_version,
            'field_key' => $field,
            ...$page,
            'selected_id' => $selectedId,
            'selected' => $selectedId === null ? null : $options->find($actor, $definition['type'], $selectedId),
        ])->header('Cache-Control', 'private, no-store');
    }
}

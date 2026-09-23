<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetVehicleFinanceLink;
use App\Models\User;
use App\Services\Fleet\VehicleFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vehicle profile's Finance view: link existing Finance records, find
 * linkable ones, route review requests to Finance and let Finance decide
 * them. Route models are ID carriers only; the service re-resolves the
 * vehicle and every record inside the actor's scope.
 */
class VehicleFinanceController extends Controller
{
    public function __construct(private readonly VehicleFinanceService $finance) {}

    public function linkable(Request $request, Asset $asset): JsonResponse
    {
        $before = $request->input('before');

        return response()->json($this->finance->linkable(
            $this->actor($request),
            (int) $asset->getKey(),
            (string) $request->input('type', ''),
            (string) $request->input('q', ''),
            is_numeric($before) && (int) $before > 0 ? (int) $before : null,
        ));
    }

    public function link(Request $request, Asset $asset): JsonResponse
    {
        $links = $this->finance->link($this->actor($request), (int) $asset->getKey(),
            $request->only(['reason', 'fixed_asset_id', 'record_type', 'record_id']), $this->key($request));

        return response()->json([
            'links' => array_map(fn (FleetVehicleFinanceLink $link): array => $this->linkDto($link), $links),
        ]);
    }

    public function unlink(Request $request, Asset $asset, int $link): JsonResponse
    {
        $removed = $this->finance->unlink($this->actor($request), (int) $asset->getKey(), $link,
            (string) $request->input('reason', ''), $this->key($request));

        return response()->json(['link' => $this->linkDto($removed)]);
    }

    public function storeRequest(Request $request, Asset $asset): JsonResponse
    {
        $created = $this->finance->createRequest($this->actor($request), (int) $asset->getKey(),
            $request->only(['request_type', 'source', 'amount', 'note', 'existing_document_id']), $this->key($request));

        return response()->json(['request' => $this->requestDto($created)]);
    }

    public function decide(Request $request, Asset $asset, int $reviewRequest): JsonResponse
    {
        $decided = $this->finance->decide($this->actor($request), (int) $asset->getKey(), $reviewRequest,
            (string) $request->input('decision', ''), (string) $request->input('note', ''),
            (int) $request->input('expected_version'), $this->key($request));

        return response()->json(['request' => $this->requestDto($decided)]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: '');
    }

    /** @return array<string,mixed> */
    private function linkDto(FleetVehicleFinanceLink $link): array
    {
        return [
            'id' => $link->id,
            'record_type' => $link->record_type,
            'record_id' => $link->record_id,
            'active' => $link->isActive(),
        ];
    }

    /** @return array<string,mixed> */
    private function requestDto(FleetFinanceReviewRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference_number,
            'type_label' => $request->typeLabel(),
            'status' => $request->status,
            'lock_version' => $request->lock_version,
        ];
    }
}

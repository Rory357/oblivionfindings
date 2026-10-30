<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\FleetCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Explicit "Add new" for the searchable Fleet choice pickers. */
class FleetCatalogueController extends Controller
{
    public function __construct(private readonly FleetCatalogueService $catalogue) {}

    public function store(Request $request, string $kind): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $entry = $this->catalogue->add($actor, $kind, (string) $request->input('label', ''));

        return response()->json(['entry' => ['id' => $entry->id, 'kind' => $entry->kind, 'label' => $entry->label]]);
    }
}

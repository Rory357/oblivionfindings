<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItAssistContractService;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only W25 contract endpoint. There is deliberately no execution or
 * apply endpoint: applying a future suggestion uses the existing
 * authorized, versioned commands, never an assistant-specific bypass.
 */
class ItAssistController extends Controller
{
    public function __construct(
        private readonly ItAssistContractService $assist,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    public function show(Request $request, ItTicket $ticket): JsonResponse
    {
        $actor = $request->user();
        abort_unless((bool) $actor?->canDo('it.view'), 403);
        abort_unless($this->workAccess->applyViewScope(ItTicket::query(), $actor)->whereKey($ticket->id)->exists(), 404);

        return response()->json($this->assist->forTicket($ticket, $actor))
            ->header('Cache-Control', 'no-store, private');
    }

    public function article(Request $request, ItKbArticle $article): JsonResponse
    {
        $actor = $request->user();
        abort_unless(app(ItKbAccessService::class)->canAuthor($actor, $article), 404);

        return response()->json($this->assist->forArticle($article, $actor))
            ->header('Cache-Control', 'no-store, private');
    }
}

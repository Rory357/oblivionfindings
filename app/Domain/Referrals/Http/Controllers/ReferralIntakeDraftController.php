<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralIntakeDrafts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralIntakeDraftController extends Controller
{
    public function __construct(private readonly ReferralIntakeDrafts $drafts) {}

    public function index(Request $request): JsonResponse
    {
        return $this->json(['drafts' => $this->drafts->list($request->user())]);
    }

    public function show(Request $request, string $draft): JsonResponse
    {
        $record = $this->drafts->find($request->user(), $draft);

        return $this->json(['draft' => [...$this->drafts->summary($record) ?? abort(404), 'payload' => $record->payload]]);
    }

    public function store(Request $request): JsonResponse
    {
        $record = $this->drafts->save($request->user(), $request->all());

        return $this->json(['saved' => true, 'draft' => $this->drafts->summary($record)]);
    }

    public function update(Request $request, string $draft): JsonResponse
    {
        $record = $this->drafts->save($request->user(), $request->all(), $draft);

        return $this->json(['saved' => true, 'draft' => $this->drafts->summary($record)]);
    }

    public function destroy(Request $request, string $draft): JsonResponse
    {
        $this->drafts->discard($request->user(), $draft);

        return $this->json(['deleted' => true]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}

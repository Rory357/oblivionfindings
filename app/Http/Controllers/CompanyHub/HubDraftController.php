<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HubDraftController extends Controller
{
    public function show(Request $request, HubPage $page, HubDraft $drafts)
    {
        $includeSnapshot = $request->boolean('include_snapshot');
        $data = $request->validate([
            'include_snapshot' => 'sometimes|boolean',
            'actor_id' => [Rule::requiredIf($includeSnapshot), 'integer', 'min:1', 'max:9007199254740991'],
            'version' => [Rule::requiredIf($includeSnapshot), 'integer', 'min:1', 'max:9007199254740991'],
        ]);

        return response()->json($drafts->read($request->user(), $page, $includeSnapshot ? $data : null))->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request, bool $restore = false): array
    {
        return $request->validate([
            'actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            ...($restore ? ['discard_id' => 'required|integer|min:1'] : []),
            'user_id' => 'prohibited', 'content' => 'prohibited',
        ]);
    }

    public function destroy(Request $request, HubPage $page, HubDraft $drafts)
    {
        return response()->json($drafts->discard($request->user(), $page, $this->input($request)))->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, HubPage $page, HubDraft $drafts)
    {
        return response()->json($drafts->restore($request->user(), $page, $this->input($request, true)))->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Services\FeedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bounded discovery always rechecks current work identity and record visibility. */
class HubDiscoveryController extends Controller
{
    public function recognition(Request $request, HubRecognition $recognition)
    {
        $filters = $request->validate([...$this->common(),
            'category' => ['nullable', Rule::in(array_keys(FeedService::KUDOS_CATEGORIES))],
            'recipient_type' => ['nullable', Rule::in(['person', 'team'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'most_appreciated'])],
        ]);

        return response()->json($recognition->browse($request->user(), $filters))->header('Cache-Control', 'private, no-store');
    }

    public function ideas(Request $request, HubIdeas $ideas)
    {
        $filters = $request->validate([...$this->common(),
            'category' => ['nullable', Rule::in(HubCommunity::CATEGORIES)],
            'status' => ['nullable', Rule::in(HubCommunity::STATUSES)],
            'sort' => ['nullable', Rule::in(['updated', 'newest', 'oldest', 'most_supported'])],
        ]);

        return response()->json($ideas->browse($request->user(), $filters))->header('Cache-Control', 'private, no-store');
    }

    private function common(): array
    {
        return ['search' => 'nullable|string|max:150', 'team_id' => 'nullable|integer|min:1|max:2147483647',
            'page' => 'nullable|integer|min:1|max:1000000', 'per_page' => 'nullable|integer|min:1|max:50'];
    }
}

<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItSavedTicketFilterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\StoreItSavedTicketFilterRequest;
use App\Models\ItSavedTicketFilter;
use Illuminate\Http\Request;

class ItSavedTicketFilterController extends Controller
{
    public function store(
        StoreItSavedTicketFilterRequest $request,
        ItSavedTicketFilterService $filters,
    ) {
        $user = $request->user();

        if (ItSavedTicketFilter::query()->where('user_id', $user->id)->count() >= 25) {
            return redirect()->back()->withErrors([
                'filters' => 'You can keep up to 25 personal ticket filters. Delete one before saving another.',
            ]);
        }

        $safeFilters = $filters->sanitize($user, (array) $request->validated('filters'));
        if ($safeFilters === []) {
            return redirect()->back()->withErrors([
                'filters' => 'Choose at least one ticket filter before saving this view.',
            ]);
        }

        ItSavedTicketFilter::query()->create([
            'user_id' => $user->id,
            'name' => trim((string) $request->validated('name')),
            'filters' => $safeFilters,
        ]);

        return redirect()->back()->with('success', 'Personal ticket filter saved.');
    }

    public function destroy(Request $request, ItSavedTicketFilter $savedFilter)
    {
        abort_unless($request->user()?->canDo('it.view'), 403);
        abort_unless((int) $savedFilter->user_id === (int) $request->user()->id, 404);

        $savedFilter->delete();

        // Do not redirect back to ?saved_filter={id}: deleting the active
        // shortcut would make the follow-up GET correctly 404.
        return redirect()->route('it.index', ['tab' => 'tickets'])
            ->with('success', 'Personal ticket filter deleted.');
    }
}

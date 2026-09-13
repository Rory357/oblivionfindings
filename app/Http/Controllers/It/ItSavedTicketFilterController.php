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
        $filters->store($request->user(), (string) $request->validated('name'), (array) $request->validated('filters'));

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

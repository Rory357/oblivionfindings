<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Exceptions\ItTicketVersionConflict;
use App\Domain\It\Services\ItTicketMacroService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\SaveItTicketMacroRequest;
use App\Models\ItTicket;
use App\Models\ItTicketMacro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItTicketMacroController extends Controller
{
    public function __construct(
        private readonly ItTicketMacroService $macros,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /** Active macros with a per-ticket preview naming every resulting change. */
    public function index(Request $request, ItTicket $ticket): JsonResponse
    {
        $actor = $request->user();
        abort_unless((bool) $actor?->canDo('it.view'), 403);
        abort_unless($this->workAccess->applyViewScope(ItTicket::query(), $actor)->whereKey($ticket->id)->exists(), 404);
        abort_unless($this->workAccess->canWork($actor, $ticket), 403);

        $macros = ItTicketMacro::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (ItTicketMacro $macro) => [
                'id' => $macro->id,
                'name' => $macro->name,
                'description' => $macro->description,
                ...$this->macros->preview($macro, $ticket, $actor),
            ]);

        return response()->json(['macros' => $macros, 'ticket_version' => (int) $ticket->lock_version])
            ->header('Cache-Control', 'no-store, private');
    }

    public function apply(Request $request, ItTicket $ticket, ItTicketMacro $macro)
    {
        $actor = $request->user();
        abort_unless((bool) $actor?->canDo('it.view'), 403);
        abort_unless($this->workAccess->applyViewScope(ItTicket::query(), $actor)->whereKey($ticket->id)->exists(), 404);
        abort_unless($this->workAccess->canWork($actor, $ticket), 403);
        abort_unless($macro->is_active, 404);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'request_uuid' => ['required', 'uuid'],
        ]);

        try {
            $this->macros->apply($macro, $ticket, $actor, (int) $data['expected_version'], $data['request_uuid']);
        } catch (ItTicketVersionConflict|\DomainException $blocked) {
            return redirect()->back()->withErrors(['macro' => $blocked->getMessage()]);
        }

        return redirect()->back()->with('success', 'Macro applied.');
    }

    public function store(SaveItTicketMacroRequest $request)
    {
        $this->macros->create($request->user(), $request->validated());

        return redirect()->back()->with('success', 'Macro created.');
    }

    public function update(SaveItTicketMacroRequest $request, ItTicketMacro $macro)
    {
        $this->macros->update($macro, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Macro updated.');
    }

    public function archive(Request $request, ItTicketMacro $macro)
    {
        abort_unless((bool) $request->user()?->canDo('it.manage'), 403);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']]);
        $this->macros->setActive($macro, $request->user(), (bool) $data['active'], (int) $data['lock_version']);

        return redirect()->back()->with('success', $data['active'] ? 'Macro restored.' : 'Macro archived.');
    }
}

<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItRecurrenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\SaveItRecurrencePlanRequest;
use App\Models\ItRecurrencePlan;
use Illuminate\Http\Request;

class ItRecurrencePlanController extends Controller
{
    public function __construct(private readonly ItRecurrenceService $recurrence) {}

    public function store(SaveItRecurrencePlanRequest $request)
    {
        $this->recurrence->create($request->user(), $request->validated());

        return redirect()->back()->with('success', 'Recurrence plan created.');
    }

    public function update(SaveItRecurrencePlanRequest $request, ItRecurrencePlan $plan)
    {
        $this->recurrence->update($plan, $request->user(), $request->validated());

        return redirect()->back()->with('success', 'Recurrence plan updated.');
    }

    public function status(Request $request, ItRecurrencePlan $plan)
    {
        abort_unless((bool) $request->user()?->canDo('it.manage'), 403);
        $data = $request->validate([
            'status' => ['required', 'in:active,paused,retired'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->recurrence->setStatus($plan, $request->user(), $data['status'], (int) $data['lock_version']);

        return redirect()->back()->with('success', match ($data['status']) {
            'active' => 'Recurrence plan resumed.',
            'paused' => 'Recurrence plan paused.',
            default => 'Recurrence plan retired.',
        });
    }
}

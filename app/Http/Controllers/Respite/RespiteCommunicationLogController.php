<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteCommunicationLog;
use App\Models\RespiteStay;
use App\Models\RespiteAuditLog;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteCommunicationLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = RespiteCommunicationLog::query()
            ->with(['stay.client'])
            ->when($request->stay_id, fn ($q, $stayId) => $q->where('stay_id', $stayId))
            ->when($request->channel, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($request->date_from, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($request->date_to, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))
            ->orderByDesc('occurred_at')
            ->paginate(20);

        return Inertia::render('respite/communication-logs/index', [
            'logs' => $logs,
            'filters' => $request->only(['stay_id', 'channel', 'date_from', 'date_to']),
            'channels' => $this->getChannels(),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->whereIn('status', ['admitted', 'active', 'extended'])
            ->orderByDesc('created_at')
            ->get();

        if ($stays->isEmpty()) {
            $stays = RespiteStay::query()
                ->with('client')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        return Inertia::render('respite/communication-logs/create', [
            'stays' => $stays,
            'stayId' => $request->stay_id,
            'channels' => $this->getChannels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stay_id' => 'required|exists:respite_stays,id',
            'channel' => 'required|in:phone,email,in_person,video,sms,portal,other',
            'participants' => 'required|array|min:1',
            'participants.*.name' => 'required|string|max:255',
            'participants.*.role' => 'required|string|max:100',
            'summary' => 'required|string|max:10000',
            'occurred_at' => 'required|date',
            'evidence' => 'nullable|array',
        ]);

        $validated['created_by'] = auth()->id();

        $log = RespiteCommunicationLog::create($validated);

        RespiteAuditLog::log(
            $log,
            RespiteAuditLog::ACTION_CREATED,
            auth()->id(),
            null,
            array_diff_key($validated, ['evidence' => null]),
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        event(new RespiteEvent('respite.communication.logged', [
            'id' => $log->id,
            'stay_id' => $log->stay_id,
            'channel' => $log->channel,
        ]));

        return redirect()
            ->route('respite.communication-logs.show', $log)
            ->with('success', 'Communication logged.');
    }

    public function show(RespiteCommunicationLog $communicationLog): Response
    {
        $communicationLog->load(['stay.client']);

        RespiteAuditLog::log(
            $communicationLog,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        return Inertia::render('respite/communication-logs/show', [
            'log' => $communicationLog,
        ]);
    }

    public function update(Request $request, RespiteCommunicationLog $communicationLog): RedirectResponse
    {
        $oldValues = $communicationLog->only(['channel', 'participants', 'summary', 'occurred_at']);

        $validated = $request->validate([
            'channel' => 'sometimes|in:phone,email,in_person,video,sms,portal,other',
            'participants' => 'sometimes|array|min:1',
            'participants.*.name' => 'required|string|max:255',
            'participants.*.role' => 'required|string|max:100',
            'summary' => 'sometimes|string|max:10000',
            'occurred_at' => 'sometimes|date',
            'evidence' => 'nullable|array',
        ]);

        $validated['updated_by'] = auth()->id();
        $communicationLog->update($validated);

        RespiteAuditLog::log(
            $communicationLog,
            RespiteAuditLog::ACTION_UPDATED,
            auth()->id(),
            $oldValues,
            array_diff_key($validated, ['evidence' => null]),
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        event(new RespiteEvent('respite.communication.updated', [
            'id' => $communicationLog->id,
            'stay_id' => $communicationLog->stay_id,
        ]));

        return back()->with('success', 'Communication log updated.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $logs = RespiteCommunicationLog::query()
            ->where('stay_id', $stay->id)
            ->orderByDesc('occurred_at')
            ->get();

        return Inertia::render('respite/communication-logs/for-stay', [
            'stay' => $stay->load('client'),
            'logs' => $logs,
            'channels' => $this->getChannels(),
        ]);
    }

    public function addEvidence(Request $request, RespiteCommunicationLog $communicationLog): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:50',
            'file_path' => 'nullable|string|max:500',
            'description' => 'required|string|max:500',
        ]);

        $evidence = $communicationLog->evidence ?? [];
        $evidence[] = [
            'type' => $validated['type'],
            'file_path' => $validated['file_path'] ?? null,
            'description' => $validated['description'],
            'added_at' => now()->toIso8601String(),
            'added_by' => auth()->id(),
        ];

        $communicationLog->update([
            'evidence' => $evidence,
            'updated_by' => auth()->id(),
        ]);

        return back()->with('success', 'Evidence added.');
    }

    protected function getChannels(): array
    {
        return [
            'phone' => 'Phone Call',
            'email' => 'Email',
            'in_person' => 'In Person',
            'video' => 'Video Call',
            'sms' => 'SMS/Text',
            'portal' => 'Portal Message',
            'other' => 'Other',
        ];
    }
}

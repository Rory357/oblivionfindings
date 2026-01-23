<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSummaryJob;
use App\Models\Client;
use App\Models\Summary;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    public function my(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        return $this->staff($request, $user);
    }

    public function staff(Request $request, User $user)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        if ($viewer->id !== $user->id) {
            abort_unless($viewer->canDo('summaries.viewAny') || $viewer->canDo('timeline.viewAny') || $viewer->canDo('staff.viewAny'), 403);
        }

        $range = $this->parseRange($request);

        $summary = Summary::query()
            ->where('scope_type', 'staff')
            ->where('scope_id', $user->id)
            ->where('period_start', $range['from'])
            ->where('period_end', $range['to'])
            ->first();

        return inertia('summaries/index', [
            'scope' => ['type' => 'staff', 'id' => $user->id, 'name' => $user->name],
            'range' => ['from' => $range['from']->toISOString(), 'to' => $range['to']->toISOString()],
            'summary' => $summary ? $this->dto($summary) : null,
        ]);
    }

    public function client(Request $request, Client $client)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $this->authorize('view', $client);

        $range = $this->parseRange($request);

        $summary = Summary::query()
            ->where('scope_type', 'client')
            ->where('scope_id', $client->id)
            ->where('period_start', $range['from'])
            ->where('period_end', $range['to'])
            ->first();

        return inertia('summaries/index', [
            'scope' => ['type' => 'client', 'id' => $client->id, 'name' => trim($client->first_name . ' ' . $client->last_name)],
            'range' => ['from' => $range['from']->toISOString(), 'to' => $range['to']->toISOString()],
            'summary' => $summary ? $this->dto($summary) : null,
        ]);
    }

    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'scope_type' => ['required', 'in:staff,client,site'],
            'scope_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        // Staff permissions
        $allowed = $user->canDo('summaries.generate');

        // Portal users (client/next_of_kin): allow generating summaries only for their linked client
        if (!$allowed && $data['scope_type'] === 'client' && $user->hasRole('client', 'next_of_kin')) {
            $client = Client::find((int) $data['scope_id']);
            if ($client && $user->canAccessClientPortal($client)) {
                $allowed = true;
            }
        }

        abort_unless($allowed, 403);

        GenerateSummaryJob::dispatch(
            $data['scope_type'],
            (int) $data['scope_id'],
            $data['from'],
            $data['to'],
            $user->id,
        );

        return back()->with('status', 'Summary generation queued.');
    }

    private function parseRange(Request $request): array
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : now()->startOfDay();
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : (clone $from)->addDays(7)->endOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($to->diffInDays($from) > 60) {
            $to = (clone $from)->addDays(60);
        }
        return compact('from', 'to');
    }

    private function dto(Summary $s): array
    {
        return [
            'id' => $s->id,
            'scope_type' => $s->scope_type,
            'scope_id' => $s->scope_id,
            'period_start' => $s->period_start?->toISOString(),
            'period_end' => $s->period_end?->toISOString(),
            'model' => $s->model,
            'summary_text' => $s->summary_text,
            'generated_at' => $s->generated_at?->toISOString(),
        ];
    }
}

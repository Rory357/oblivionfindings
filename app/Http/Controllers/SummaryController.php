<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSummaryJob;
use App\Models\Client;
use App\Models\Site;
use App\Models\Summary;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

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
        abort_if($viewer->hasRole('client', 'next_of_kin'), 403);

        if ($viewer->id !== $user->id) {
            abort_unless($viewer->canDo('summaries.viewAny') || $viewer->canDo('timeline.viewAny') || $viewer->canDo('staff.viewAny'), 403);
            abort_unless($this->canAccessCurrentStaff($viewer, $user), 403);
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
        abort_if($viewer->hasRole('client', 'next_of_kin'), 403);
        $this->authorize('view', $client);

        $range = $this->parseRange($request);

        $summary = Summary::query()
            ->where('scope_type', 'client')
            ->where('scope_id', $client->id)
            ->where('period_start', $range['from'])
            ->where('period_end', $range['to'])
            ->first();

        return inertia('summaries/index', [
            'scope' => ['type' => 'client', 'id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)],
            'range' => ['from' => $range['from']->toISOString(), 'to' => $range['to']->toISOString()],
            'summary' => $summary ? $this->dto($summary) : null,
        ]);
    }

    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_if($user->hasRole('client', 'next_of_kin'), 403);

        $data = $request->validate([
            'scope_type' => ['required', 'in:staff,client,site'],
            'scope_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        abort_unless($user->canDo('summaries.generate'), 403);
        $this->authorizeGenerationScope(
            $user,
            $data['scope_type'],
            (int) $data['scope_id'],
        );

        GenerateSummaryJob::dispatch(
            $data['scope_type'],
            (int) $data['scope_id'],
            $data['from'],
            $data['to'],
            $user->id,
        );

        return back()->with('status', 'Summary generation queued.');
    }

    private function authorizeGenerationScope(User $user, string $scopeType, int $scopeId): void
    {
        if ($scopeType === 'client') {
            $this->authorize('view', Client::query()->findOrFail($scopeId));

            return;
        }

        if ($scopeType === 'staff') {
            $target = User::query()->findOrFail($scopeId);
            abort_unless($this->canAccessCurrentStaff($user, $target), 403);

            return;
        }

        $site = Site::query()->findOrFail($scopeId);
        $this->siteAccess->assertCanAccessSiteId($user, (int) $site->id);
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

    private function canAccessCurrentStaff(User $viewer, User $target): bool
    {
        $query = User::query()->whereKey($target->id);
        $this->siteAccess->applyStaffScope(
            $query,
            $viewer,
            UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        );

        return $query->exists();
    }
}

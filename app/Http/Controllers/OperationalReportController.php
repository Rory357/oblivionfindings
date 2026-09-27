<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\LoneWorkerSession;
use App\Models\OperationalReport;
use App\Models\OperationalReportRun;
use App\Models\OperationalReportSubscription;
use App\Models\OperationalReportVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\LoneWorkerSessionScope;
use App\Services\Reporting\ReportAccess;
use App\Services\Reporting\ReportDefinition;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportRuns;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationalReportController extends Controller
{
    public function __construct(private ReportAccess $access, private ReportDefinition $definitions, private ReportRuns $runs) {}

    public function index(Request $request)
    {
        $domain = $request->route('domain') ?? 'fleet';
        abort_unless(in_array($domain, ['fleet', 'client', 'staff', 'self']), 404);
        $sources = $this->access->sources($request->user(), $domain);
        abort_unless($sources !== [], 403);
        $saved = OperationalReport::query()->where('user_id', $request->user()->id)->whereIn('source', array_keys($sources))->orderByDesc('updated_at')->get();
        $shared = OperationalReport::whereJsonContains('shared_with', (int) $request->user()->id)->whereNull('archived_at')->whereIn('source', array_keys($sources))->get()
            ->map(fn ($report) => ['id' => $report->id, 'name' => $report->name, 'source' => $report->source, 'definition' => $this->portableDefinition($report->definition)]);

        return Inertia::render('reporting/workspace', [
            'domain' => $domain, 'sources' => $sources, 'canExport' => $domain === 'fleet' || $request->user()->canDo('assets.telemetry.export'),
            'templates' => array_values(array_filter(config('operational-reports.templates'), fn ($t) => isset($sources[$t['source']]))),
            'saved' => $saved, 'shared' => $shared, 'viewerId' => $request->user()->id, 'initialSubject' => $request->integer('subject') ?: null,
        ])->toResponse($request)->withHeaders(ClientLocationAccessService::headers());
    }

    public function targets(Request $request)
    {
        $data = $request->validate(['source' => ['required', 'string'], 'q' => ['nullable', 'string', 'max:80']]);
        $sources = $this->access->sources($request->user());
        $source = $sources[$data['source']] ?? null;
        abort_unless($source, 403);
        $actor = $this->access->actor($request->user());
        $q = $data['q'] ?? '';
        $targets = [];
        $sites = [];
        if ($source['domain'] === 'client') {
            $candidates = app(SecurityDevicesAccessService::class)->assignableClients($actor, $q)->take(100);
            foreach ($candidates as $client) {
                try {
                    app(ClientLocationAccessService::class)->resolve($actor, $client);
                } catch (AuthorizationException|ModelNotFoundException|HttpException $e) {
                    continue;
                }
                $targets[] = ['id' => $client->id, 'label' => trim($client->first_name.' '.$client->last_name)];
            }
        } elseif ($source['domain'] === 'staff') {
            $query = app(LoneWorkerSessionScope::class)->apply(LoneWorkerSession::query()->with('user:id,name'), $actor);
            $query->when($q !== '', fn ($builder) => $builder->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$q.'%')));
            $targets = $query->orderByDesc('started_at')->limit(50)->get()->map(fn ($s) => ['id' => $s->id, 'label' => $s->user->name.' · '.$s->started_at->setTimezone('Pacific/Auckland')->format('j M Y H:i').' · '.$s->status])->all();
        } elseif ($source['domain'] === 'fleet') {
            $assets = in_array($data['source'], ['maintenance', 'resources', 'obligations', 'custody', 'stocktakes', 'downtime', 'finance_bills'])
                ? app(SecurityDevicesAccessService::class)->reportAssets($actor) : app(SecurityDevicesAccessService::class)->reportVehiclesForFleet($actor);
            $scope = $this->access->context($actor, ['source' => $data['source'], 'site_ids' => [], 'resource_ids' => [], 'subject_id' => null]);
            $sites = Site::whereIn('id', $scope['site_ids'])->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => $site->id, 'label' => $site->name])->all();
            $targets = $assets->whereIn('id', $scope['asset_ids'])->where('name', 'like', '%'.$q.'%')->orderBy('name')->limit(100)->get(['id', 'name'])->map(fn ($a) => ['id' => $a->id, 'label' => $a->name])->all();
        }
        if ($source['domain'] === 'staff') {
            $sites = Site::whereIn('id', (new UserSiteAccessService)->accessibleHealthSafetySiteIds($actor))->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => $site->id, 'label' => $site->name])->all();
        }

        return response()->json(['targets' => $targets, 'sites' => $sites], 200, ClientLocationAccessService::headers());
    }

    public function validateDefinition(Request $request)
    {
        $data = $request->validate(['definition' => ['required', 'array']]);

        return response()->json(['definition' => $this->definitions->validate($data['definition'], $this->access->sources($request->user()))], 200, ClientLocationAccessService::headers());
    }

    public function save(Request $request, ?OperationalReport $report = null)
    {
        $data = $request->validate(['definition' => ['required', 'array'], 'version' => ['nullable', 'integer', 'min:1'], 'folder' => ['nullable', 'string', 'max:80'], 'favourite' => ['sometimes', 'boolean']]);
        $definition = $this->definitions->validate($data['definition'], $this->access->sources($request->user()));
        if ($report?->exists) {
            abort_unless((int) $report->user_id === (int) $request->user()->id, 404);
        }
        $report = DB::transaction(function () use ($request, $report, $data, $definition) {
            if ($report?->exists) {
                $report = OperationalReport::whereKey($report->id)->lockForUpdate()->firstOrFail();
                abort_unless(($data['version'] ?? null) === $report->version, 409, 'This report was changed in another tab. Reload it before saving.');
                $version = $report->version + 1;
            } else {
                $report = new OperationalReport(['user_id' => $request->user()->id]);
                $version = 1;
            }
            $report->fill(['name' => $definition['name'], 'source' => $definition['source'], 'definition' => $definition, 'version' => $version,
                'folder' => $data['folder'] ?? null, 'favourite' => $data['favourite'] ?? false])->save();
            OperationalReportVersion::create(['report_id' => $report->id, 'version' => $version, 'definition' => $definition, 'user_id' => $request->user()->id]);

            return $report;
        });

        return response()->json(['report' => $report], 200, ClientLocationAccessService::headers());
    }

    public function versions(Request $request, OperationalReport $report)
    {
        $this->owned($request, $report);

        return response()->json(['versions' => OperationalReportVersion::where('report_id', $report->id)->orderByDesc('version')->get()], 200, ClientLocationAccessService::headers());
    }

    public function archive(Request $request, OperationalReport $report)
    {
        $this->owned($request, $report);
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $report->update(['archived_at' => $data['archived'] ? now() : null]);
        if ($data['archived']) {
            OperationalReportSubscription::where('report_id', $report->id)->update(['active' => false]);
        }

        return response()->json(['report' => $report], 200, ClientLocationAccessService::headers());
    }

    public function run(Request $request)
    {
        $data = $request->validate(['definition' => ['required', 'array'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'report_id' => ['nullable', 'integer']]);
        $report = isset($data['report_id']) ? OperationalReport::findOrFail($data['report_id']) : null;
        if ($report) {
            $this->owned($request, $report);
        }
        $run = $this->runs->queue($request->user(), $data['definition'], trim($data['reason']), $report);

        return response()->json(['id' => $run->id, 'status' => $run->status], 202, ClientLocationAccessService::headers());
    }

    public function recent(Request $request)
    {
        $runs = OperationalReportRun::where('user_id', $request->user()->id)->latest()->limit(50)->get(['id', 'user_id', 'definition', 'status', 'created_at', 'expires_at']);
        $visible = [];
        foreach ($runs as $run) {
            try {
                $this->access->context($request->user(), $run->definition);
            } catch (AuthorizationException|ModelNotFoundException|HttpException $e) {
                continue;
            }
            $visible[] = $run;
        }

        return response()->json(['runs' => $visible], 200, ClientLocationAccessService::headers());
    }

    public function status(Request $request, OperationalReportRun $run)
    {
        abort_unless((int) $run->user_id === (int) $request->user()->id, 404);
        $result = $run->status === 'ready' ? $this->runs->result($request->user(), $run) : null;
        if ($result) {
            $result['source'] = ReportRuns::publicSource($result['source']);
            $result['result']['rows'] = array_slice($result['result']['rows'], 0, 500);
            $result['result']['groups'] = array_slice($result['result']['groups'], 0, 500);
            $result['preview_limit'] = 500;
        }

        return response()->json(['id' => $run->id, 'status' => $run->status, 'failure_code' => $run->failure_code, 'payload' => $result], 200, ClientLocationAccessService::headers());
    }

    public function cancel(Request $request, OperationalReportRun $run)
    {
        abort_unless((int) $run->user_id === (int) $request->user()->id, 404);
        OperationalReportRun::whereKey($run->id)->whereIn('status', ['queued', 'running'])->update(['status' => 'cancelled', 'payload' => null]);

        return response()->json(['status' => $run->fresh()->status], 200, ClientLocationAccessService::headers());
    }

    public function export(Request $request, OperationalReportRun $run, ReportExporter $exporter)
    {
        $data = $request->validate(['format' => ['required', 'in:csv,json,xlsx,pdf'], 'section' => ['nullable', 'in:summary,rows'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $exporter->download($request->user(), $run, $data['format'], trim($data['reason']), $data['section'] ?? 'rows');
    }

    public function subscription(Request $request, OperationalReport $report)
    {
        $this->owned($request, $report);
        $data = $request->validate(['frequency' => ['required', 'in:daily,weekly,monthly'], 'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        abort_if($report->archived_at, 422, 'Restore this report before scheduling it.');
        $this->access->context($request->user(), $report->definition);
        $subscription = OperationalReportSubscription::updateOrCreate(['report_id' => $report->id, 'user_id' => $request->user()->id],
            $data + ['next_run_at' => now('Pacific/Auckland')->addDay()->startOfDay()->addHours(7)->utc()]);

        return response()->json(['subscription' => $subscription], 200, ClientLocationAccessService::headers());
    }

    public function share(Request $request, OperationalReport $report)
    {
        $this->owned($request, $report);
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'remove' => ['sometimes', 'boolean']]);
        $recipient = User::where('email', $data['email'])->whereNotNull('approved_at')->first();
        abort_unless($recipient && isset($this->access->sources($recipient)[$report->source]), 422, 'This recipient is unavailable for this source.');
        $ids = $report->shared_with ?? [];
        if ($data['remove'] ?? false) {
            $ids = array_values(array_diff($ids, [$recipient->id]));
        } else {
            $ids = array_values(array_unique([...$ids, (int) $recipient->id]));
            abort_if(count($ids) > 25, 422, 'A definition can be shared with up to 25 accounts.');
        }
        $report->update(['shared_with' => $ids]);

        return response()->json(['shared_count' => count($ids)], 200, ClientLocationAccessService::headers());
    }

    private function portableDefinition(array $definition): array
    {
        // Definition sharing never includes a creator's result, person or resource scope.
        $definition['subject_id'] = null;
        $definition['site_ids'] = [];
        $definition['resource_ids'] = [];
        $definition['filters'] = [];
        $definition['filter_groups'] = [];
        foreach ($definition['measures'] as &$measure) {
            unset($measure['where']);
        }

        return $definition;
    }

    private function owned(Request $request, OperationalReport $report): void
    {
        abort_unless((int) $report->user_id === (int) $request->user()->id, 404);
        abort_unless(isset($this->access->sources($request->user())[$report->source]), 403);
    }
}

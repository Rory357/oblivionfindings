<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\MedicationDowntime;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeAccess;
use App\Services\Medication\Downtime\DowntimeService;
use App\Services\Medication\Downtime\PaperEntryService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MedicationDowntimeController extends Controller
{
    public function __construct(private readonly DowntimeAccess $access, private readonly DowntimeService $downtimes, private readonly PaperEntryService $paper) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $ids = $this->access->siteIds($actor);
        $manager = $this->access->manages($actor);
        $query = MedicationDowntime::query()->whereIn('site_id', $ids);
        if (! $manager) {
            $query->whereHas('entries', fn ($q) => $q->where('given_by', $actor->id)->orWhere('witness_id', $actor->id));
        }
        $rows = $query->orderByDesc('started_at')->paginate(25)->through(fn ($row) => [
            'id' => (int) $row->id, 'site' => Site::query()->find($row->site_id)?->name,
            'started_at' => $row->started_at->toIso8601String(), 'ended_at' => $row->ended_at->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
            'description' => $manager && $actor->canDo('medications.controlled.view') ? $row->description : 'Downtime paper medication records',
        ]);

        return Inertia::render('emar/downtime/index', [
            'downtimes' => $rows, 'sites' => Site::query()->whereIn('id', $ids)->get(['id', 'name']),
            'can_manage' => $manager, 'can_make_pack' => $actor->canDo('medications.reports.export'),
            'today' => now('Pacific/Auckland')->toDateString(), 'tomorrow' => now('Pacific/Auckland')->addDay()->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'required|integer|min:1', 'started_at' => 'required|string|max:40', 'ended_at' => 'required|string|max:40',
            'description' => 'required|string|max:4000', 'request_uuid' => 'required|uuid',
            'sheets' => 'nullable|array|max:10', 'sheets.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        abort_unless($this->access->manages($request->user()), 403);
        abort_unless(in_array((int) $data['site_id'], $this->access->siteIds($request->user()), true), 404);
        $sheets = [];
        try {
            foreach ($request->file('sheets', []) as $file) {
                $path = $file->store('medication-downtime', 'private');
                if (! $path) {
                    throw new \RuntimeException('Could not store the private paper sheet.');
                }
                $sheets[] = ['path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
            }
            unset($data['sheets']);
            $downtime = $this->downtimes->declare($request->user(), $data, $sheets);
            foreach ($sheets as $sheet) {
                if (! $downtime->sheets()->where('path', $sheet['path'])->exists()) {
                    Storage::disk('private')->delete($sheet['path']);
                }
            }
        } catch (\Throwable $e) {
            foreach ($sheets as $sheet) {
                Storage::disk('private')->delete($sheet['path']);
            }
            throw $e;
        }

        return to_route('emar.downtime.show', $downtime->id);
    }

    public function show(Request $request, int $downtime)
    {
        $actor = $request->user();
        $row = $this->access->downtime($actor, $downtime);
        $manager = $this->access->manages($actor);
        $clientIds = $this->access->clients($actor, (int) $row->site_id);
        $canControlled = $actor->canDo('medications.controlled.view');
        $entries = $row->entries()->whereIn('client_id', $clientIds)
            ->when(! $manager, fn ($q) => $q->where(fn ($q) => $q->where('given_by', $actor->id)->orWhere('witness_id', $actor->id)))
            ->with(['confirmations', 'posting'])->get()->filter(fn ($entry) => $canControlled || (! ($entry->snapshot['controlled'] ?? false) && ! ClientMedication::query()->whereKey($entry->client_medication_id)->value('controlled_drug')));
        $doses = $manager ? $row->doses()->whereIn('client_id', $clientIds)->get()->filter(fn ($dose) => $canControlled || (! ($dose->snapshot['controlled'] ?? false) && ! ClientMedication::query()->whereKey($dose->client_medication_id)->value('controlled_drug'))) : collect();
        $prn = $manager ? ClientMedication::query()->whereIn('client_id', $clientIds)->where('is_prn', true)->where('active', true)
            ->when(! $canControlled, fn ($q) => $q->where('controlled_drug', false))->with('client')->get()->map(fn ($order) => [
                'id' => (int) $order->id, 'person' => $order->client->full_name, 'medicine' => $order->name, 'dosage' => $order->dosage,
                'observation_keys' => $this->paper->snapshot($order)['observation_keys'],
                'second_person_required' => $this->paper->snapshot($order)['second_person_required'],
            ])->all() : [];
        $staff = $manager ? User::query()->whereNotNull('approved_at')->get()
            ->filter(fn ($user) => in_array((int) $row->site_id, app(UserSiteAccessService::class)->accessibleSiteIds($user), true))
            ->map(fn ($user) => ['id' => (int) $user->id, 'name' => $user->name])->values()->all() : [['id' => (int) $actor->id, 'name' => $actor->name]];
        $enteredTargetIds = $row->entries()->whereNotNull('downtime_dose_id')->pluck('downtime_dose_id');
        $resolved = $row->resolutions()->get()->keyBy('downtime_dose_id');
        $enteredTargetIds = $enteredTargetIds->merge($resolved->keys());

        return Inertia::render('emar/downtime/show', [
            'downtime' => [
                'id' => (int) $row->id, 'site' => Site::query()->find($row->site_id)?->name,
                'started_at' => $row->started_at->toIso8601String(), 'ended_at' => $row->ended_at->toIso8601String(),
                'description' => $manager && $canControlled ? $row->description : 'Downtime paper medication records', 'finished_at' => $row->finished_at?->toIso8601String(),
            ],
            'doses' => $doses->map(fn ($dose) => ['id' => (int) $dose->id, 'client_medication_id' => (int) $dose->client_medication_id,
                'scheduled_for' => $dose->scheduled_for->toIso8601String(), 'snapshot' => $dose->snapshot,
                'entry_id' => $entries->firstWhere('downtime_dose_id', $dose->id)?->id,
                'resolution' => $resolved->has($dose->id) ? ['reason' => $resolved->get($dose->id)->reason,
                    'resolved_by' => User::query()->find($resolved->get($dose->id)->resolved_by)?->name] : null,
                'resolution_choices' => ! $resolved->has($dose->id) && ! $entries->firstWhere('downtime_dose_id', $dose->id) ? $this->paper->resolutionChoices($actor, $row, $dose) : []])->values()->all(),
            'entries' => $entries->map(fn ($entry) => [
                'id' => (int) $entry->id, 'snapshot' => $entry->snapshot, 'outcome' => $entry->outcome,
                'given_at' => $entry->given_at->toIso8601String(), 'entered_at' => $entry->created_at->toIso8601String(),
                'given_by' => User::query()->find($entry->given_by)?->name, 'entered_by' => User::query()->find($entry->entered_by)?->name,
                'witness' => $entry->witness_id ? User::query()->find($entry->witness_id)?->name : null,
                'dose_on_paper' => $entry->dose_on_paper, 'notes' => $entry->notes,
                'can_confirm_giver' => (int) $entry->given_by === (int) $actor->id && ! $entry->confirmations->contains('kind', 'giver'),
                'can_confirm_witness' => (int) $entry->witness_id === (int) $actor->id && ! $entry->confirmations->contains('kind', 'witness'),
                'reconciliation' => $this->paper->reconciliationPreview($actor, $row, $entry),
            ])->values()->all(),
            'sheets' => $manager && $canControlled ? $row->sheets()->get()->map(fn ($sheet) => ['id' => (int) $sheet->id, 'mime_type' => $sheet->mime_type, 'url' => '/emar/downtime/'.$row->id.'/sheets/'.$sheet->id])->all() : [],
            'sheets_concealed' => ! ($manager && $canControlled) && $row->sheets()->exists(),
            'prn_orders' => $prn, 'staff' => $staff, 'actor_id' => (int) $actor->id, 'can_manage' => $manager,
            'open_paper_entry' => $entries->contains('id', (int) $request->query('paper_entry')) ? (int) $request->query('paper_entry') : null,
            'can_finish' => $manager && $row->finished_at === null && ! $row->doses()->whereNotIn('id', $enteredTargetIds)->exists(),
            'controlled_notice' => ! $canControlled ? 'Controlled paper entries and scans are omitted. Ask the house lead with controlled-medicine access.' : null,
        ]);
    }

    public function finish(Request $request, int $downtime)
    {
        $this->downtimes->finish($request->user(), $this->access->downtime($request->user(), $downtime));

        return back()->with('success', 'Paper collection finished. Any confirmations and clinical reconciliation remain due.');
    }

    public function resolve(Request $request, int $downtime, int $dose)
    {
        $data = $request->validate(['kind' => 'required|in:clinical,paper', 'record_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:4000', 'accountable_confirmation' => 'accepted']);
        $row = $this->access->downtime($request->user(), $downtime);
        $target = $row->doses()->findOrFail($dose);
        $this->paper->resolveDuplicate($request->user(), $row, $target, $data['kind'], (int) $data['record_id'], $data['reason']);

        return back()->with('success', 'The duplicate was reviewed and linked to existing evidence. No dose was posted or changed.');
    }

    public function sheet(Request $request, int $downtime, int $sheet)
    {
        $row = $this->access->downtime($request->user(), $downtime);
        abort_unless($this->access->manages($request->user()) && $request->user()->canDo('medications.controlled.view'), 404);
        $file = $row->sheets()->findOrFail($sheet);

        return Storage::disk('private')->download($file->path, 'DT-'.$row->id.'-paper-'.$file->id.'.'.match ($file->mime_type) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg',
        }, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

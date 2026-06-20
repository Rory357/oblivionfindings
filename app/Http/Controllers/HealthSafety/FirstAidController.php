<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\StoreFirstAidRecordRequest;
use App\Http\Requests\HealthSafety\UpdateFirstAidRecordRequest;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * First Aid Register — H&S gold-standard controller (docs/first-aid-redesign).
 *
 * Modal-first register: index serves the hero/tabs/filters/list plus a lazily-resolved
 * `detail` partial (?record=) for the detail dialog. Reuses the hazards.* permission
 * scheme (no dedicated first_aid.* — see §0.2). Escalation is user-driven via
 * linkIncident (create/link a ClientIncident → existing observer cascade), so there is
 * no parallel observer and no is_notifiable/severity columns; "reportable" is derived.
 */
class FirstAidController extends Controller
{
    use RespondsToInertiaOrJson;

    /** Canonical outcome that, with the ambulance flag, marks a treatment WorkSafe-reportable. */
    private const REPORTABLE_OUTCOME = 'sent_to_hospital';

    /* ================================================================== */
    /*  Register page                                                      */
    /* ================================================================== */

    public function index(Request $request): \Inertia\Response
    {
        abort_unless((bool) $request->user()?->canDo('hazards.view'), 403);

        if (! Schema::hasTable('first_aid_records')) {
            return Inertia::render('health-safety/first-aid/index', $this->emptyPayload($request));
        }

        $filters = $this->filters($request);
        $tab = $request->string('tab')->toString() ?: 'all';
        $recordId = $request->integer('record') ?: null;
        $can = $this->canBlock($request);

        return Inertia::render('health-safety/first-aid/index', [
            'records' => fn () => $this->buildListPayload($request, $filters, $tab),
            'tab' => $tab,
            'tabCounts' => fn () => $this->tabCounts($request, $filters),
            'hero' => fn () => $this->heroData($request),
            'filters' => $filters,
            'sites' => fn () => $this->siteOptions(),
            // The picker pools carry client PII (names) + incident titles — only ship them to
            // users who can actually record/link (the wizard + link pane). View-only roles
            // (e.g. Maintenance Coordinator, H&S Officer with hazards.view only) get []. The
            // hero first-aider badge count is computed server-side in heroData(), independent
            // of this prop.
            'firstAiders' => $can['create'] ? fn () => $this->firstAiderPool() : [],
            'clients' => $can['create'] ? fn () => $this->clientOptions() : [],
            'staff' => $can['create'] ? fn () => $this->staffOptions() : [],
            'incidents' => $can['create'] ? fn () => $this->incidentOptions() : [],
            'can' => $can,
            'detail' => fn () => $recordId ? $this->buildDetailPayload($recordId, $request) : null,
            // Drives the wizard auto-open when arriving from the command-centre launcher.
            'report' => $request->boolean('report'),
        ]);
    }

    /** Export the filtered register to CSV — honours the same scope/period/tab as index(). */
    public function export(Request $request): StreamedResponse
    {
        abort_unless((bool) $request->user()?->canDo('hazards.view'), 403);

        $filters = $this->filters($request);
        $tab = $request->string('tab')->toString() ?: 'all';
        $query = $this->applyTab($this->applyPeriod($this->scopedQuery($request), $filters['period']), $tab)
            ->with(['site:id,name', 'firstAider:id,name'])
            ->orderByDesc('treatment_date')
            ->orderByDesc('id'); // stable tiebreaker so chunk() can't skip/dup rows sharing a timestamp

        $filename = 'first-aid-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Treated', 'Person type', 'Site', 'Treatment date', 'Injury / illness', 'Body part', 'Treatment given', 'Outcome', 'Ambulance', 'First-aider', 'Linked incident']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $this->reference($r->id),
                        $this->csvCell($r->treated_person_name),
                        $r->treated_person_type,
                        $this->csvCell($r->site?->name),
                        optional($r->treatment_date)->format('Y-m-d H:i'),
                        str_replace('_', ' ', (string) $r->injury_illness_type),
                        $this->csvCell($r->body_part),
                        $this->csvCell($r->treatment_given),
                        str_replace('_', ' ', (string) $r->treatment_outcome),
                        $r->ambulance_called ? 'Yes' : 'No',
                        $this->csvCell($r->firstAider?->name),
                        $r->related_incident_id ? 'INC-'.str_pad((string) $r->related_incident_id, 4, '0', STR_PAD_LEFT) : '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection (=,+,-,@,tab,CR prefixes) in a free-text CSV cell. */
    private function csvCell(?string $value): string
    {
        $v = (string) $value;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    /* ================================================================== */
    /*  CRUD                                                               */
    /* ================================================================== */

    public function store(StoreFirstAidRecordRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $record = FirstAidRecord::create($data);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'status' => 'First aid record created.',
                'id' => $record->id,
            ]);
        }

        return back()
            ->with('success', 'First aid record created.')
            ->with('created_first_aid_id', $record->id);
    }

    /** Modal-first: JSON for axios, redirect into the register (?record=) for browsers. */
    public function show(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        abort_unless((bool) $request->user()?->canDo('hazards.view'), 403);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['record' => $this->buildDetailPayload($record->id, $request)]);
        }

        return redirect()->route('health-safety.first-aid.index', ['record' => $record->id]);
    }

    public function update(UpdateFirstAidRecordRequest $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        $data = $request->validated();
        // Switching the person type away from 'client' must clear the client link, else the
        // record keeps showing on the old client's profile First-aid panel (FormRequest uses
        // `sometimes`, so an absent client_id is never nulled by itself).
        if (($data['treated_person_type'] ?? $record->treated_person_type) !== 'client') {
            $data['client_id'] = null;
        }
        $data['updated_by'] = $request->user()->id;
        $record->update($data);

        return $this->inertiaOrJson($request, 'First aid record updated.');
    }

    public function destroy(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);

        $record->delete();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'First aid record archived.']);
        }

        // back() preserves the list query (tab/filters); the modal closes once ?record drops.
        return back()->with('success', 'First aid record archived.');
    }

    /* ================================================================== */
    /*  Detail-modal actions                                               */
    /* ================================================================== */

    /**
     * Escalate a treatment to an incident: link an existing ClientIncident, or create
     * one and back-fill related_incident_id — letting the existing ClientIncidentObserver
     * run the HsEvent / governance cascade. Idempotent if already linked.
     */
    public function linkIncident(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);

        $data = $request->validate([
            'related_incident_id' => ['nullable', 'exists:client_incidents,id'],
        ]);

        if ($record->related_incident_id) {
            return $this->inertiaOrJson($request, 'Already linked to an incident.');
        }

        if (! empty($data['related_incident_id'])) {
            $record->update([
                'related_incident_id' => $data['related_incident_id'],
                'incident_reported' => true,
            ]);

            return $this->inertiaOrJson($request, 'Treatment linked to incident.');
        }

        // No existing incident chosen → create one from the treatment. client_incidents.client_id
        // is NOT-NULL, so only a client treatment with a real client_id can auto-create one;
        // staff/visitor/contractor treatments must link an existing incident instead.
        if ($record->treated_person_type !== 'client' || ! $record->client_id) {
            return back()->with('error', 'Only client treatments can create a new incident. Link an existing incident instead.');
        }

        $incident = ClientIncident::create([
            'client_id' => $record->client_id,
            'title' => 'First aid: '.str_replace('_', ' ', (string) $record->injury_illness_type),
            'description' => $record->injury_illness_description,
            'occurred_at' => $record->treatment_date,
            'reported_by' => $request->user()->id,
            'severity' => ($record->ambulance_called || $record->treatment_outcome === self::REPORTABLE_OUTCOME) ? 'high' : 'medium',
            'status' => 'submitted',
            'submitted_at' => now(),
            'type' => 'first_aid',
            // Hospital admission (sent_to_hospital) is WorkSafe-notifiable (HSWA s.23); ambulance-only
            // (assessed, not admitted) is NOT — matching FirstAidObserver so the linked and unlinked
            // escalation paths return the same verdict for the same treatment.
            'is_notifiable' => $record->treatment_outcome === self::REPORTABLE_OUTCOME,
        ]);

        $record->update([
            'incident_reported' => true,
            'related_incident_id' => $incident->id,
        ]);

        AuditLogger::log('firstaidrecord.escalated', $record, ['incident_id' => $incident->id]);

        return $this->inertiaOrJson($request, 'Incident created from treatment.', [
            'created_incident_id' => $incident->id,
        ]);
    }

    public function addFollowup(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]);

        $record->followups()->create([
            'notes' => $data['notes'],
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Audited against the record (not the followup's own morph) so it shows in History.
        AuditLogger::log('firstaidrecord.followup.add', $record, ['notes' => \Illuminate\Support\Str::limit($data['notes'], 80)]);

        return $this->inertiaOrJson($request, 'Follow-up added.');
    }

    public function completeFollowup(Request $request, FirstAidRecord $record, FirstAidFollowup $followup): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);
        abort_unless((int) $followup->first_aid_record_id === (int) $record->id, 404);

        $followup->update(['completed_at' => now()]);

        AuditLogger::log('firstaidrecord.followup.complete', $record, ['followup_id' => $followup->id]);

        return $this->inertiaOrJson($request, 'Follow-up completed.');
    }

    /* ================================================================== */
    /*  Attachments (premium document upload)                              */
    /* ================================================================== */

    public function uploadAttachment(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx'], // 20 MB; ACC45/photos/notes only
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $disk = 'public';
        $path = $file->store('first_aid_attachments', $disk);

        $record->attachments()->create([
            'uploaded_by' => $request->user()->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $data['kind'] ?? null,
            'notes' => $data['notes'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
        ]);

        AuditLogger::log('firstaidrecord.attachment.add', $record, ['original_name' => $file->getClientOriginalName()]);

        return $this->inertiaOrJson($request, 'Evidence uploaded.');
    }

    public function downloadAttachment(Request $request, FirstAidRecord $record, FirstAidAttachment $attachment)
    {
        abort_unless((bool) $request->user()?->canDo('hazards.view'), 403);
        abort_unless((int) $attachment->first_aid_record_id === (int) $record->id, 404);

        $disk = $attachment->disk ?: 'public';
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroyAttachment(Request $request, FirstAidRecord $record, FirstAidAttachment $attachment): RedirectResponse|JsonResponse
    {
        abort_unless($this->userCanManage($request), 403);
        abort_unless((int) $attachment->first_aid_record_id === (int) $record->id, 404);

        $disk = $attachment->disk ?: 'public';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }

        AuditLogger::log('firstaidrecord.attachment.remove', $record, ['attachment_id' => $attachment->id]);
        $attachment->delete();

        return $this->inertiaOrJson($request, 'Evidence removed.');
    }

    /* ================================================================== */
    /*  Query building                                                     */
    /* ================================================================== */

    /**
     * @return array{site_id:?int,treated_person_type:?string,treatment_outcome:?string,first_aider_id:?int,period:string,q:?string}
     */
    private function filters(Request $request): array
    {
        return [
            'site_id' => $request->integer('site_id') ?: null,
            'treated_person_type' => $request->string('treated_person_type')->toString() ?: null,
            'treatment_outcome' => $request->string('treatment_outcome')->toString() ?: null,
            'first_aider_id' => $request->integer('first_aider_id') ?: null,
            'period' => $request->string('period')->toString() ?: '30d',
            'q' => $request->string('q')->toString() ?: null,
        ];
    }

    /** Base query with the entity filters (site/person/outcome/first-aider/search) — no period, no tab. */
    private function scopedQuery(Request $request): Builder
    {
        $f = $this->filters($request);

        return FirstAidRecord::query()
            ->when($f['site_id'], fn (Builder $q, $v) => $q->where('site_id', $v))
            ->when($f['treated_person_type'], fn (Builder $q, $v) => $q->where('treated_person_type', $v))
            ->when($f['treatment_outcome'], fn (Builder $q, $v) => $q->where('treatment_outcome', $v))
            ->when($f['first_aider_id'], fn (Builder $q, $v) => $q->where('first_aider_id', $v))
            ->when($f['q'], fn (Builder $q, $v) => $q->where(function (Builder $sub) use ($v) {
                $sub->where('treated_person_name', 'like', "%{$v}%")
                    ->orWhere('injury_illness_description', 'like', "%{$v}%")
                    ->orWhere('treatment_given', 'like', "%{$v}%")
                    ->orWhere('body_part', 'like', "%{$v}%");
            }));
    }

    private function applyPeriod(Builder $query, string $period): Builder
    {
        $from = match ($period) {
            'week' => now()->subWeek(),
            '30d' => now()->subDays(30),
            'quarter' => now()->subMonths(3),
            default => null,
        };

        return $from ? $query->where('treatment_date', '>=', $from) : $query;
    }

    private function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'ambulance' => $query->where('ambulance_called', true),
            // "Linked" = a genuine incident link (not merely the reported flag) so the tab agrees
            // with the row's "Linked" vs "Reportable" badge.
            'linked' => $query->whereNotNull('related_incident_id'),
            'reportable' => $this->applyReportable($query),
            'followup' => $query->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at')),
            default => $query,
        };
    }

    /**
     * "Reportable · unlinked": a treatment carrying a WorkSafe/escalation signal (flagged
     * reported, ambulance called, or sent to hospital) that is NOT yet linked to an incident.
     * The single source of truth shared by the tab, the counts and the hero so they can't drift
     * (and matches the register row's "Reportable" badge).
     */
    private function applyReportable(Builder $query): Builder
    {
        return $query->whereNull('related_incident_id')
            ->where(fn (Builder $q) => $q->where('incident_reported', true)
                ->orWhere('ambulance_called', true)
                ->orWhere('treatment_outcome', self::REPORTABLE_OUTCOME));
    }

    /* ================================================================== */
    /*  Payload builders                                                   */
    /* ================================================================== */

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildListPayload(Request $request, array $filters, string $tab): LengthAwarePaginator
    {
        $query = $this->applyTab(
            $this->applyPeriod($this->scopedQuery($request), $filters['period']),
            $tab,
        );

        return $query
            ->with(['site:id,name', 'firstAider:id,name'])
            ->withCount([
                'attachments',
                'followups as open_followups_count' => fn (Builder $q) => $q->whereNull('completed_at'),
            ])
            ->orderByDesc('treatment_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FirstAidRecord $r) => $this->rowPayload($r));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(FirstAidRecord $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $this->reference($r->id),
            'treatment_date' => $r->treatment_date?->toISOString(),
            'treated_person_name' => $r->treated_person_name,
            'treated_person_type' => $r->treated_person_type,
            'injury_illness_type' => $r->injury_illness_type,
            'body_part' => $r->body_part,
            'treatment_given' => $r->treatment_given,
            'treatment_outcome' => $r->treatment_outcome,
            'ambulance_called' => (bool) $r->ambulance_called,
            'first_aider_name' => $r->firstAider?->name,
            'site_name' => $r->site?->name,
            'incident_reported' => (bool) $r->incident_reported,
            'related_incident_id' => $r->related_incident_id,
            'attachments_count' => (int) ($r->attachments_count ?? 0),
            'open_followups_count' => (int) ($r->open_followups_count ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function tabCounts(Request $request, array $filters): array
    {
        $base = $this->applyPeriod($this->scopedQuery($request), $filters['period']);

        return [
            'all' => (clone $base)->count(),
            'ambulance' => (clone $base)->where('ambulance_called', true)->count(),
            'linked' => (clone $base)->whereNotNull('related_incident_id')->count(),
            'reportable' => $this->applyReportable(clone $base)->count(),
            'followup' => (clone $base)->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at'))->count(),
        ];
    }

    /**
     * Stable command-centre overview — entity filters only, fixed windows (NOT period),
     * so the hero doesn't jump around when the Period pill changes.
     *
     * @return array<string, mixed>
     */
    private function heroData(Request $request): array
    {
        $scoped = $this->scopedQuery($request);
        $live = (clone $scoped)->where('treatment_date', '>=', now()->subDays(30));

        return [
            'live' => [
                'treated' => (clone $live)->count(),
                'ambulance' => (clone $live)->where('ambulance_called', true)->count(),
                'hospital' => (clone $live)->where('treatment_outcome', self::REPORTABLE_OUTCOME)->count(),
                'linked' => (clone $live)->whereNotNull('related_incident_id')->count(),
            ],
            'attention' => [
                'reportable_unlinked' => $this->applyReportable(clone $scoped)->count(),
                'followups_open' => (clone $scoped)->whereHas('followups', fn (Builder $q) => $q->whereNull('completed_at'))->count(),
                'no_aider' => (clone $scoped)->whereNull('first_aider_id')->count(),
                'today' => (clone $scoped)->whereDate('treatment_date', today())->count(),
            ],
            'badges' => [
                'first_aiders' => count($this->firstAiderPool()),
                'worksafe_awaiting' => $this->applyReportable(clone $scoped)->count(),
                'acc45_month' => (clone $scoped)->where('treatment_date', '>=', now()->startOfMonth())
                    ->where(fn (Builder $q) => $q->whereIn('treatment_outcome', ['medical_centre', self::REPORTABLE_OUTCOME])
                        ->orWhere('ambulance_called', true))->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDetailPayload(int $id, Request $request): ?array
    {
        $record = FirstAidRecord::query()
            ->with([
                'site:id,name',
                'firstAider:id,name',
                'treatedPerson:id,name',
                'client:id,first_name,last_name',
                'relatedIncident:id,title,type,occurred_at',
                'creator:id,name',
                'updater:id,name',
                'attachments' => fn ($q) => $q->latest()->with('uploader:id,name'),
                'followups' => fn ($q) => $q->latest()->with(['assignedTo:id,name', 'creator:id,name']),
            ])
            ->find($id);

        if (! $record) {
            return null;
        }

        $incident = $record->relatedIncident;

        return [
            'id' => $record->id,
            'reference' => $this->reference($record->id),
            'treated_person_name' => $record->treated_person_name,
            'treated_person_type' => $record->treated_person_type,
            'treatment_date' => $record->treatment_date?->toISOString(),
            'injury_illness_type' => $record->injury_illness_type,
            'injury_illness_description' => $record->injury_illness_description,
            'body_part' => $record->body_part,
            'treatment_given' => $record->treatment_given,
            'treatment_outcome' => $record->treatment_outcome,
            'ambulance_called' => (bool) $record->ambulance_called,
            'first_aider_notes' => $record->first_aider_notes,
            'incident_reported' => (bool) $record->incident_reported,
            'site' => $record->site ? ['id' => $record->site->id, 'name' => $record->site->name] : null,
            'site_id' => $record->site_id,
            'first_aider' => $record->firstAider ? ['id' => $record->firstAider->id, 'name' => $record->firstAider->name] : null,
            'first_aider_id' => $record->first_aider_id,
            'treated_person' => $record->treatedPerson ? ['id' => $record->treatedPerson->id, 'name' => $record->treatedPerson->name] : null,
            'client' => $record->client ? ['id' => $record->client->id, 'name' => trim(($record->client->first_name ?? '').' '.($record->client->last_name ?? ''))] : null,
            'client_id' => $record->client_id,
            'related_incident' => $incident ? [
                'id' => $incident->id,
                'reference' => 'INC-'.str_pad((string) $incident->id, 4, '0', STR_PAD_LEFT),
                'title' => $incident->title,
            ] : null,
            'created_by_name' => $record->creator?->name,
            'updated_by_name' => $record->updater?->name,
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
            'attachments' => $record->attachments->map(fn (FirstAidAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'url' => "/health-safety/first-aid/{$record->id}/attachments/{$a->id}/download",
                'size' => $a->size,
                'mime' => $a->mime,
                'is_image' => $a->isImage(),
                'kind' => $a->kind,
                'notes' => $a->notes,
                'uploaded_by_name' => $a->uploader?->name,
                'created_at' => $a->created_at?->toISOString(),
            ])->values(),
            'followups' => $record->followups->map(fn (FirstAidFollowup $f) => [
                'id' => $f->id,
                'notes' => $f->notes,
                'assigned_to_name' => $f->assignedTo?->name,
                'due_at' => $f->due_at?->toISOString(),
                'completed_at' => $f->completed_at?->toISOString(),
                'created_by_name' => $f->creator?->name,
                'created_at' => $f->created_at?->toISOString(),
            ])->values(),
            'history' => $this->history($record),
            'can' => ['manage' => $this->userCanManage($request)],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function history(FirstAidRecord $record): array
    {
        return AuditLog::query()
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (AuditLog $log) {
                $fields = is_array($log->meta['fields'] ?? null) ? $log->meta['fields'] : [];
                $detail = match (true) {
                    str_ends_with((string) $log->action, '.create') => 'Record created',
                    str_ends_with((string) $log->action, '.delete') => 'Record archived',
                    str_contains((string) $log->action, 'attachment.add') => 'Evidence uploaded',
                    str_contains((string) $log->action, 'attachment.remove') => 'Evidence removed',
                    str_contains((string) $log->action, 'followup.add') => 'Follow-up added',
                    str_contains((string) $log->action, 'followup.complete') => 'Follow-up completed',
                    str_contains((string) $log->action, 'escalated') => 'Escalated to an incident',
                    count($fields) > 0 => 'Updated: '.implode(', ', array_slice(array_map(
                        fn ($f) => str_replace('_', ' ', (string) $f),
                        $fields,
                    ), 0, 4)),
                    default => 'Updated',
                };

                return [
                    'id' => $log->id,
                    'timestamp' => $log->created_at?->toISOString(),
                    'action' => $log->action,
                    'actor' => $log->user?->name,
                    'detail' => $detail,
                ];
            })
            ->values()
            ->all();
    }

    /* ================================================================== */
    /*  Form options                                                       */
    /* ================================================================== */

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function siteOptions(): array
    {
        return Site::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])
            ->values()->all();
    }

    /**
     * The real first-aider pool — staff whose HR profile is flagged is_first_aider
     * (NOT every user). Drives the wizard picker and the hero "first aiders" badge.
     *
     * @return array<int, array{id:int,name:string}>
     */
    private function firstAiderPool(): array
    {
        return User::query()
            ->whereHas('hrEmployeeProfile', fn (Builder $q) => $q->where('is_first_aider', true))
            ->staff()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()->all();
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    private function clientOptions(): array
    {
        return Client::query()
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? ''))])
            ->values()->all();
    }

    /**
     * General staff list for the "treated staff" picker — links treated_person_id → users.id.
     * Real staff only (excludes client/next_of_kin), unlike firstAiderPool (is_first_aider).
     *
     * @return array<int, array{id:int,name:string}>
     */
    private function staffOptions(): array
    {
        return User::query()->staff()->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()->all();
    }

    /**
     * Recent incidents for the "link to incident" picker.
     *
     * @return array<int, array{id:int,reference:string,label:string}>
     */
    private function incidentOptions(): array
    {
        return ClientIncident::query()
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'title', 'occurred_at'])
            ->map(function (ClientIncident $i) {
                $ref = 'INC-'.str_pad((string) $i->id, 4, '0', STR_PAD_LEFT);

                return [
                    'id' => $i->id,
                    'reference' => $ref,
                    'label' => $ref.' · '.($i->title ?: 'Incident'),
                ];
            })
            ->values()->all();
    }

    /* ================================================================== */
    /*  Permissions / helpers                                              */
    /* ================================================================== */

    /**
     * @return array{view:bool,create:bool,manage:bool}
     */
    private function canBlock(Request $request): array
    {
        $user = $request->user();

        return [
            'view' => (bool) $user?->canDo('hazards.view'),
            'create' => (bool) ($user?->canDo('hazards.create') || $user?->canDo('hazards.manage')),
            'manage' => $this->userCanManage($request),
        ];
    }

    private function userCanManage(Request $request): bool
    {
        return (bool) $request->user()?->canDo('hazards.manage');
    }

    private function reference(int $id): string
    {
        return 'FA-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Fully-shaped empty payload for the deploy-ordering window before the table exists.
     *
     * @return array<string, mixed>
     */
    private function emptyPayload(Request $request): array
    {
        return [
            'records' => ['data' => [], 'links' => [], 'last_page' => 1, 'current_page' => 1],
            'tab' => 'all',
            'tabCounts' => ['all' => 0, 'ambulance' => 0, 'linked' => 0, 'reportable' => 0, 'followup' => 0],
            'hero' => [
                'live' => ['treated' => 0, 'ambulance' => 0, 'hospital' => 0, 'linked' => 0],
                'attention' => ['reportable_unlinked' => 0, 'followups_open' => 0, 'no_aider' => 0, 'today' => 0],
                'badges' => ['first_aiders' => 0, 'worksafe_awaiting' => 0, 'acc45_month' => 0],
            ],
            'filters' => $this->filters($request),
            'sites' => [],
            'firstAiders' => [],
            'clients' => [],
            'staff' => [],
            'incidents' => [],
            'can' => $this->canBlock($request),
            'detail' => null,
        ];
    }
}

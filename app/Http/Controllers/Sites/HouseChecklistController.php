<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistResponse;
use App\Models\SiteDamage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HouseChecklistController extends Controller
{
    public function index(Request $request, Site $site)
    {
        if (!in_array($site->type, ['house', 'residential'])) {
            abort(404, 'House checklists are only available for house/residential sites.');
        }

        if (!$request->user()->canDo('checklists.view')) {
            abort(403);
        }

        // Get templates: global ones for house type + site-specific ones
        $templates = SiteChecklistTemplate::where(function ($q) use ($site) {
                $q->where(function ($inner) {
                    $inner->whereNull('site_id')
                          ->where(function ($q2) {
                              $q2->where('applicable_to_type', 'house')
                                 ->orWhere('applicable_to_type', 'all');
                          });
                })
                ->orWhere('site_id', $site->id);
            })
            ->where('is_active', true)
            ->with('items')
            ->get();

        // Recent runs for this site
        $runs = SiteChecklistRun::where('site_id', $site->id)
            ->with(['template:id,name', 'completedBy:id,name', 'responses.templateItem'])
            ->withCount(['damages' => function ($q) {
                $q->whereNull('deleted_at');
            }])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Rooms for damage location dropdown
        $rooms = $site->houseRooms()->active()->orderBy('sort_order')->get(['id', 'name']);

        return Inertia::render('sites/checklists/house-index', [
            'site' => $site,
            'templates' => $templates,
            'runs' => $runs,
            'rooms' => $rooms,
            'canManage' => $request->user()->canDo('checklists.manage_templates'),
            'canRun' => $request->user()->canDo('checklists.run'),
        ]);
    }

    public function storeTemplate(Request $request, Site $site)
    {
        if (!in_array($site->type, ['house', 'residential'])) {
            abort(404);
        }

        if (!$request->user()->canDo('checklists.manage_templates')) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'frequency' => ['required', 'string', 'in:daily,weekly,fortnightly,monthly,quarterly,once'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.question' => ['required', 'string', 'max:255'],
            'items.*.response_type' => ['required', 'string', 'in:yes_no,yes_no_na,pass_fail,numeric,text,photo'],
            'items.*.is_required' => ['boolean'],
        ]);

        // Generate a unique key for the template
        $key = 'house_' . $site->id . '_' . Str::slug($data['name'], '_') . '_' . Str::random(4);

        $template = SiteChecklistTemplate::create([
            'site_id' => $site->id,
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'applicable_to_type' => 'house',
            'frequency' => $data['frequency'],
            'is_active' => true,
        ]);

        foreach ($data['items'] as $index => $item) {
            $template->items()->create([
                'question' => $item['question'],
                'response_type' => $item['response_type'],
                'is_required' => $item['is_required'] ?? true,
                'sort_order' => $index,
            ]);
        }

        return redirect()->back()->with('success', 'Checklist template created.');
    }

    public function startRun(Request $request, Site $site, SiteChecklistTemplate $template)
    {
        if (!in_array($site->type, ['house', 'residential'])) {
            abort(404);
        }

        if (!$request->user()->canDo('checklists.run')) {
            abort(403);
        }

        // Find or create an assignment for this template + site
        $assignment = SiteChecklistAssignment::firstOrCreate(
            [
                'site_id' => $site->id,
                'template_id' => $template->id,
            ],
            [
                'frequency' => $template->frequency,
                'start_date' => now()->toDateString(),
                'is_active' => true,
            ]
        );

        if ($assignment->runs()->awaitingCompletion()->exists()) {
            return redirect()->back()->with(
                'info',
                'Complete the existing checklist run before starting another.'
            );
        }

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'template_id' => $template->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'scheduled_date' => now()->toDateString(),
            'started_at' => now(),
            'status' => 'in_progress',
        ]);

        return redirect()->back()->with('success', 'Checklist run started.');
    }

    public function completeRun(Request $request, Site $site, SiteChecklistRun $run)
    {
        abort_unless($run->site_id === $site->id, 404);

        if (!in_array($site->type, ['house', 'residential'])) {
            abort(404);
        }

        if (!$request->user()->canDo('checklists.run')) {
            abort(403);
        }

        $data = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.template_item_id' => ['required', 'exists:site_checklist_template_items,id'],
            'responses.*.response_value' => ['nullable', 'string'],
            'responses.*.notes' => ['nullable', 'string'],
            'damages' => ['nullable', 'array'],
            'damages.*.title' => ['required', 'string', 'max:255'],
            'damages.*.description' => ['required', 'string'],
            'damages.*.severity' => ['required', 'string', 'in:minor,moderate,major,critical'],
            'damages.*.location_in_site' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['responses'] as $response) {
            SiteChecklistResponse::updateOrCreate(
                [
                    'run_id' => $run->id,
                    'template_item_id' => $response['template_item_id'],
                ],
                [
                    'response_value' => $response['response_value'] ?? '',
                    'notes' => $response['notes'] ?? null,
                ]
            );
        }

        // Create damage reports from checklist
        $damagesCreated = 0;
        if (!empty($data['damages'])) {
            foreach ($data['damages'] as $damage) {
                SiteDamage::create([
                    'tenant_id' => $site->tenant_id,
                    'site_id' => $site->id,
                    'reported_by' => $request->user()->id,
                    'title' => $damage['title'],
                    'description' => $damage['description'],
                    'severity' => $damage['severity'],
                    'location_in_site' => $damage['location_in_site'] ?? null,
                    'status' => 'reported',
                    'damage_date' => now()->toDateString(),
                    'discovered_date' => now()->toDateString(),
                    'insurance_status' => 'not_applicable',
                    'checklist_run_id' => $run->id,
                ]);
                $damagesCreated++;
            }
        }

        $run->calculateCompletion();

        $run->update([
            'status' => 'completed',
            'completed_by_user_id' => $request->user()->id,
            'completed_at' => now(),
        ]);

        $message = 'Checklist run completed.';
        if ($damagesCreated > 0) {
            $message .= " {$damagesCreated} damage " . ($damagesCreated === 1 ? 'report' : 'reports') . ' created.';
        }

        return redirect()->back()->with('success', $message);
    }
}

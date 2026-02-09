<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteChecklistTemplateController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SiteChecklistTemplate::class);

        $templates = SiteChecklistTemplate::withCount('items')
            ->when($request->type, fn($q) => $q->where('applicable_to_type', $request->type))
            ->when($request->status === 'active', fn($q) => $q->active())
            ->when($request->status === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20);

        return inertia('sites/checklists/templates/index', [
            'templates' => $templates,
            'filters' => $request->only(['type', 'status']),
        ]);
    }

    public function create()
    {
        $this->authorize('create', SiteChecklistTemplate::class);

        return inertia('sites/checklists/templates/create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', SiteChecklistTemplate::class);

        $validated = $request->validate([
            'key' => 'required|string|max:50|unique:site_checklist_templates,key',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'applicable_to_type' => 'required|in:house,head_office,facility,all',
            'frequency' => 'required|in:once,daily,weekly,fortnightly,monthly,quarterly',
            'is_active' => 'boolean',
        ]);

        $template = SiteChecklistTemplate::create($validated);

        return redirect()
            ->route('sites.checklists.templates.edit', $template)
            ->with('success', 'Checklist template created. Now add items.');
    }

    public function edit(SiteChecklistTemplate $template)
    {
        $this->authorize('update', $template);

        $template->load('items');

        return inertia('sites/checklists/templates/edit', [
            'template' => $template,
        ]);
    }

    public function update(Request $request, SiteChecklistTemplate $template)
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'applicable_to_type' => 'required|in:house,head_office,facility,all',
            'frequency' => 'required|in:once,daily,weekly,fortnightly,monthly,quarterly',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function destroy(SiteChecklistTemplate $template)
    {
        $this->authorize('delete', $template);

        // Check if template has assignments
        if ($template->assignments()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete template with active site assignments.');
        }

        $template->delete();

        return redirect()
            ->route('sites.checklists.templates.index')
            ->with('success', 'Template deleted.');
    }

    // Item management
    public function storeItem(Request $request, SiteChecklistTemplate $template)
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'question' => 'required|string',
            'response_type' => 'required|in:yes_no,pass_fail,number,text,select,multiple,photo',
            'response_config' => 'nullable|array',
            'is_required' => 'boolean',
            'guidance' => 'nullable|string',
            'failure_creates_hazard' => 'boolean',
        ]);

        $maxOrder = $template->items()->max('sort_order') ?? 0;

        $template->items()->create([
            ...$validated,
            'sort_order' => $maxOrder + 1,
        ]);

        return redirect()->back()->with('success', 'Item added.');
    }

    public function updateItem(Request $request, SiteChecklistTemplateItem $item)
    {
        $this->authorize('update', $item->template);

        $validated = $request->validate([
            'question' => 'required|string',
            'response_type' => 'required|in:yes_no,pass_fail,number,text,select,multiple,photo',
            'response_config' => 'nullable|array',
            'is_required' => 'boolean',
            'guidance' => 'nullable|string',
            'failure_creates_hazard' => 'boolean',
        ]);

        $item->update($validated);

        return redirect()->back()->with('success', 'Item updated.');
    }

    public function destroyItem(SiteChecklistTemplateItem $item)
    {
        $this->authorize('update', $item->template);

        $item->delete();

        // Reorder remaining items
        $item->template->items()->orderBy('sort_order')->get()->each(function ($i, $idx) {
            $i->update(['sort_order' => $idx + 1]);
        });

        return redirect()->back()->with('success', 'Item removed.');
    }

    public function reorderItems(Request $request, SiteChecklistTemplate $template)
    {
        $this->authorize('update', $template);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:site_checklist_template_items,id',
            'items.*.sort_order' => 'required|integer',
        ]);

        foreach ($validated['items'] as $item) {
            $template->items()->where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }
}

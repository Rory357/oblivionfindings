<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\SiteChecklistTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Checklist templates are created/edited/deleted entirely from the in-page
 * builder modal on /checklists (and the per-site Checklists tab) — there are no
 * standalone template pages. Every action returns back() so the modal can close
 * without navigating.
 */
class SiteChecklistTemplateController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', SiteChecklistTemplate::class);

        $validated = $this->validateTemplate($request, null);

        DB::transaction(function () use ($validated) {
            $template = SiteChecklistTemplate::create([
                'key' => $validated['key'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'] ?? null,
                'applicable_to_type' => $validated['applicable_to_type'],
                'frequency' => $validated['frequency'],
                'is_active' => $validated['is_active'] ?? true,
                'settings' => $this->settingsPayload($validated),
            ]);

            $this->persistItems($template, $validated['items'] ?? []);
        });

        return redirect()->back()->with('success', 'Checklist template created.');
    }

    public function update(Request $request, SiteChecklistTemplate $template)
    {
        $this->authorize('update', $template);

        $validated = $this->validateTemplate($request, $template);

        DB::transaction(function () use ($template, $validated) {
            $template->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'] ?? null,
                'applicable_to_type' => $validated['applicable_to_type'],
                'frequency' => $validated['frequency'],
                'is_active' => $validated['is_active'] ?? true,
                'settings' => $this->settingsPayload($validated),
            ]);

            $this->persistItems($template, $validated['items'] ?? []);
        });

        return redirect()->back()->with('success', 'Checklist template updated.');
    }

    public function destroy(SiteChecklistTemplate $template)
    {
        $this->authorize('delete', $template);

        if ($template->assignments()->where('is_active', true)->exists()) {
            return redirect()->back()->with('error', 'Cannot delete a template with active site assignments. Remove the assignments first.');
        }

        $template->delete();

        return redirect()->back()->with('success', 'Checklist template deleted.');
    }

    private function validateTemplate(Request $request, ?SiteChecklistTemplate $template): array
    {
        $categoryKeys = array_column(config('checklists.categories'), 'key');

        $keyRule = $template
            ? ['sometimes', 'string', 'max:50']
            : ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:site_checklist_templates,key'];

        return $request->validate([
            'key' => $keyRule,
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', Rule::in($categoryKeys)],
            'applicable_to_type' => ['required', 'in:house,head_office,facility,all'],
            'frequency' => ['required', 'in:once,daily,weekly,fortnightly,monthly,quarterly,annual'],
            'is_active' => ['boolean'],
            'requires_photo' => ['boolean'],
            'requires_signature' => ['boolean'],
            'items' => ['array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.question' => ['required', 'string', 'max:500'],
            'items.*.response_type' => ['required', 'in:yes_no,yes_no_na,pass_fail,numeric,text,photo'],
            'items.*.response_config' => ['nullable', 'array'],
            'items.*.is_required' => ['boolean'],
            'items.*.guidance' => ['nullable', 'string', 'max:1000'],
            'items.*.failure_creates_hazard' => ['boolean'],
            'items.*.failure_creates_damage' => ['boolean'],
        ]);
    }

    private function settingsPayload(array $validated): array
    {
        return [
            'requires_photo' => (bool) ($validated['requires_photo'] ?? false),
            'requires_signature' => (bool) ($validated['requires_signature'] ?? false),
        ];
    }

    /**
     * Sync the template's items to the submitted list: update existing rows,
     * create new ones, and remove rows the editor dropped — but never delete an
     * item that already has run responses (that would orphan completion data).
     */
    private function persistItems(SiteChecklistTemplate $template, array $items): void
    {
        $keep = [];

        foreach (array_values($items) as $index => $item) {
            $payload = [
                'tenant_id' => $template->tenant_id,
                'sort_order' => $index,
                'question' => $item['question'],
                'response_type' => $item['response_type'],
                'response_config' => $this->normaliseResponseConfig($item),
                'is_required' => (bool) ($item['is_required'] ?? true),
                'guidance' => $item['guidance'] ?? null,
                'failure_creates_hazard' => (bool) ($item['failure_creates_hazard'] ?? false),
                'failure_creates_damage' => (bool) ($item['failure_creates_damage'] ?? false),
            ];

            $existing = ! empty($item['id'])
                ? $template->items()->whereKey($item['id'])->first()
                : null;

            if ($existing) {
                $existing->update($payload);
                $keep[] = $existing->id;
            } else {
                $keep[] = $template->items()->create($payload)->id;
            }
        }

        $template->items()
            ->whereNotIn('id', $keep ?: [0])
            ->get()
            ->each(function ($item) {
                if (! $item->responses()->exists()) {
                    $item->delete();
                }
            });
    }

    /**
     * Keep only meaningful numeric config (min/max/unit); other types store null.
     */
    private function normaliseResponseConfig(array $item): ?array
    {
        if (($item['response_type'] ?? null) !== 'numeric') {
            return null;
        }

        $cfg = $item['response_config'] ?? [];
        $out = [];
        if (isset($cfg['min']) && $cfg['min'] !== '' && $cfg['min'] !== null) {
            $out['min'] = (float) $cfg['min'];
        }
        if (isset($cfg['max']) && $cfg['max'] !== '' && $cfg['max'] !== null) {
            $out['max'] = (float) $cfg['max'];
        }
        if (! empty($cfg['unit'])) {
            $out['unit'] = (string) $cfg['unit'];
        }

        return $out ?: null;
    }
}

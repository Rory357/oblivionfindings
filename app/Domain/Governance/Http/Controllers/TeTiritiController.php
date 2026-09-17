<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\TeTiritiObligation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * How the organisation meets its Te Tiriti o Waitangi commitments under
 * Ngā Paerewa section 1, grouped by the Hauora (Wai 2575) principles.
 */
class TeTiritiController extends Controller
{
    private const STORED_STATUSES = ['not_started', 'in_progress', 'achieved', 'ongoing', 'implemented', 'embedded'];

    private const PRESENTED_STATUSES = ['not_started', 'in_progress', 'implemented', 'embedded'];

    public function index(Request $request)
    {
        $obligations = TeTiritiObligation::query()
            ->with('owner:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (TeTiritiObligation $obligation) => TeTiritiObligation::principleKey($obligation->principle))
            ->map(fn ($group) => $group->map(fn (TeTiritiObligation $obligation) => [
                'id' => $obligation->id,
                'principle' => TeTiritiObligation::principleKey($obligation->principle),
                'title' => $obligation->title,
                'description' => $obligation->description,
                'implementation_status' => $this->presentStatus($obligation->status),
                'evidence_notes' => $obligation->evidence,
                'target_date' => $obligation->target_date?->toDateString(),
                // Names only — never staff email addresses.
                'owner' => $obligation->owner ? ['id' => $obligation->owner->id, 'name' => $obligation->owner->name] : null,
                'order' => $obligation->id,
            ])->values());

        $canManage = (bool) $request->user()?->canDo('governance.te-tiriti.manage');

        return Inertia::render('Governance/TeTiriti/Index', [
            'obligationsByPrinciple' => $obligations,
            'principles' => TeTiritiObligation::principleOptions(),
            'owners' => $canManage
                ? User::staff()->select('id', 'name')->orderBy('name')->get()
                : [],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'principle' => ['required', Rule::in(array_keys(TeTiritiObligation::PRINCIPLES))],
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'status' => ['nullable', Rule::in(self::STORED_STATUSES)],
            'implementation_status' => ['nullable', Rule::in(self::PRESENTED_STATUSES)],
            'evidence' => 'nullable|string',
            'evidence_notes' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
            'owner_id' => ['nullable', 'integer', $this->staffMember()],
        ], $this->messages());

        TeTiritiObligation::create([
            'principle' => $validated['principle'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => $this->normalizeStatus($validated['status'] ?? $validated['implementation_status'] ?? 'not_started'),
            'evidence' => $validated['evidence'] ?? $validated['evidence_notes'] ?? null,
            'actions_taken' => $validated['actions_taken'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
            'progress_pct' => $validated['progress_pct'] ?? 0,
            'owner_id' => $validated['owner_id'] ?? auth()->id(),
        ]);

        return redirect()->back()->with('success', 'Commitment added.');
    }

    public function update(Request $request, TeTiritiObligation $obligation)
    {
        $validated = $request->validate([
            'principle' => ['sometimes', 'required', Rule::in(array_keys(TeTiritiObligation::PRINCIPLES))],
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'status' => ['nullable', Rule::in(self::STORED_STATUSES)],
            'implementation_status' => ['nullable', Rule::in(self::PRESENTED_STATUSES)],
            'evidence' => 'nullable|string',
            'evidence_notes' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'target_date' => 'nullable|date',
            'progress_pct' => 'nullable|integer|min:0|max:100',
            'owner_id' => ['sometimes', 'nullable', 'integer', $this->staffMember()],
        ], $this->messages());

        $payload = [];

        foreach (['principle', 'title', 'description', 'actions_taken', 'target_date', 'progress_pct', 'owner_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('status', $validated) || array_key_exists('implementation_status', $validated)) {
            $payload['status'] = $this->normalizeStatus($validated['status'] ?? $validated['implementation_status']);
        }

        if (array_key_exists('evidence', $validated) || array_key_exists('evidence_notes', $validated)) {
            $payload['evidence'] = $validated['evidence'] ?? $validated['evidence_notes'] ?? null;
        }

        $obligation->update($payload);

        return redirect()->back()->with('success', 'Commitment saved.');
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'principle.required' => 'Choose the principle this commitment gives effect to.',
            'principle.in' => 'Choose the principle from the list.',
            'title.required' => 'Give the commitment a name.',
            'title.max' => 'Keep the name to 255 characters or fewer.',
            'description.required' => 'Describe what the organisation commits to.',
            'status.in' => 'Choose a status from the list.',
            'implementation_status.in' => 'Choose a status from the list.',
            'target_date.date' => 'Enter the target date as a date.',
            'owner_id.integer' => 'Choose who is responsible from the list.',
        ];
    }

    /** The owner must be a staff member (never a client or next of kin). */
    protected function staffMember(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && ! User::staff()->whereKey((int) $value)->exists()) {
                $fail('Choose who is responsible from the list.');
            }
        };
    }

    protected function normalizeStatus(?string $status): string
    {
        return match ($status) {
            'implemented' => 'achieved',
            'embedded' => 'ongoing',
            default => $status ?? 'not_started',
        };
    }

    protected function presentStatus(?string $status): string
    {
        return match ($status) {
            'achieved' => 'implemented',
            'ongoing' => 'embedded',
            default => $status ?? 'not_started',
        };
    }
}

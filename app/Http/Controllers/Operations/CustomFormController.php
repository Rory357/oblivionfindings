<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\CustomForm;
use App\Models\CustomFormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomFormController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.viewAny'), 403);

        $scope = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id));

        $forms = (clone $scope)
            ->withCount('submissions')
            ->with('creator:id,name')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = '%' . trim((string) $request->query('q')) . '%';

                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('form_type', 'like', $search);
                });
            })
            ->when($request->query('status') === 'active', fn ($query) => $query->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CustomForm $form) => [
                'id' => $form->id,
                'name' => $form->name,
                'description' => $form->description,
                'form_type' => $form->form_type ?: 'general',
                'is_active' => (bool) $form->is_active,
                'submissions_count' => (int) ($form->submissions_count ?? 0),
                'created_at' => optional($form->created_at)->toISOString(),
                'created_by' => $form->creator
                    ? ['id' => $form->creator->id, 'name' => $form->creator->name]
                    : null,
            ]);

        $stats = [
            'total' => (clone $scope)->count(),
            'active' => (clone $scope)->where('is_active', true)->count(),
            'submissions_this_week' => CustomFormSubmission::query()
                ->whereHas('form', function ($query) use ($auth) {
                    if ($auth->organization_id) {
                        $query->where('organization_id', $auth->organization_id);
                    }
                })
                ->where('created_at', '>=', now()->startOfWeek())
                ->count(),
        ];

        return inertia('operations/forms/Index', [
            'forms' => $forms,
            'filters' => [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
            ],
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('custom_forms.view') || $auth->canDo('custom_forms.viewAny')), 403);

        $form = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($form);

        return inertia('operations/forms/Show', [
            'form' => $form,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.create'), 403);

        return inertia('operations/forms/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'form_type' => ['required', 'in:general,shift,care_delivery,handover,incident,medication'],
            'schema' => ['required', 'array', 'min:1'],
            'schema.*.label' => ['required', 'string', 'max:255'],
            'schema.*.type' => ['required', 'string', 'max:50'],
            'schema.*.required' => ['nullable', 'boolean'],
            'schema.*.options' => ['nullable', 'array'],
            'schema.*.options.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        CustomForm::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'form_type' => $data['form_type'],
            'schema' => $data['schema'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Form created.');
    }

    public function edit(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('custom_forms.edit') || $auth->canDo('custom_forms.update')), 403);

        $form = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($form);

        return inertia('operations/forms/Edit', [
            'form' => $form,
        ]);
    }

    public function update(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('custom_forms.edit') || $auth->canDo('custom_forms.update')), 403);

        $form = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($form);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'form_type' => ['sometimes', 'required', 'in:general,shift,care_delivery,handover,incident,medication'],
            'schema' => ['sometimes', 'required', 'array', 'min:1'],
            'schema.*.label' => ['required_with:schema', 'string', 'max:255'],
            'schema.*.type' => ['required_with:schema', 'string', 'max:50'],
            'schema.*.required' => ['nullable', 'boolean'],
            'schema.*.options' => ['nullable', 'array'],
            'schema.*.options.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $form->update($data);

        return redirect()->back()->with('success', 'Form updated.');
    }

    public function submissions(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('custom_forms.view') || $auth->canDo('custom_forms.viewAny')), 403);

        $form = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($form);

        $submissions = CustomFormSubmission::query()
            ->where('custom_form_id', $form->id)
            ->with(['submitter:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/forms/Submissions', [
            'form' => $form,
            'submissions' => $submissions,
        ]);
    }

    public function submitForm(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.submit'), 403);

        $form = CustomForm::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($form);

        $data = $request->validate([
            'data' => ['required', 'array'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $shift = null;
        if (!empty($data['shift_id'])) {
            $shift = Shift::query()
                ->select(['id', 'client_id'])
                ->findOrFail($data['shift_id']);

            $data['client_id'] = $data['client_id'] ?? $shift->client_id;
        }

        if ($shift && !empty($data['client_id']) && (int) $shift->client_id !== (int) $data['client_id']) {
            throw ValidationException::withMessages([
                'shift_id' => 'The selected shift does not match the selected client.',
            ]);
        }

        $this->validateSubmissionAgainstSchema($form, $data['data']);

        CustomFormSubmission::create([
            'organization_id' => $auth->organization_id,
            'custom_form_id' => $form->id,
            'data' => $data['data'],
            'submitted_by' => $auth->id,
            'client_id' => $data['client_id'] ?? null,
            'shift_id' => $shift?->id,
            'status' => 'submitted',
        ]);

        return redirect()->back()->with('success', 'Form submitted.');
    }

    private function validateSubmissionAgainstSchema(CustomForm $form, array $payload): void
    {
        $errors = [];

        foreach (($form->schema ?? []) as $index => $field) {
            if (!($field['required'] ?? false)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? 'Field'));
            $key = trim((string) ($field['key'] ?? Str::slug($label ?: "field_{$index}", '_')));
            $value = $payload[$key] ?? null;

            $isEmpty = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && count($value) === 0);

            if ($isEmpty) {
                $errors["data.{$key}"] = "{$label} is required.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}

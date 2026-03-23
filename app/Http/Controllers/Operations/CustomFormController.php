<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CustomForm;
use App\Models\CustomFormSubmission;
use Illuminate\Http\Request;

class CustomFormController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.viewAny'), 403);

        $forms = CustomForm::query()
            ->where('organization_id', $auth->organization_id)
            ->withCount('submissions')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/forms/Index', [
            'forms' => $forms,
        ]);
    }

    public function show(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.view'), 403);

        $form = CustomForm::query()
            ->where('organization_id', $auth->organization_id)
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
            'schema' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        CustomForm::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'schema' => $data['schema'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Form created.');
    }

    public function edit(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.edit'), 403);

        $form = CustomForm::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($form);

        return inertia('operations/forms/Edit', [
            'form' => $form,
        ]);
    }

    public function update(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.edit'), 403);

        $form = CustomForm::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($form);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schema' => ['sometimes', 'required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $form->update($data);

        return redirect()->back()->with('success', 'Form updated.');
    }

    public function submissions(Request $request, $form)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('custom_forms.view'), 403);

        $form = CustomForm::query()
            ->where('organization_id', $auth->organization_id)
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
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($form);

        $data = $request->validate([
            'data' => ['required', 'array'],
        ]);

        CustomFormSubmission::create([
            'custom_form_id' => $form->id,
            'data' => $data['data'],
            'submitted_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Form submitted.');
    }
}

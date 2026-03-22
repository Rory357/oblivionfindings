<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCustomFieldDefinition;
use App\Domain\Hr\Models\HrCustomFieldValue;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomFieldController extends Controller
{
    /**
     * List all custom field definitions.
     */
    public function definitions(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $definitions = HrCustomFieldDefinition::forTenant($user->tenant_id)
            ->ordered()
            ->with('creator:id,name')
            ->get();

        return Inertia::render('hr/settings/custom-fields', [
            'definitions' => $definitions,
            'fieldTypes' => HrCustomFieldDefinition::FIELD_TYPES,
        ]);
    }

    /**
     * Create a new custom field definition.
     */
    public function storeDefinition(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'string', Rule::in(HrCustomFieldDefinition::FIELD_TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'is_required' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $fieldKey = Str::slug($validated['name'], '_');

        // Ensure unique field_key per tenant
        $suffix = 0;
        $baseKey = $fieldKey;
        while (HrCustomFieldDefinition::forTenant($user->tenant_id)->where('field_key', $fieldKey)->exists()) {
            $suffix++;
            $fieldKey = $baseKey . '_' . $suffix;
        }

        HrCustomFieldDefinition::create([
            'tenant_id' => $user->tenant_id,
            'name' => $validated['name'],
            'field_key' => $fieldKey,
            'field_type' => $validated['field_type'],
            'options' => $validated['options'] ?? null,
            'is_required' => $validated['is_required'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return redirect()->route('hr.settings.custom-fields')
            ->with('success', 'Custom field created successfully.');
    }

    /**
     * Update a custom field definition.
     */
    public function updateDefinition(Request $request, HrCustomFieldDefinition $definition)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        abort_unless($definition->tenant_id === $user->tenant_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'string', Rule::in(HrCustomFieldDefinition::FIELD_TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $definition->update($validated);

        return redirect()->route('hr.settings.custom-fields')
            ->with('success', 'Custom field updated successfully.');
    }

    /**
     * Delete a custom field definition and all its values.
     */
    public function destroyDefinition(Request $request, HrCustomFieldDefinition $definition)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        abort_unless($definition->tenant_id === $user->tenant_id, 403);

        $definition->delete(); // cascadeOnDelete handles values

        return redirect()->route('hr.settings.custom-fields')
            ->with('success', 'Custom field deleted successfully.');
    }

    /**
     * Get custom field values for an employee.
     */
    public function employeeFields(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);

        $definitions = HrCustomFieldDefinition::forTenant($user->tenant_id)
            ->active()
            ->ordered()
            ->get();

        $values = HrCustomFieldValue::where('employee_profile_id', $profile->id)
            ->whereIn('field_definition_id', $definitions->pluck('id'))
            ->get()
            ->keyBy('field_definition_id');

        $fields = $definitions->map(fn ($def) => [
            'definition' => $def,
            'value' => $values->get($def->id)?->value,
        ]);

        return response()->json($fields);
    }

    /**
     * Save custom field values for an employee.
     */
    public function updateEmployeeFields(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $validated = $request->validate([
            'fields' => ['required', 'array'],
            'fields.*.definition_id' => ['required', 'integer', 'exists:hr_custom_field_definitions,id'],
            'fields.*.value' => ['nullable', 'string'],
        ]);

        foreach ($validated['fields'] as $field) {
            HrCustomFieldValue::updateOrCreate(
                [
                    'employee_profile_id' => $profile->id,
                    'field_definition_id' => $field['definition_id'],
                ],
                [
                    'value' => $field['value'],
                ]
            );
        }

        return back()->with('success', 'Custom fields updated successfully.');
    }
}

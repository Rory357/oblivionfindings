<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceGroupAutoRuleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorContract;
use Inertia\Inertia;

class DeviceGroupController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly DeviceGroupAutoRuleService $autoRules,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $visibleDeviceIds = $this->access->visibleDevices($user)->select('devices.id');
        $query = DeviceGroup::query()->withCount([
            'devices' => fn ($devices) => $devices->whereIn('devices.id', clone $visibleDeviceIds),
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        $query->orderBy('name');

        $groups = $query->paginate(30)->withQueryString();

        return Inertia::render('security-devices/device-groups/index', [
            'groups' => [
                'data' => $groups->getCollection()->map(fn (DeviceGroup $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'type' => $g->type,
                    'description' => $g->description,
                    'devices_count' => $g->devices_count,
                    'created_at' => $g->created_at?->toISOString(),
                ]),
                'links' => $groups->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $groups->currentPage(),
                    'last_page' => $groups->lastPage(),
                    'total' => $groups->total(),
                ],
            ],
            'filters' => $request->only(['search', 'type']),
        ]);
    }

    public function show(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $visibleDevices = $this->access->visibleDevices($user);
        $visibleDeviceIds = (clone $visibleDevices)->select('devices.id');
        $members = $group->devices()
            ->whereIn('devices.id', clone $visibleDeviceIds)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        // Available devices for the add-member dialog (not already in this group).
        $existingDeviceIds = $group->devices()->pluck('devices.id');
        $availableDevices = (clone $visibleDevices)
            ->whereNotIn('id', $existingDeviceIds)
            ->operational()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'device_uid', 'domain', 'category']);

        $autoRules = is_array($group->auto_rules) ? $group->auto_rules : null;
        $autoRuleConditionCount = $autoRules
            ? count($autoRules['conditions'] ?? [])
            : 0;

        return Inertia::render('security-devices/device-groups/show', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'type' => $group->type,
                'description' => $group->description,
                'created_at' => $group->created_at?->toISOString(),
                'auto_rules' => $autoRules,
                'auto_rule_condition_count' => $autoRuleConditionCount,
            ],
            'members' => [
                'data' => $members->getCollection()->map(fn (Device $d) => $this->mapDeviceForList($d)),
                'links' => $members->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $members->currentPage(),
                    'last_page' => $members->lastPage(),
                    'total' => $members->total(),
                ],
            ],
            'availableDevices' => $availableDevices->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'domain' => $d->domain,
                'category' => $d->category,
            ]),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        return Inertia::render('security-devices/device-groups/create');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:device_groups,name'],
            'type' => ['nullable', 'string', 'in:location,functional,vendor,maintenance,custom'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated = array_merge($validated, $this->validateAutoRules($request));

        $validated['type'] = $validated['type'] ?? 'custom';

        $group = DeviceGroup::create($validated);

        return redirect()->route('security-devices.device-groups.show', $group)
            ->with('success', "Group '{$group->name}' created.");
    }

    public function edit(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        return Inertia::render('security-devices/device-groups/edit', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'type' => $group->type,
                'description' => $group->description,
                'auto_rules' => is_array($group->auto_rules) ? $group->auto_rules : null,
            ],
        ]);
    }

    public function update(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', "unique:device_groups,name,{$group->id}"],
            'type' => ['nullable', 'string', 'in:location,functional,vendor,maintenance,custom'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $validated = array_merge($validated, $this->validateAutoRules($request));

        $group->update($validated);

        return redirect()->route('security-devices.device-groups.show', $group)
            ->with('success', "Group '{$group->name}' updated.");
    }

    /**
     * Preview which devices auto_rules would match, without changing membership.
     * Returns a JSON summary suitable for an "are you sure?" dialog.
     */
    public function previewAutoRules(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $result = $this->autoRules->preview(
            $group,
            deviceScope: $this->access->visibleDevices($user),
        );

        return response()->json([
            'count' => $result['count'],
            'changes' => $this->autoRules->previewChanges(
                $group,
                $this->access->visibleDevices($user),
            ),
            'sample' => $result['sample']->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'category' => $d->category,
            ])->values(),
        ]);
    }

    /** Preview unsaved builder rules without creating or changing membership. */
    public function previewDraftAutoRules(Request $request)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $validated = $this->validateAutoRules($request, required: true);
        $result = $this->autoRules->previewRules(
            $validated['auto_rules'],
            deviceScope: $this->access->visibleDevices($user),
        );

        return response()->json([
            'count' => $result['count'],
            'sample' => $result['sample']->map(fn (Device $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'device_uid' => $device->device_uid,
                'category' => $device->category,
            ])->values(),
        ]);
    }

    /**
     * Apply auto_rules to the group's membership. Adds newly-matching devices,
     * removes devices that no longer match, keeps ones that still match.
     */
    public function syncAutoRules(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];
        if (empty($rules) || ! $this->autoRules->areRulesSupported($rules)) {
            return redirect()->back()->with('error', 'This group has no valid automatic-membership rule. Edit and save the rule before applying changes.');
        }

        $delta = $this->autoRules->applyToGroup(
            $group,
            $this->access->visibleDevices($user),
        );

        return redirect()->back()->with(
            'success',
            "Auto-rules applied: added {$delta['added']}, removed {$delta['removed']}, kept {$delta['kept']} (total {$delta['total']} matches).",
        );
    }

    public function destroy(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $name = $group->name;
        $group->delete(); // soft delete

        return redirect()->route('security-devices.device-groups')
            ->with('success', "Group '{$name}' deleted.");
    }

    public function addMember(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);

        $validated = $request->validate([
            'device_id' => ['required', 'integer'],
        ]);
        $device = $this->access->visibleDevices($user)
            ->whereKey($validated['device_id'])
            ->first();
        abort_unless($device, 404);

        // Prevent duplicate membership.
        if ($group->devices()->where('devices.id', $device->id)->exists()) {
            return back()->withErrors(['device_id' => 'Device is already a member of this group.']);
        }

        $group->devices()->attach($device->id);

        return back()->with('success', 'Device added to group.');
    }

    public function removeMember(Request $request, DeviceGroup $group, Device $device)
    {
        $user = $request->user();
        $this->assertCanManageGroups($user);
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($group->devices()->where('devices.id', $device->id)->exists(), 404);

        $group->devices()->detach($device->id);

        return back()->with('success', 'Device removed from group.');
    }

    private function assertCanManageGroups(User $user): void
    {
        abort_unless(
            $user->canDo('securityDevices.groups.manage') && $this->access->canViewAllSites($user),
            403,
        );
    }

    /**
     * Validate and normalise the governed automatic-membership schema.
     *
     * @return array{auto_rules?: array{match: string, conditions: list<array{field: string, op: string, value: string|list<string>}>}|null}
     */
    private function validateAutoRules(Request $request, bool $required = false): array
    {
        $validator = Validator::make($request->all(), [
            'auto_rules' => [$required ? 'required' : 'nullable', 'array:match,conditions'],
            'auto_rules.match' => ['required_with:auto_rules', Rule::in(['all', 'any'])],
            'auto_rules.conditions' => ['required_with:auto_rules', 'array', 'min:1', 'max:8'],
            'auto_rules.conditions.*' => ['required', 'array:field,op,value'],
            'auto_rules.conditions.*.field' => ['required', 'string', Rule::in(DeviceGroupAutoRuleService::ALLOWED_FIELDS)],
            'auto_rules.conditions.*.op' => ['required', 'string', Rule::in(DeviceGroupAutoRuleService::ALLOWED_OPS)],
            'auto_rules.conditions.*.value' => ['required'],
        ]);

        $validator->after(function (ValidatorContract $validator) use ($request): void {
            $conditions = $request->input('auto_rules.conditions', []);
            if (! is_array($conditions)) {
                return;
            }

            foreach ($conditions as $index => $condition) {
                if (! is_array($condition)) {
                    continue;
                }

                $operation = $condition['op'] ?? null;
                $value = $condition['value'] ?? null;
                $key = "auto_rules.conditions.{$index}.value";

                if ($operation === 'in') {
                    $items = is_array($value) ? $value : [];
                    $validItems = array_filter($items, fn ($item) => is_string($item) && trim($item) !== '' && mb_strlen(trim($item)) <= 100);
                    $normalised = array_map(fn (string $item) => trim($item), $validItems);
                    if (count($items) < 1 || count($items) > 20 || count($normalised) !== count($items) || count(array_unique($normalised)) !== count($normalised)) {
                        $validator->errors()->add($key, 'Enter between 1 and 20 unique values, with no blank values.');
                    }

                    continue;
                }

                if (in_array($operation, ['equals', 'not_equals'], true)
                    && (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 100)) {
                    $validator->errors()->add($key, 'Enter a value between 1 and 100 characters.');
                }
            }
        });

        $validated = $validator->validate();
        if (! array_key_exists('auto_rules', $validated) || $validated['auto_rules'] === null) {
            return $required ? ['auto_rules' => null] : $validated;
        }

        foreach ($validated['auto_rules']['conditions'] as &$condition) {
            $condition['value'] = $condition['op'] === 'in'
                ? array_values(array_unique(array_map(fn (string $value) => trim($value), $condition['value'])))
                : trim($condition['value']);
        }
        unset($condition);

        return ['auto_rules' => $validated['auto_rules']];
    }
}

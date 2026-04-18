<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceGroupAutoRuleService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class DeviceGroupController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly DeviceGroupAutoRuleService $autoRules,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $query = DeviceGroup::query()->withCount('devices');

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
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $members = $group->devices()
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        // Available devices for the add-member dialog (not already in this group).
        $existingDeviceIds = $group->devices()->pluck('devices.id');
        $availableDevices = Device::query()
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
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        return Inertia::render('security-devices/device-groups/create');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:device_groups,name'],
            'type' => ['nullable', 'string', 'in:location,functional,vendor,maintenance,custom'],
            'description' => ['nullable', 'string', 'max:2000'],
            'auto_rules' => ['nullable', 'array'],
        ]);

        $validated['tenant_id'] = 1;
        $validated['type'] = $validated['type'] ?? 'custom';

        $group = DeviceGroup::create($validated);

        return redirect()->route('security-devices.device-groups.show', $group)
            ->with('success', "Group '{$group->name}' created.");
    }

    public function edit(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        return Inertia::render('security-devices/device-groups/edit', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'type' => $group->type,
                'description' => $group->description,
            ],
        ]);
    }

    public function update(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', "unique:device_groups,name,{$group->id}"],
            'type' => ['nullable', 'string', 'in:location,functional,vendor,maintenance,custom'],
            'description' => ['nullable', 'string', 'max:2000'],
            'auto_rules' => ['nullable', 'array'],
        ]);

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
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $result = $this->autoRules->preview($group);

        return response()->json([
            'count' => $result['count'],
            'sample' => $result['sample']->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'category' => $d->category,
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
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];
        if (empty($rules)) {
            return redirect()->back()->with('error', 'This group has no auto-rules configured.');
        }

        $delta = $this->autoRules->applyToGroup($group);

        return redirect()->back()->with(
            'success',
            "Auto-rules applied: added {$delta['added']}, removed {$delta['removed']}, kept {$delta['kept']} (total {$delta['total']} matches).",
        );
    }

    public function destroy(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $name = $group->name;
        $group->delete(); // soft delete

        return redirect()->route('security-devices.device-groups')
            ->with('success', "Group '{$name}' deleted.");
    }

    public function addMember(Request $request, DeviceGroup $group)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $validated = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
        ]);

        // Prevent duplicate membership.
        if ($group->devices()->where('devices.id', $validated['device_id'])->exists()) {
            return back()->withErrors(['device_id' => 'Device is already a member of this group.']);
        }

        $group->devices()->attach($validated['device_id']);

        return back()->with('success', 'Device added to group.');
    }

    public function removeMember(Request $request, DeviceGroup $group, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.groups.manage'), 403);

        $group->devices()->detach($device->id);

        return back()->with('success', 'Device removed from group.');
    }
}

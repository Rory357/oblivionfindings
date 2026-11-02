<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceGroupAutoRuleService;
use App\Domain\SecurityDevices\Services\DeviceGroupMaintenancePreview;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorContract;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Device groups' remaining endpoints. S&D rebuild C6 (D8): the group pages are gone and no screen
 * calls these any more; a group is now a filter on Devices. Kept until Stephan says yes to
 * dropping the tables.
 */
class DeviceGroupController extends Controller
{
    public function __construct(
        private readonly DeviceGroupAutoRuleService $autoRules,
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceGroupMaintenancePreview $maintenancePreview,
    ) {}

    public function previewMaintenancePolicy(Request $request, DeviceGroup $group): JsonResponse
    {
        try {
            abort_if($request->getContent() !== '' || $request->request->count() !== 0, 422, 'invalid_request');
            $data = $this->maintenancePreview->read(
                $request->user(), (int) $group->id, $request->query(), (string) $request->server('QUERY_STRING', ''),
            );
        } catch (ModelNotFoundException) {
            // A Site, monitor or member removed part-way through the read
            // (a firstOrFail() inside Monitoring's owner checks). Answer with
            // the same private 404 as every other unavailable read rather than
            // Laravel's default "No query results for model …" body.
            return self::previewUnavailable(404);
        } catch (HttpExceptionInterface $error) {
            $status = $error->getStatusCode();
            if (! in_array($status, [404, 422, 503], true)) {
                throw $error;
            }

            return self::previewUnavailable($status);
        }

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    /** The one uniform, uncached error body for the maintenance preview read. */
    public static function previewUnavailable(int $status = 404): JsonResponse
    {
        return response()->json(['message' => $status === 422 ? 'invalid_request' : 'unavailable'], $status)
            ->header('Cache-Control', 'private, no-store');
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

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->wizardSaveReply($request, $group);
        }

        return redirect()->route('security-devices.device-groups.show', $group)
            ->with('success', "Group '{$group->name}' created.");
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

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->wizardSaveReply($request, $group);
        }

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

    private function wizardSaveReply(Request $request, DeviceGroup $group): JsonResponse
    {
        $group->refresh();

        return response()->json([
            'saved' => true,
            'saved_by' => $request->user()->id,
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'type' => $group->type,
                'description' => $group->description,
                'auto_rules' => is_array($group->auto_rules) ? $group->auto_rules : null,
            ],
        ])->header('Cache-Control', 'private, no-store');
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

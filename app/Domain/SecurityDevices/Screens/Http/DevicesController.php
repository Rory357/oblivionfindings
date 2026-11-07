<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Devices\DevicesIndexPresenter;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The Devices menu (S&D rebuild C1): the page, Add one device (pop-up 2) and Move to another Site
 * (pop-up 72, one device or many). Edit, ping, watch and retire use today's device routes.
 */
final class DevicesController extends Controller
{
    /** Old links still land on the right view. */
    private const array LEGACY_DOMAIN_TABS = [
        'it_infrastructure' => 'network',
        'security' => 'alarms_doors',
        'iot_healthcare' => 'sensors',
        'tracking' => 'trackers',
        'facilities' => 'sensors',
    ];

    private const array LEGACY_VIEW_STATUS = [
        'unmonitored' => 'not_watched',
        'offline' => 'offline',
        'needs_attention' => 'problem',
    ];

    public function __construct(
        private readonly DevicesIndexPresenter $presenter,
        private readonly DeviceFormOptions $formOptions,
        private readonly DeviceRegistryService $registry,
        private readonly DeviceAssignmentService $assignments,
        private readonly DeviceFieldOwnershipService $fieldOwnership,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);

        $input = [
            'tab' => $request->query('tab') ?? self::LEGACY_DOMAIN_TABS[(string) $request->query('domain')] ?? null,
            'upkeep' => $request->query('upkeep'),
            'site' => $request->query('site') ?? $request->query('site_id'),
            'status' => $request->query('status') ?? self::LEGACY_VIEW_STATUS[(string) $request->query('view')] ?? null,
            // S&D rebuild C6: Health kit and today's device groups (D8) as a filter.
            'group' => $request->query('group') ?? ($request->query('domain') === 'iot_healthcare' ? 'health' : null),
            'q' => $request->query('q') ?? $request->query('search'),
            'page' => $request->query('page'),
            // S&D v13 (pg-sdr-2f): the removed device whose last logbook lines are open.
            'retired' => $request->query('retired'),
        ];

        $layout = $request->query('layout');
        if (! in_array($layout, ['cards', 'list'], true)) {
            $stored = $user->uiPreferences()->where('key', DevicesIndexPresenter::LAYOUT_PREFERENCE)->value('value');
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;
            $layout = is_array($stored) && in_array($stored[0] ?? null, ['cards', 'list'], true) ? $stored[0] : 'cards';
        }

        // Each prop is a closure over one read, so loading a pop-up's options skips the page.
        $page = null;
        $read = function () use (&$page, $user, $input, $layout): array {
            return $page ??= $this->presenter->present($user, $input, $layout);
        };
        $props = [];
        foreach (DevicesIndexPresenter::PROPS as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render('security-devices/devices/index', [
            ...$props,
            'openDialog' => $request->query('dialog') === 'add-device' && $user->canDo('securityDevices.devices.create') ? 'add' : null,
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    /** Pop-up 2: one device by its address. It is added, then the page opens it. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.create'), 403);

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(DeviceFormOptions::KINDS))],
            'name' => ['required', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'string', 'max:253', $this->addressRule()],
        ], [
            'name.required' => 'Give it the name staff call it.',
        ]);

        $kind = DeviceFormOptions::KINDS[$validated['kind']];
        $address = trim((string) ($validated['ip_address'] ?? ''));
        $device = $this->registry->registerDevice(array_filter([
            'name' => trim($validated['name']),
            'domain' => $kind['domain'],
            'category' => $kind['category'],
            'subcategory' => $kind['subcategory'],
            'manufacturer' => $this->clean($validated['manufacturer'] ?? null),
            'model' => $this->clean($validated['model'] ?? null),
            'ip_address' => $address === '' ? null : $address,
            'location_description' => $this->clean($validated['location'] ?? null),
        ], fn (mixed $value): bool => $value !== null), $user, isset($validated['site_id']) ? (int) $validated['site_id'] : null);

        return response()->json([
            'id' => (int) $device->id,
            'name' => (string) $device->name,
            'href' => '/security-devices/devices/'.$device->id,
            'message' => "Added {$device->name}.",
        ], 201);
    }

    /**
     * Pop-up 72: the new Site and room. Its history comes too. A device that is with a person or
     * a vehicle is moved from its assignments instead, so consent and custody rules stay intact.
     */
    public function move(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.assign'), 403);

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1', 'max:100'],
            'device_ids.*' => ['integer', 'min:1', 'distinct'],
            'site_id' => ['required', 'integer', 'min:1'],
            'room' => ['nullable', 'string', 'max:255'],
        ], [
            'site_id.required' => 'Choose the Site it’s moving to.',
        ]);

        $siteId = (int) $validated['site_id'];
        $this->access->assertCanViewSite($user, $siteId);
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->first();
        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => 'Choose an active Site you can see.']);
        }

        $room = $this->clean($validated['room'] ?? null);
        $moved = [];
        $refused = [];
        foreach (array_map('intval', $validated['device_ids']) as $deviceId) {
            $device = $this->access->visibleDevices($user)->whereKey($deviceId)->first();
            if ($device === null) {
                $refused[] = ['id' => $deviceId, 'name' => null, 'reason' => 'You can’t see this device.'];

                continue;
            }

            try {
                DB::transaction(function () use ($user, $device, $site, $room): void {
                    $current = $device->assignments()->active()->lockForUpdate()->latest('assigned_at')->first();
                    if ($current !== null && $current->assignable_type !== DeviceAssignment::TARGET_SITE) {
                        $with = match ($current->assignable_type) {
                            DeviceAssignment::TARGET_CLIENT => 'a person',
                            DeviceAssignment::TARGET_STAFF => 'a staff member',
                            DeviceAssignment::TARGET_VEHICLE => 'a vehicle',
                            default => 'a room',
                        };
                        throw ValidationException::withMessages([
                            'device' => "It’s with {$with}. Move it from its assignments so consent and custody rules stay intact.",
                        ]);
                    }
                    if ($current === null || (int) $current->assignable_id !== (int) $site->id) {
                        $this->assignments->assign(
                            device: $device,
                            assignableType: DeviceAssignment::TARGET_SITE,
                            assignableId: (int) $site->id,
                            assignedByUserId: (int) $user->id,
                            assignmentType: AssignmentType::Permanent,
                            authorizeLockedDevice: function (Device $locked) use ($user): void {
                                $this->access->assertCanViewDevice($user, $locked);
                                $this->access->assertCanManageActiveAssignment($user, $locked, true);
                            },
                        );
                    }
                    if ($room !== null) {
                        $this->fieldOwnership->updateFromLocal($device->fresh(), ['location_description' => $room], $user, null, null);
                    }
                }, 3);
                $moved[] = (int) $device->id;
            } catch (ValidationException $exception) {
                $refused[] = ['id' => (int) $device->id, 'name' => (string) $device->name, 'reason' => collect($exception->errors())->flatten()->first()];
            } catch (HttpExceptionInterface|\InvalidArgumentException $exception) {
                $refused[] = ['id' => (int) $device->id, 'name' => (string) $device->name, 'reason' => 'You can’t move this device.'];
            } catch (Throwable $exception) {
                report($exception);
                $refused[] = ['id' => (int) $device->id, 'name' => (string) $device->name, 'reason' => 'It couldn’t be moved. Try again.'];
            }
        }

        $count = count($moved);

        return response()->json([
            'moved' => $moved,
            'refused' => $refused,
            'message' => $count === 0
                ? 'Nothing was moved.'
                : 'Moved '.$count.' '.($count === 1 ? 'device' : 'devices').' to '.$site->name.'.',
        ]);
    }

    private function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** An IP address or a hostname, as today's device form accepts. */
    private function addressRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_IP) !== false) {
                return;
            }
            if (preg_match('/[a-z]/i', $trimmed) === 1
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $trimmed) === 1) {
                return;
            }
            $fail('Enter an IP address (like 10.20.0.31) or a hostname.');
        };
    }
}

<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use App\Models\SiteFacilityZone;
use App\Models\SiteHoResource;
use App\Models\SiteHouseRoom;
use App\Models\SiteTypePlan;
use App\Models\SiteTypePlanPin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SiteTypePlanService
{
    public function currentDraft(Site $site): ?SiteTypePlan
    {
        return SiteTypePlan::currentDraft($site);
    }

    public function currentPublished(Site $site): ?SiteTypePlan
    {
        return SiteTypePlan::currentPublished($site);
    }

    public function currentEditable(Site $site): ?SiteTypePlan
    {
        return $this->currentDraft($site) ?? $this->currentPublished($site);
    }

    public function storeDraft(Site $site, array $layout, ?string $notes, ?int $userId): SiteTypePlan
    {
        return DB::transaction(function () use ($site, $layout, $notes, $userId) {
            $draft = $this->currentDraft($site);

            if (! $draft) {
                $draft = new SiteTypePlan([
                    'tenant_id' => $site->tenant_id,
                    'site_id' => $site->id,
                    'site_type' => $site->type,
                    'status' => SiteTypePlan::STATUS_DRAFT,
                    'version' => max(1, $this->nextVersion($site)),
                    'created_by_user_id' => $userId,
                ]);
            }

            $draft->fill([
                'site_type' => $site->type,
                'layout' => $this->normaliseLayout($layout, $site->type),
                'notes' => $notes,
            ]);
            $draft->save();

            return $draft->fresh(['pins']);
        });
    }

    public function cloneToDraft(Site $site, ?int $userId = null): SiteTypePlan
    {
        return DB::transaction(function () use ($site, $userId) {
            if ($draft = $this->currentDraft($site)) {
                return $draft->load('pins');
            }

            $published = $this->currentPublished($site);
            abort_unless($published, 404, 'No published plan exists to edit.');

            $draft = SiteTypePlan::create([
                'tenant_id' => $site->tenant_id,
                'site_id' => $site->id,
                'site_type' => $site->type,
                'status' => SiteTypePlan::STATUS_DRAFT,
                'version' => $this->nextVersion($site),
                'layout' => $published->layout,
                'notes' => $published->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($published->pins as $pin) {
                $draft->pins()->create($pin->only([
                    'tenant_id',
                    'kind',
                    'subkind',
                    'device_id',
                    'room_ref_type',
                    'room_ref_id',
                    'label',
                    'notes',
                    'meta',
                    'x',
                    'y',
                    'rotation_deg',
                    'width',
                    'height',
                    'path_points',
                    'sort_order',
                ]));
            }

            return $draft->fresh(['pins']);
        });
    }

    public function discardDraft(Site $site): void
    {
        DB::transaction(function () use ($site) {
            $draft = $this->currentDraft($site);
            if ($draft) {
                $draft->delete();
            }
        });
    }

    public function publishDraft(Site $site, ?int $userId): SiteTypePlan
    {
        return DB::transaction(function () use ($site, $userId) {
            $draft = $this->currentDraft($site);
            abort_unless($draft, 404, 'No draft plan exists to publish.');

            SiteTypePlan::query()
                ->forSite($site)
                ->published()
                ->whereKeyNot($draft->id)
                ->update([
                    'status' => SiteTypePlan::STATUS_ARCHIVED,
                    'archived_at' => now(),
                    'updated_at' => now(),
                ]);

            $draft->forceFill([
                'status' => SiteTypePlan::STATUS_PUBLISHED,
                'version' => $this->nextVersion($site),
                'published_at' => now(),
                'published_by_user_id' => $userId,
                'archived_at' => null,
            ])->save();

            return $draft->fresh(['pins']);
        });
    }

    public function replacePins(SiteTypePlan $plan, array $pins, bool $replace = false): Collection
    {
        return DB::transaction(function () use ($plan, $pins, $replace) {
            if ($replace) {
                $plan->pins()->delete();
            }

            foreach (array_values($pins) as $index => $pinData) {
                $payload = $this->pinPayload($plan, $pinData, $index);
                $pinId = $replace ? null : ($pinData['id'] ?? null);

                if ($pinId) {
                    $plan->pins()->whereKey($pinId)->firstOrFail()->update($payload);
                    continue;
                }

                $plan->pins()->create($payload);
            }

            return $plan->fresh(['pins'])->pins;
        });
    }

    public function draftForEmergencyPins(Site $site, ?int $userId = null): SiteTypePlan
    {
        if ($draft = $this->currentDraft($site)) {
            return $draft->load('pins');
        }

        if ($this->currentPublished($site)) {
            return $this->cloneToDraft($site, $userId);
        }

        return $this->storeDraft($site, $this->seedDefaultLayout($site->type), null, $userId);
    }

    public function replaceEmergencyPins(SiteTypePlan $plan, array $pins): Collection
    {
        return DB::transaction(function () use ($plan, $pins) {
            $plan->pins()
                ->whereIn('kind', SiteTypePlanPin::EMERGENCY_KINDS)
                ->delete();

            foreach (array_values($pins) as $index => $pinData) {
                $plan->pins()->create($this->pinPayload($plan, $pinData, $index));
            }

            return $plan->fresh(['pins'])->pins;
        });
    }

    public function summaryFor(Site $site): array
    {
        $draft = $this->currentDraft($site)?->load('pins');
        $published = $this->currentPublished($site)?->load('pins');
        $summaryPlan = $published ?? $draft;

        return [
            'tab_label' => $this->tabLabel($site),
            'inventory_label' => $this->inventoryLabel($site),
            'inventory_href' => $this->inventoryHref($site),
            'status' => $draft && $published ? 'draft_over_published' : ($draft ? 'draft' : ($published ? 'published' : 'empty')),
            'draft' => $this->serializePlan($draft, true),
            'published' => $this->serializePlan($published, true),
            'has_plan' => (bool) $summaryPlan,
            'has_published' => (bool) $published,
            'has_emergency_layer' => $published ? $this->hasEmergencyLayer($published) : false,
            'has_medication_pin' => $published ? $this->hasMedicationPin($published) : false,
            'pin_counts' => $summaryPlan ? $summaryPlan->pins->countBy('kind')->all() : [],
            'inventory' => $this->siteInventory($site),
            'taxonomy' => $this->taxonomy(),
            'emergency_pin_kinds' => SiteTypePlanPin::EMERGENCY_KINDS,
        ];
    }

    /**
     * Surface the rooms and devices that the builder is allowed to reference.
     */
    public function siteInventory(Site $site): array
    {
        return [
            'rooms' => $this->siteRooms($site),
            'devices' => $this->siteDevices($site),
        ];
    }

    public function siteRooms(Site $site): array
    {
        $rooms = [];

        SiteHouseRoom::query()
            ->where('site_id', $site->id)
            ->when($site->tenant_id, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->each(function (SiteHouseRoom $room) use (&$rooms) {
                $rooms[] = [
                    'id' => $room->id,
                    'name' => $room->name,
                    'type' => 'house_room',
                    'type_label' => 'Bedroom',
                    'is_active' => (bool) $room->is_active,
                    'is_assigned' => $room->assigned_client_id !== null,
                ];
            });

        SiteHoResource::query()
            ->where('site_id', $site->id)
            ->when($site->tenant_id, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get()
            ->each(function (SiteHoResource $resource) use (&$rooms) {
                $rooms[] = [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'type' => 'ho_resource',
                    'type_label' => $this->humanise($resource->resource_type ?? 'Space'),
                    'is_active' => (bool) $resource->is_active,
                    'is_assigned' => false,
                ];
            });

        SiteFacilityZone::query()
            ->where('site_id', $site->id)
            ->when($site->tenant_id, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->orderBy('name')
            ->get()
            ->each(function (SiteFacilityZone $zone) use (&$rooms) {
                $rooms[] = [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'type' => 'facility_zone',
                    'type_label' => $this->humanise($zone->zone_type ?? 'Zone'),
                    'is_active' => (bool) $zone->is_active,
                    'is_assigned' => false,
                ];
            });

        return $rooms;
    }

    public function siteDevices(Site $site): array
    {
        return DeviceAssignment::query()
            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
            ->where('assignable_id', $site->id)
            ->whereNull('released_at')
            ->with('device')
            ->get()
            ->map(function (DeviceAssignment $assignment) {
                $device = $assignment->device;
                if (! $device) {
                    return null;
                }

                return [
                    'id' => $device->id,
                    'name' => $device->name ?? $device->device_uid,
                    'uid' => $device->device_uid,
                    'category' => $device->category,
                    'subcategory' => $device->subcategory,
                    'manufacturer' => $device->manufacturer,
                    'model' => $device->model,
                    'status' => $device->status?->value ?? null,
                    'health' => $device->health_status?->value ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Plan-builder taxonomy shared with the frontend.
     */
    public function taxonomy(): array
    {
        return config('site_plan_taxonomy', []);
    }

    private function humanise(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    public function serializePlan(?SiteTypePlan $plan, bool $includePins = true): ?array
    {
        if (! $plan) {
            return null;
        }

        $plan->loadMissing('pins');

        return [
            'id' => $plan->id,
            'site_id' => $plan->site_id,
            'site_type' => $plan->site_type,
            'status' => $plan->status,
            'version' => $plan->version,
            'layout' => $plan->layout,
            'notes' => $plan->notes,
            'published_at' => $plan->published_at?->toIso8601String(),
            'pins' => $includePins ? $plan->pins->map(fn (SiteTypePlanPin $pin) => $this->serializePin($pin))->values()->all() : [],
        ];
    }

    public function serializePin(SiteTypePlanPin $pin): array
    {
        return [
            'id' => $pin->id,
            'kind' => $pin->kind,
            'subkind' => $pin->subkind,
            'device_id' => $pin->device_id,
            'room_ref_type' => $pin->room_ref_type,
            'room_ref_id' => $pin->room_ref_id,
            'label' => $pin->label,
            'notes' => $pin->notes,
            'meta' => $pin->meta ?? [],
            'x' => (float) $pin->x,
            'y' => (float) $pin->y,
            'rotation_deg' => (int) $pin->rotation_deg,
            'width' => $pin->width !== null ? (float) $pin->width : null,
            'height' => $pin->height !== null ? (float) $pin->height : null,
            'path_points' => $pin->path_points ?? [],
            'sort_order' => (int) $pin->sort_order,
        ];
    }

    public function hasEmergencyLayer(SiteTypePlan $plan): bool
    {
        $plan->loadMissing('pins');

        return $plan->pins->contains('kind', SiteTypePlanPin::KIND_ASSEMBLY_POINT)
            && $plan->pins->contains('kind', SiteTypePlanPin::KIND_EMERGENCY_EXIT);
    }

    public function hasMedicationPin(SiteTypePlan $plan): bool
    {
        $plan->loadMissing('pins');

        return $plan->pins->contains('kind', SiteTypePlanPin::KIND_MEDICATION_STORAGE);
    }

    public function seedDefaultLayout(string $type): array
    {
        $label = in_array($type, ['house', 'residential'], true) ? 'Bedroom 1' : 'Zone 1';

        return $this->normaliseLayout([
            'schema_version' => 1,
            'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
            'grid' => ['enabled' => true, 'size' => 10, 'snap' => true],
            'rooms' => [
                ['id' => 'room-1', 'label' => $label, 'shape' => 'rect', 'x' => 0.08, 'y' => 0.12, 'width' => 0.28, 'height' => 0.24],
                ['id' => 'room-2', 'label' => 'Kitchen', 'shape' => 'rect', 'x' => 0.38, 'y' => 0.12, 'width' => 0.24, 'height' => 0.24],
                ['id' => 'room-3', 'label' => 'Lounge', 'shape' => 'rect', 'x' => 0.08, 'y' => 0.40, 'width' => 0.54, 'height' => 0.28],
            ],
            'walls' => [],
            'doors' => [],
            'windows' => [],
            'labels' => [],
        ], $type);
    }

    public function renderLayoutSvg(SiteTypePlan $plan, ?iterable $pins = null): string
    {
        $layout = $this->normaliseLayout($plan->layout ?? [], $plan->site_type);
        $canvas = $layout['canvas'];
        $width = (int) ($canvas['width'] ?? 1000);
        $height = (int) ($canvas['height'] ?? 700);
        $pinList = $pins ?? $plan->pins;

        $svg = [];
        $svg[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" role="img">', $width, $height);
        $svg[] = '<rect x="0" y="0" width="100%" height="100%" fill="#ffffff"/>';

        foreach (($layout['rooms'] ?? []) as $room) {
            $x = (float) ($room['x'] ?? 0) * $width;
            $y = (float) ($room['y'] ?? 0) * $height;
            $w = (float) ($room['width'] ?? 0.12) * $width;
            $h = (float) ($room['height'] ?? 0.12) * $height;
            $label = e((string) ($room['label'] ?? 'Room'));

            $svg[] = sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="#f8fafc" stroke="#334155" stroke-width="3"/>', $x, $y, $w, $h);
            $svg[] = sprintf('<text x="%.2f" y="%.2f" font-family="Arial, sans-serif" font-size="18" fill="#0f172a">%s</text>', $x + 12, $y + 28, $label);
        }

        foreach (($layout['walls'] ?? []) as $wall) {
            $points = collect($wall['points'] ?? [])
                ->map(fn ($point) => sprintf('%.2f,%.2f', (float) ($point['x'] ?? 0) * $width, (float) ($point['y'] ?? 0) * $height))
                ->implode(' ');
            if ($points !== '') {
                $thickness = (int) ($wall['thickness'] ?? 4);
                $svg[] = sprintf('<polyline points="%s" fill="none" stroke="#111827" stroke-width="%d"/>', $points, $thickness);
            }
        }

        foreach (($layout['doors'] ?? []) as $door) {
            $svg[] = $this->renderDoorSvg($this->normaliseDoor($door), $width, $height);
        }

        foreach ($pinList as $pin) {
            $pin = $pin instanceof SiteTypePlanPin ? $this->serializePin($pin) : $pin;
            $x = (float) ($pin['x'] ?? 0.5) * $width;
            $y = (float) ($pin['y'] ?? 0.5) * $height;
            $label = e($this->pinLabel($pin));
            $color = $this->pinColor((string) ($pin['kind'] ?? 'custom_marker'));

            $svg[] = sprintf('<circle cx="%.2f" cy="%.2f" r="13" fill="%s" stroke="#ffffff" stroke-width="4"/>', $x, $y, $color);
            $svg[] = sprintf('<text x="%.2f" y="%.2f" font-family="Arial, sans-serif" font-size="14" fill="#111827">%s</text>', $x + 18, $y + 5, $label);
        }

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    public function tabLabel(Site $site): string
    {
        return match ($site->type) {
            'head_office' => 'Office Plan',
            'facility' => 'Facility Plan',
            default => 'House Plan',
        };
    }

    public function inventoryLabel(Site $site): string
    {
        return match ($site->type) {
            'head_office' => 'Manage resources',
            'facility' => 'Manage zones',
            default => 'Manage rooms',
        };
    }

    public function inventoryHref(Site $site): string
    {
        return match ($site->type) {
            'head_office' => "/sites/{$site->id}/resources",
            'facility' => "/sites/{$site->id}/zones",
            default => "/sites/{$site->id}/rooms",
        };
    }

    private function nextVersion(Site $site): int
    {
        $max = (int) SiteTypePlan::query()
            ->forSite($site)
            ->whereIn('status', [SiteTypePlan::STATUS_PUBLISHED, SiteTypePlan::STATUS_ARCHIVED])
            ->max('version');

        return $max + 1;
    }

    private function normaliseLayout(array $layout, string $type): array
    {
        return array_replace_recursive([
            'schema_version' => 1,
            'canvas' => [
                'width' => 1000,
                'height' => 700,
                'unit' => 'rel',
                // 25 mm per virtual canvas unit → the 1000×700 canvas covers
                // a 25 m × 17.5 m plan by default. Calibrated via the scale tool.
                'meters_per_unit' => 0.025,
            ],
            'grid' => ['enabled' => true, 'size' => 10, 'snap' => true],
            'scale' => null,
            'walls' => [],
            'rooms' => [],
            'doors' => [],
            'windows' => [],
            'labels' => [],
            'site_type' => $type,
        ], $layout);
    }

    private function pinPayload(SiteTypePlan $plan, array $pinData, int $index): array
    {
        return [
            'tenant_id' => $plan->tenant_id,
            'kind' => $pinData['kind'],
            'subkind' => $pinData['subkind'] ?? null,
            'device_id' => $pinData['device_id'] ?? null,
            'room_ref_type' => $pinData['room_ref_type'] ?? null,
            'room_ref_id' => $pinData['room_ref_id'] ?? null,
            'label' => $pinData['label'] ?? null,
            'notes' => $pinData['notes'] ?? null,
            'meta' => $pinData['meta'] ?? null,
            'x' => $pinData['x'],
            'y' => $pinData['y'],
            'rotation_deg' => $pinData['rotation_deg'] ?? 0,
            'width' => $pinData['width'] ?? null,
            'height' => $pinData['height'] ?? null,
            'path_points' => $pinData['path_points'] ?? null,
            'sort_order' => $pinData['sort_order'] ?? $index,
        ];
    }

    private function pinLabel(array $pin): string
    {
        return $pin['label'] ?: str_replace('_', ' ', (string) ($pin['kind'] ?? 'Marker'));
    }

    private function pinColor(string $kind): string
    {
        return match ($kind) {
            SiteTypePlanPin::KIND_DEVICE => '#2563eb',
            SiteTypePlanPin::KIND_MEDICATION_STORAGE => '#7c3aed',
            SiteTypePlanPin::KIND_ASSEMBLY_POINT => '#16a34a',
            SiteTypePlanPin::KIND_EMERGENCY_EXIT => '#dc2626',
            SiteTypePlanPin::KIND_FIRE_EXTINGUISHER, SiteTypePlanPin::KIND_FIRE_BLANKET => '#ea580c',
            SiteTypePlanPin::KIND_FIRST_AID_KIT, SiteTypePlanPin::KIND_DEFIBRILLATOR => '#0891b2',
            default => '#475569',
        };
    }

    /**
     * Apply default values to a door so legacy entries (no subkind, swing string only)
     * still render as a meaningful symbol. Mirrors `normaliseDoor()` in TypeScript.
     */
    private function normaliseDoor(array $door): array
    {
        $swingSide = $door['swing_side'] ?? (($door['swing'] ?? '') === 'left' ? 'left' : 'right');

        return array_merge([
            'subkind' => 'single_swing',
            'swing_side' => $swingSide,
            'swing_direction' => 'in',
            'width' => 0.06,
            'rotation_deg' => 0,
        ], $door, ['swing_side' => $swingSide]);
    }

    /**
     * Emit an architectural door symbol matching the React `DoorSymbol` component.
     */
    private function renderDoorSvg(array $door, int $canvasWidth, int $canvasHeight): string
    {
        $x = (float) ($door['x'] ?? 0) * $canvasWidth;
        $y = (float) ($door['y'] ?? 0) * $canvasHeight;
        $w = (float) ($door['width'] ?? 0.06) * $canvasWidth;
        $rotation = (int) ($door['rotation_deg'] ?? 0);
        $cx = $x + $w / 2;
        $cy = $y;

        $paths = match ($door['subkind']) {
            'double_swing' => $this->doorDoubleSwingPaths($door, $x, $y, $w),
            'sliding' => $this->doorSlidingPaths($x, $y, $w),
            'pocket' => $this->doorPocketPaths($door, $x, $y, $w),
            'bifold' => $this->doorBifoldPaths($x, $y, $w),
            'folding' => $this->doorFoldingPaths($x, $y, $w),
            'garage' => $this->doorGaragePaths($x, $y, $w),
            'revolving' => $this->doorRevolvingPaths($x, $y, $w),
            default => $this->doorSingleSwingPaths($door, $x, $y, $w),
        };

        $open = $rotation !== 0
            ? sprintf('<g transform="rotate(%d %.2f %.2f)">', $rotation, $cx, $cy)
            : '<g>';

        return $open.implode('', $paths).'</g>';
    }

    private function doorWallStops(float $x, float $y, float $w): array
    {
        return [
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2"/>', $x, $y - 3, $x, $y + 3),
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2"/>', $x + $w, $y - 3, $x + $w, $y + 3),
        ];
    }

    private function doorSingleSwingPaths(array $door, float $x, float $y, float $w): array
    {
        $side = $door['swing_side'] ?? 'right';
        $dir = $door['swing_direction'] ?? 'in';
        $hingeX = $side === 'right' ? $x + $w : $x;
        $endY = $dir === 'in' ? $y + $w : $y - $w;
        $arcTargetX = $side === 'right' ? $x : $x + $w;

        // Sweep flag mapping (matches the JS SWING_PATHS table):
        // right-in => 1, right-out => 0, left-in => 0, left-out => 1
        $sweep = match ("$side-$dir") {
            'right-in', 'left-out' => 1,
            default => 0,
        };

        $paths = $this->doorWallStops($x, $y, $w);
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2" stroke-linecap="round" fill="none"/>',
            $hingeX, $y, $hingeX, $endY,
        );
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f A %.2f,%.2f 0 0 %d %.2f,%.2f" stroke="#1f2937" stroke-width="1.5" fill="none"/>',
            $hingeX, $endY, $w, $w, $sweep, $arcTargetX, $y,
        );
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $hingeX, $y);

        return $paths;
    }

    private function doorDoubleSwingPaths(array $door, float $x, float $y, float $w): array
    {
        $out = ($door['swing_direction'] ?? 'in') === 'out';
        $leafEndY = $out ? $y - $w / 2 : $y + $w / 2;
        $leftSweep = $out ? 0 : 1;
        $rightSweep = $out ? 1 : 0;

        $paths = $this->doorWallStops($x, $y, $w);
        // Left leaf
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2" stroke-linecap="round" fill="none"/>',
            $x, $y, $x, $leafEndY,
        );
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f A %.2f,%.2f 0 0 %d %.2f,%.2f" stroke="#1f2937" stroke-width="1.5" fill="none"/>',
            $x, $leafEndY, $w / 2, $w / 2, $leftSweep, $x + $w / 2, $y,
        );
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x, $y);
        // Right leaf
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2" stroke-linecap="round" fill="none"/>',
            $x + $w, $y, $x + $w, $leafEndY,
        );
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f A %.2f,%.2f 0 0 %d %.2f,%.2f" stroke="#1f2937" stroke-width="1.5" fill="none"/>',
            $x + $w, $leafEndY, $w / 2, $w / 2, $rightSweep, $x + $w / 2, $y,
        );
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x + $w, $y);

        return $paths;
    }

    private function doorSlidingPaths(float $x, float $y, float $w): array
    {
        return [
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="1"/>', $x, $y - 2, $x + $w, $y - 2),
            sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="3" fill="#1f2937"/>', $x, $y - 1, $w * 0.55),
            sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="3" fill="#1f2937"/>', $x + $w * 0.45, $y + 3, $w * 0.55),
            sprintf(
                '<path d="M %.2f,%.2f L %.2f,%.2f M %.2f,%.2f L %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="1.2" fill="none"/>',
                $x + $w - 6, $y + 4.5, $x + $w - 2, $y + 4.5,
                $x + $w - 4, $y + 3, $x + $w - 2, $y + 4.5, $x + $w - 4, $y + 6,
            ),
        ];
    }

    private function doorPocketPaths(array $door, float $x, float $y, float $w): array
    {
        $pocketLeft = ($door['swing_side'] ?? 'right') === 'left';
        $pocketX = $pocketLeft ? $x - $w * 0.9 : $x + $w;
        $stubX = $pocketLeft ? $x + $w : $x;
        $leafX = $pocketLeft ? $x - $w * 0.7 : $x + $w * 0.1;

        return [
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2"/>', $stubX, $y - 3, $stubX, $y + 3),
            sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="8" fill="none" stroke="#1f2937" stroke-width="1" stroke-dasharray="3 3"/>',
                $pocketX, $y - 4, $w * 0.9,
            ),
            sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="3" fill="#1f2937"/>', $leafX, $y - 1, $w * 0.6),
        ];
    }

    private function doorBifoldPaths(float $x, float $y, float $w): array
    {
        $paths = $this->doorWallStops($x, $y, $w);
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f L %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2" fill="none" stroke-linecap="round"/>',
            $x, $y, $x + $w / 2, $y + $w / 2, $x + $w, $y,
        );
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x, $y);
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x + $w, $y);

        return $paths;
    }

    private function doorFoldingPaths(float $x, float $y, float $w): array
    {
        $paths = $this->doorWallStops($x, $y, $w);
        $paths[] = sprintf(
            '<path d="M %.2f,%.2f L %.2f,%.2f L %.2f,%.2f L %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="2" fill="none" stroke-linecap="round"/>',
            $x, $y,
            $x + $w * 0.25, $y + $w * 0.25,
            $x + $w * 0.5, $y,
            $x + $w * 0.75, $y + $w * 0.25,
            $x + $w, $y,
        );
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x, $y);
        $paths[] = sprintf('<circle cx="%.2f" cy="%.2f" r="2" fill="#1f2937"/>', $x + $w, $y);

        return $paths;
    }

    private function doorGaragePaths(float $x, float $y, float $w): array
    {
        $paths = [
            sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="6" fill="#1f2937" stroke="#1f2937" stroke-width="1"/>', $x, $y - 1, $w),
        ];
        for ($i = 1; $i < 6; $i++) {
            $lx = $x + ($w * $i) / 6;
            $paths[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="#ffffff" stroke-width="1"/>', $lx, $y - 1, $lx, $y + 5);
        }

        return $paths;
    }

    private function doorRevolvingPaths(float $x, float $y, float $w): array
    {
        $cx = $x + $w / 2;
        $cy = $y;
        $r = $w / 2;

        return [
            sprintf('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="none" stroke="#1f2937" stroke-width="1.5"/>', $cx, $cy, $r),
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="1.5"/>', $cx - $r, $cy, $cx + $r, $cy),
            sprintf('<path d="M %.2f,%.2f L %.2f,%.2f" stroke="#1f2937" stroke-width="1.5"/>', $cx, $cy - $r, $cx, $cy + $r),
        ];
    }
}

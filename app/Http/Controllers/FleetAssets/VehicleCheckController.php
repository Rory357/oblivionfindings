<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetChecklistTemplateVersion;
use App\Models\User;
use App\Services\Fleet\VehicleCheckLibraryService;
use App\Services\Fleet\VehicleCheckService;
use App\Services\Fleet\VehicleChecksPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The vehicle profile's Checks & inspections: a JSON read model, recording a
 * check, amendments, the vehicle's check requirement, publishing checklist
 * versions and reporting a problem. Every call resolves the vehicle in the
 * actor's scope first, so a foreign or missing vehicle answers 404; the
 * services recheck their own authority under their locks.
 */
class VehicleCheckController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleChecksPresenter $presenter,
        private readonly VehicleCheckService $checks,
        private readonly VehicleCheckLibraryService $library,
    ) {}

    public function index(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->vehicle($viewer, $asset);

        return response()->json($this->presenter->present($viewer, $vehicle));
    }

    public function store(Request $request, Asset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        $files = array_values(array_filter((array) $request->file('files', []), fn (mixed $file): bool => $file instanceof UploadedFile));
        $result = $this->checks->submit($actor, (int) $vehicle->id, $request->only([
            'template_id', 'template_version_id', 'items_sha256', 'observed_local', 'observed_offset',
            'answers', 'notes', 'rule_version_id',
        ]), $files, $this->key($request));
        $run = $result['run'];
        $outcome = $run->outcome ?? 'needs_assessment';

        return response()->json([
            'run' => [
                'id' => $run->id,
                'reference' => 'CHK-'.$run->id,
                'outcome' => $outcome,
                'template_version_id' => $run->template_version_id !== null ? (int) $run->template_version_id : null,
            ],
            'files' => array_map(fn (AssetDocument $file): array => [
                'id' => $file->id, 'name' => $file->original_name, 'state' => $file->state,
            ], $result['files']),
            'message' => match ($outcome) {
                'passed' => 'Check recorded as passed.',
                'failed' => 'Check recorded as failed. Report the issue to Maintenance.',
                default => 'Check recorded for assessment.',
            },
        ]);
    }

    public function amend(Request $request, Asset $asset, FleetChecklistRun $run): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        abort_unless((int) $run->asset_id === (int) $vehicle->id, 404);
        $amendment = $this->checks->amend($actor, (int) $vehicle->id, (int) $run->id,
            (string) $request->input('note', ''), $this->key($request));

        return response()->json([
            'amendment' => [
                'id' => $amendment->id,
                'note' => $amendment->note,
                'recorded_at' => $amendment->recorded_at?->copy()->utc()->toIso8601String(),
            ],
            'message' => 'Amendment recorded. The original check is unchanged.',
        ]);
    }

    public function requirement(Request $request, Asset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        $requirement = $this->checks->updateRequirement($actor, (int) $vehicle->id,
            $request->only(['template_id', 'due_on', 'owner_user_id', 'expected_version']));

        return response()->json([
            'requirement' => [
                'template_id' => $requirement->template_id !== null ? (int) $requirement->template_id : null,
                'owner_user_id' => $requirement->owner_user_id !== null ? (int) $requirement->owner_user_id : null,
                'lock_version' => (int) $requirement->lock_version,
            ],
            'message' => 'Check requirement saved.',
        ]);
    }

    public function storeTemplate(Request $request, Asset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        $version = $this->library->publish($actor, (int) $vehicle->id, null, $this->definition($request), $this->key($request));

        return response()->json($this->versionResult($version, 'Checklist published as version '.$version->version.'.'));
    }

    public function publishVersion(Request $request, Asset $asset, FleetChecklistTemplate $template): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        $version = $this->library->publish($actor, (int) $vehicle->id, (int) $template->id, $this->definition($request), $this->key($request));

        return response()->json($this->versionResult($version, 'Version '.$version->version.' published for new checks.'));
    }

    public function report(Request $request, Asset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $vehicle = $this->vehicle($actor, $asset);
        $result = $this->checks->report($actor, (int) $vehicle->id, $request->only([
            'title', 'description', 'source_run_id', 'existing_work_order_id', 'estimated_start_date', 'estimated_end_date',
        ]), $this->key($request));
        $order = $result['work_order'];

        return response()->json([
            'work_order' => ['id' => $order->id, 'reference' => $order->reference_number, 'title' => $order->title],
            'linked' => $result['linked'],
            'message' => $result['linked']
                ? 'Report linked to '.($order->reference_number ?? 'the existing work').'.'
                : 'Report saved as '.($order->reference_number ?? 'new work').'. The site Coordinator will assess it.',
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function vehicle(User $actor, Asset $asset): Asset
    {
        return $this->vehicles->assignableVehicle($actor, (int) $asset->getKey()) ?? abort(404);
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 100);
    }

    /** @return array<string,mixed> */
    private function definition(Request $request): array
    {
        return $request->only([
            'name', 'use', 'assignment', 'evidence_required', 'questions', 'confirmed',
            'expected_version_id', 'expected_items_sha256',
        ]);
    }

    /** @return array<string,mixed> */
    private function versionResult(FleetChecklistTemplateVersion $version, string $message): array
    {
        return [
            'template' => [
                'id' => (int) $version->template_id,
                'version_id' => (int) $version->id,
                'version' => (int) $version->version,
                'name' => $version->name,
            ],
            'message' => $message,
        ];
    }
}

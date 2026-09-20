<?php

namespace App\Services\Fleet;

use App\Models\FleetWorkOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaintenanceAttachmentService
{
    public function __construct(private readonly MaintenanceAccessService $access) {}

    public function upload(User $actor, int $workOrderId, string $parentType, int $parentId,
        string $key, UploadedFile $file, ?string $category = null, ?string $description = null): object
    {
        abort_unless(in_array($parentType, ['work', 'report', 'check', 'action'], true), 422);
        $mime = (string) $file->getMimeType();
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true), 422);
        $bytes = (int) $file->getSize();
        abort_unless($bytes > 0 && $bytes <= 10 * 1024 * 1024, 422);
        $sha256 = hash_file('sha256', $file->getRealPath());
        abort_unless(is_string($sha256), 422);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '_',
            str_replace(['/', '\\'], '_', $file->getClientOriginalName())) ?: 'evidence';
        $fingerprint = MaintenanceFingerprint::of([
            'actor_id' => (int) $actor->id, 'operation' => 'maintenance.attachment.upload',
            'work_order_id' => $workOrderId, 'parent_type' => $parentType, 'parent_id' => $parentId,
            'sha256' => $sha256, 'byte_size' => $bytes, 'mime_type' => $mime, 'original_name' => $name,
            'category' => $category, 'description' => $description,
        ]);
        $writtenPath = null;
        try {
            return DB::transaction(function () use ($actor, $workOrderId, $parentType, $parentId,
                $key, $file, $mime, $bytes, $sha256, $name, $category, $description, $fingerprint, &$writtenPath): object {
                $preview = FleetWorkOrder::query()->whereKey($workOrderId)->firstOrFail(['id', 'asset_id']);
                $asset = $this->access->asset($actor, (int) $preview->asset_id, true);
                $order = FleetWorkOrder::query()->whereKey($workOrderId)
                    ->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
                $this->assertParent($actor, $order, $parentType, $parentId, true);

                $prior = DB::table('fleet_maintenance_attachments')
                    ->where('uploaded_by_user_id', $actor->id)->where('request_key', $key)->first();
                if ($prior) {
                    abort_unless((int) $prior->work_order_id === $workOrderId
                        && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409);
                    return $prior;
                }

                $directory = "maintenance/{$asset->id}/{$workOrderId}";
                $writtenPath = Storage::disk('private')->putFileAs($directory, $file, Str::uuid()->toString());
                if (! is_string($writtenPath) || $writtenPath === '') {
                    throw ValidationException::withMessages(['file' => 'The evidence file could not be saved. Keep it and retry.']);
                }
                $id = DB::table('fleet_maintenance_attachments')->insertGetId([
                    'work_order_id' => $order->id,
                    'report_id' => $parentType === 'report' ? $parentId : null,
                    'check_run_id' => $parentType === 'check' ? $parentId : null,
                    'action_id' => $parentType === 'action' ? $parentId : null,
                    'uploaded_by_user_id' => $actor->id,
                    'disk' => 'private', 'path' => $writtenPath,
                    'original_name' => $name, 'mime_type' => $mime,
                    'category' => $category, 'description' => $description,
                    'byte_size' => $bytes, 'sha256' => $sha256,
                    'request_key' => $key, 'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);
                return DB::table('fleet_maintenance_attachments')->where('id', $id)->first();
            });
        } catch (\Throwable $error) {
            if ($writtenPath) {
                Storage::disk('private')->delete($writtenPath);
            }
            if ($error instanceof QueryException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                $preview = FleetWorkOrder::query()->whereKey($workOrderId)->firstOrFail(['id', 'asset_id']);
                $this->access->asset($actor, (int) $preview->asset_id);
                $this->assertParent($actor, $preview, $parentType, $parentId, true);
                $prior = DB::table('fleet_maintenance_attachments')
                    ->where('uploaded_by_user_id', $actor->id)->where('request_key', $key)->first();
                if ($prior && (int) $prior->work_order_id === $workOrderId
                    && hash_equals((string) $prior->request_fingerprint, $fingerprint)) {
                    return $prior;
                }
            }
            throw $error;
        }
    }

    public function download(User $actor, int $workOrderId, int $attachmentId): object
    {
        $order = FleetWorkOrder::query()->whereKey($workOrderId)->firstOrFail();
        $this->access->asset($actor, (int) $order->asset_id);
        $row = DB::table('fleet_maintenance_attachments')->where('id', $attachmentId)
            ->where('work_order_id', $order->id)->where('disk', 'private')->first();
        abort_unless($row, 404);
        $parentCount = (int) ($row->report_id !== null) + (int) ($row->check_run_id !== null)
            + (int) ($row->action_id !== null);
        abort_unless($parentCount <= 1, 404);
        $this->assertParent($actor, $order,
            $parentCount === 0 ? 'work' : ($row->report_id ? 'report' : ($row->check_run_id ? 'check' : 'action')),
            (int) ($row->report_id ?? $row->check_run_id ?? $row->action_id ?? 0));
        abort_unless(Storage::disk('private')->exists($row->path), 404);

        return $row;
    }

    private function assertParent(User $actor, FleetWorkOrder $order, string $type, int $id, bool $write = false): void
    {
        $asset = $this->access->asset($actor, (int) $order->asset_id);
        if ($type === 'work') {
            abort_unless($id === 0, 422);
            abort_unless($this->access->canManage($actor) || (! $write && $this->access->canReview($actor, $asset)), 403);

            return;
        }
        $privileged = $this->access->canManage($actor) || $this->access->canReview($actor, $asset);
        $column = match ($type) {
            'report' => 'fleet_maintenance_reports',
            'check' => 'fleet_checklist_runs',
            'action' => 'fleet_maintenance_actions',
        };
        $parent = DB::table($column)->where('id', $id)->where('work_order_id', $order->id)->first();
        abort_unless($parent, 404);
        if ($type === 'check') {
            abort_unless((int) $parent->asset_id === (int) $order->asset_id, 404);
        }
        if ($type === 'report') {
            abort_unless((int) $parent->asset_id === (int) $order->asset_id, 404);
            if ($write ? ! $this->access->canManage($actor) : ! $privileged) {
                abort_unless($this->access->canReport($actor)
                    && (int) $parent->submitted_by_user_id === (int) $actor->id, 403);
            }
        } else {
            abort_unless($write ? $this->access->canManage($actor) : $privileged, 403);
            if ($write && $type === 'action' && $parent->action_type === 'attest_repair') {
                abort_unless((int) $parent->actor_user_id === (int) $actor->id, 403);
            }
        }
    }
}

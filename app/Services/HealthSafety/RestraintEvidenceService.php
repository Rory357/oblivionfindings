<?php

namespace App\Services\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Private bytes and retained removal evidence use the same source command/receipt transaction. */
class RestraintEvidenceService
{
    use ServesPrivateAttachments;

    public function __construct(private readonly RestraintService $commands, private readonly RestraintAccessService $access, private readonly RestraintSourceDeliveryService $delivery) {}

    public function add(RestraintEvent $event, User $actor, array $input, UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240']])->validate();
        $manifest = ['sha256' => hash_file('sha256', $file->getRealPath()), 'name' => basename($file->getClientOriginalName()), 'size' => $file->getSize(), 'mime' => $file->getMimeType()];
        $paths = [];
        // A caller-owned outer transaction may roll back after this command returned.
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$paths): void {
                foreach ($paths as $path) {
                    Storage::disk('private')->delete($path);
                }
            });
        }
        try {
            $result = $this->commands->command($event, $actor, 'add_evidence', [...$input, 'file_manifest' => $manifest], fn ($v) => RestraintCommandValues::validate($v, ['category' => ['nullable', Rule::in(['body_map', 'injury_photo', 'authorisation', 'debrief', 'other'])], 'notes' => ['nullable', 'string', 'max:500'], 'file_manifest' => ['required', 'array:sha256,name,size,mime']]), function ($row, $current, $reads, $sites, $v) use ($file, &$paths): array {
                $path = $file->store('restraint_attachments', 'private');
                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('Private evidence storage did not confirm saved bytes.');
                }
                $paths[] = $path;
                DB::afterRollBack(fn () => Storage::disk('private')->delete($path));
                $a = RestraintEventAttachment::create(['restraint_event_id' => $row->id, 'uploaded_by' => $current->id, 'uploader_snapshot' => $this->commands->actorSnapshot($current), 'disk' => 'private', 'path' => $path, 'original_name' => $v['file_manifest']['name'], 'mime' => $v['file_manifest']['mime'], 'mime_type' => $v['file_manifest']['mime'], 'size' => $v['file_manifest']['size'], 'sha256' => $v['file_manifest']['sha256'], 'source_revision' => $row->source_revision, 'category' => $v['category'] ?? null, 'notes' => $v['notes'] ?? null]);
                if (! $a->id || ! RestraintEventAttachment::whereKey($a->id)->exists()) {
                    throw new RuntimeException('Private evidence metadata was not confirmed.');
                }

                return ['attachment_id' => $a->id];
            });
        } catch (Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('private')->delete($path);
            } throw $error;
        }

        return $result;
    }

    public function remove(RestraintEvent $event, RestraintEventAttachment $attachment, User $actor, array $input): array
    {
        return $this->commands->command($event, $actor, 'remove_evidence', [...$input, 'attachment_id' => (int) $attachment->id], fn ($v) => RestraintCommandValues::validate($v, ['attachment_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $v): array {
            $a = RestraintEventAttachment::where('restraint_event_id', $row->id)->whereKey($v['attachment_id'])->lockForUpdate()->firstOrFail();
            if ($a->removed_at) {
                throw ValidationException::withMessages(['attachment' => 'This evidence already has retained removal provenance.']);
            }
            $a->forceFill(['removed_at' => now()->utc(), 'removed_by' => $current->id, 'removal_reason' => $v['reason'], 'removal_command_key' => $v['idempotency_key']])->save();
            $this->delivery->stageEvidenceRemoval($row, $a, $current);

            return ['attachment_id' => $a->id];
        });
    }

    public function download(RestraintEvent $event, RestraintEventAttachment $attachment, User $viewer): StreamedResponse
    {
        $file = $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($event, $attachment): array {
            $row = $reads->query(RestraintEvent::query())->findOrFail($event->id);
            $this->access->assertEvent($row, $current, $reads, $sites, $current->canDo('restraints.view') ? 'view' : 'workspace');
            $a = $reads->query(RestraintEventAttachment::query())->where('restraint_event_id', $row->id)->whereKey($attachment->id)->firstOrFail();
            abort_unless(! $a->removed_at && $a->disk === 'private' && str_starts_with($a->path, 'restraint_attachments/') && ! str_contains($a->path, '..') && Storage::disk('private')->exists($a->path), 404);

            return $a->only(['path', 'original_name', 'mime', 'mime_type']);
        });

        return $this->streamPrivateAttachment('private', $file['path'], basename($file['original_name']), $file['mime'] ?? $file['mime_type']);
    }
}

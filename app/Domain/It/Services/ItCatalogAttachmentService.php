<?php

namespace App\Domain\It\Services;

use App\Models\ItAttachment;
use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Catalogue evidence stays on the canonical submission and its original field audience. */
final class ItCatalogAttachmentService
{
    /** Safe metadata through the same current access check as the download. */
    public function forResult(User $actor, ItTicket|ItProvisioningRequest $result, bool $publicOnly = false): array
    {
        $submissions = ItCatalogSubmission::query()->where('result_type', $result->getMorphClass())
            ->where('result_id', $result->getKey())->with('attachments')->orderBy('id')->get();
        $files = [];
        foreach ($submissions as $submission) {
            foreach ($submission->attachments as $attachment) {
                $field = $this->field($submission, (string) $attachment->catalogue_field_key);
                $internal = ($field['visibility'] ?? 'requester') !== 'requester'
                    || ($submission->contract_snapshot['internal_only'] ?? false);
                if (! $field || ($publicOnly && $internal) || ! $this->canDownload($actor, $attachment)) {
                    continue;
                }
                $files[] = ['id' => $attachment->id, 'name' => $attachment->original_name,
                    'size' => $attachment->size, 'url' => '/it/attachments/'.$attachment->id,
                    'catalogue_field_label' => (string) ($field['label'] ?? $field['key']),
                    'is_internal' => $internal];
            }
        }

        return $files;
    }

    public function store(ItCatalogSubmission $submission, User $actor, array $fields, ItAttachmentWriteContext $context): void
    {
        $context->assertActive();
        if ((int) $submission->requester_user_id !== (int) $actor->id) {
            throw new LogicException('Catalogue evidence requires its original submitting actor.');
        }
        $storage = app(ItAttachmentStorageService::class);
        foreach ($fields as $key => $files) {
            $field = $this->field($submission, (string) $key);
            if (! $field || (($field['visibility'] ?? 'requester') !== 'requester' && ! $actor->canDo('it.manage'))) {
                throw ValidationException::withMessages(["values.{$key}" => 'This attachment field is not available.']);
            }
            $reservations = $storage->reserveDirect($submission, $files, $actor);
            $context->remember($reservations);
            $paths = [];
            $storage->storeReservedDirect($submission, $files, $actor, $reservations, $paths);
            foreach ($submission->attachments()->whereIn('path', $paths)->get() as $attachment) {
                $attachment->forceFill(['catalogue_field_key' => $key])->save();
            }
        }
        if ($fields !== []) {
            AuditLogger::logOrFail('it.catalogue.attachments.recorded', $submission, [
                'actor_id' => $actor->id, 'attachment_count' => $submission->attachments()->count(),
            ]);
        }
    }

    public function canDownload(User $actor, ItAttachment $attachment): bool
    {
        // Reload both ownership and the immutable field. Neither current form
        // publication nor a caller-supplied parent can broaden a saved file.
        $file = ItAttachment::query()->find($attachment->getKey());
        $submission = $file?->attachable;
        if (! $actor->isApproved() || ! $submission instanceof ItCatalogSubmission
            || ! is_string($file->catalogue_field_key)) {
            return false;
        }
        $field = $this->field($submission, $file->catalogue_field_key);
        if (! $field || ! in_array($field['visibility'] ?? 'requester', ['requester', 'internal', 'restricted'], true)) {
            return false;
        }
        $internal = ($field['visibility'] ?? 'requester') !== 'requester'
            || ($submission->contract_snapshot['internal_only'] ?? false);
        $result = $submission->result;
        if ($result instanceof ItTicket) {
            $access = app(ItWorkAccessService::class);

            return $access->canView($actor, $result) && $actor->can('view', $result)
                && (! $internal || $access->canWork($actor, $result));
        }
        if ($result instanceof ItProvisioningRequest) {
            $access = app(ItProvisioningAccessService::class);

            return $access->canView($actor, $result) || (! $internal && $access->canTrack($actor, $result));
        }

        return false;
    }

    public static function fingerprint(UploadedFile $file): array
    {
        $hash = $file->isValid() ? hash_file('sha256', $file->getPathname()) : false;
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['values' => 'A file could not be read. Choose it again before retrying.']);
        }

        return ['file_name' => $file->getClientOriginalName(), 'size' => (int) $file->getSize(), 'sha256' => $hash];
    }

    private function field(ItCatalogSubmission $submission, string $key): ?array
    {
        $fields = array_values(array_filter($submission->schema_snapshot['fields'] ?? [],
            fn (mixed $field): bool => is_array($field) && ($field['key'] ?? null) === $key));

        return count($fields) === 1 && ($fields[0]['type'] ?? null) === 'attachment' ? $fields[0] : null;
    }
}

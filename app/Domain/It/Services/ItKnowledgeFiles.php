<?php

namespace App\Domain\It\Services;

use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\ItKbRevision;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ItKnowledgeFiles
{
    public function upload(ItKbArticle $article, User $actor, UploadedFile $upload, int $version, ?int $replace, ?string $requestUuid = null): ItKbFile
    {
        abort_unless(app(ItKnowledgeMedia::class)->ready(), 503);
        $extension = strtolower($upload->getClientOriginalExtension());
        $mime = $upload->getMimeType();
        $allowed = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
        ];
        if (! in_array($mime, $allowed[$extension] ?? [], true)) {
            throw ValidationException::withMessages(['file' => 'Choose a valid Word (.doc or .docx), PDF, PNG or JPEG file.']);
        }
        if ($extension === 'docx' && $this->wordText($upload->getRealPath()) === null) {
            throw ValidationException::withMessages(['file' => 'This Word file cannot be safely read. Export it as a PDF or upload a valid .docx.']);
        }
        if (in_array($mime, ItKnowledgeRaster::MIME_TYPES, true)) {
            app(ItKnowledgeRaster::class)->inspect($upload->getRealPath(), $mime);
        }
        // Commit ownership before writing bytes. Interrupted uploads remain
        // private, identifiable reservations, never untracked public files.
        $requestUuid ??= (string) Str::uuid();
        $file = DB::transaction(function () use ($article, $actor, $upload, $version, $replace, $mime, $requestUuid): ItKbFile {
            $locked = ItKbArticle::query()->lockForUpdate()->findOrFail($article->id);
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            abort_unless(app(ItKbAccessService::class)->canAuthor($actor, $locked), 404);
            $existing = ItKbFile::query()->where('uploaded_by_user_id', $actor->id)->where('request_uuid', $requestUuid)->first();
            if ($existing) {
                abort_unless($existing->article_id === $locked->id && app(ItKbRevisionService::class)->canAccessScope($actor, $existing->audience, $existing->site_scope), 404);
                abort_unless($existing->expected_version === $version && $existing->replaces_file_id === $replace
                    && hash_equals($existing->sha256, hash_file('sha256', $upload->getRealPath())), 409, 'This upload reference belongs to different file content or a different document version.');

                return $existing;
            }
            $copy = app(ItKbRevisionService::class)->workingCopy($locked);
            abort_if($copy && ! app(ItKbRevisionService::class)->canAccessScope($actor, $copy->audience, $copy->site_scope), 404);
            if ((int) $locked->lock_version !== $version || ! in_array($locked->status, ['draft', 'published'], true) || $copy?->status === 'in_review') {
                throw ValidationException::withMessages(['file' => 'This document changed or is awaiting review. Refresh it before uploading.']);
            }
            $content = $copy?->snapshot ?? app(ItKbRevisionService::class)->content($locked);
            $ids = $content['file_ids'] ?? [];
            if (! $replace && count($ids) >= 30) {
                throw ValidationException::withMessages(['file' => 'A document can contain up to 30 files.']);
            }
            $previous = $replace ? ItKbFile::query()->where('article_id', $locked->id)->whereKey($ids)->findOrFail($replace) : null;
            if ($replace && ! in_array($mime, ItKnowledgeRaster::MIME_TYPES, true) && $this->referencesImage($content['diagrams'] ?? [], $replace)) {
                throw ValidationException::withMessages(['file' => 'This image is used in a diagram. Replace it with a PNG or JPEG, or remove it from the diagram first.']);
            }
            $series = $previous?->series_id ?? (string) Str::uuid();
            $name = mb_substr(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', $upload->getClientOriginalName()), 0, 240);
            $file = ItKbFile::query()->create([
                'article_id' => $locked->id, 'series_id' => $series,
                'version' => (int) ItKbFile::query()->where('series_id', $series)->max('version') + 1,
                'name' => $name, 'mime' => $mime, 'size' => $upload->getSize(),
                'sha256' => hash_file('sha256', $upload->getRealPath()),
                'path' => 'it_knowledge/'.Str::uuid(), 'state' => 'reserved',
                'request_uuid' => $requestUuid, 'expected_version' => $version, 'replaces_file_id' => $replace,
                'audience' => $content['audience'], 'site_scope' => $content['site_scope'] ?? null,
                'uploaded_by_user_id' => $actor->id, 'created_at' => now(),
            ]);
            AuditLogger::logOrFail('it.knowledge.file.reserved', $locked, ['actor_id' => $actor->id, 'file_id' => $file->id]);

            return $file;
        });
        $this->resume($article, $actor, $file, $version, $upload);

        return $file->fresh();
    }

    /** The same reservation is resumed; bytes and the final mutation serialize under the parent lock. */
    public function resume(ItKbArticle $article, User $actor, ItKbFile $file, int $version, ?UploadedFile $upload = null): void
    {
        $error = DB::transaction(function () use ($article, $actor, $file, $version, $upload): ?string {
            $locked = ItKbArticle::query()->lockForUpdate()->findOrFail($article->id);
            $file = ItKbFile::query()->lockForUpdate()->findOrFail($file->id);
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            $this->guardReservation($actor, $locked, $file);
            if ($file->state === 'ready') {
                abort_unless($this->canOpen($actor, $locked, $file), 404);

                return null;
            }
            if (! in_array($file->state, ['reserved', 'scan_unavailable'], true)) {
                return 'This upload cannot be resumed. Remove it from unfinished uploads and choose another file.';
            }
            $revisions = app(ItKbRevisionService::class);
            $copy = $revisions->workingCopy($locked);
            abort_if($copy && ! $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope), 404);
            if ($version !== $file->expected_version || $locked->lock_version !== $version || ! in_array($locked->status, ['draft', 'published'], true) || $copy?->status === 'in_review') {
                return 'The document changed after this upload began. Review the current draft, remove the unfinished upload and upload the file again.';
            }
            $content = $copy?->snapshot ?? $revisions->content($locked);
            $replace = $file->replaces_file_id;
            if ($replace && ! in_array($replace, $content['file_ids'] ?? [], true)) {
                return 'The file being replaced is no longer in this draft. Review the current files before uploading again.';
            }
            $disk = Storage::disk(ItAttachment::DISK);
            if ($upload && ! $disk->exists($file->path)) {
                if ($disk->putFileAs('it_knowledge', $upload, basename($file->path)) !== $file->path) {
                    return 'The file could not be stored. Your document is unchanged.';
                }
            }
            if (! $disk->exists($file->path)) {
                return 'The upload did not finish transferring. Choose the original file again, or remove this unfinished upload.';
            }
            if (! hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path)))) {
                $file->update(['state' => 'integrity_failed']);
                AuditLogger::logOrFail('it.knowledge.file.integrity_failed', $locked, ['actor_id' => $actor->id, 'file_id' => $file->id]);

                return 'The stored file could not be verified. It remains private. Choose another upload.';
            }
            $scan = app(MalwareScanner::class)->scanPath($disk->path($file->path), (array) config('it.inbound_mail.malware_scanner'));
            if ($scan->disposition !== MalwareScanDisposition::Clean) {
                $state = $scan->disposition === MalwareScanDisposition::Infected ? 'quarantined' : 'scan_unavailable';
                $file->update(['state' => $state]);
                AuditLogger::logOrFail('it.knowledge.file.'.$state, $locked, ['actor_id' => $actor->id, 'file_id' => $file->id]);

                return $state === 'quarantined' ? 'The file was quarantined and cannot be opened. Choose another file.'
                    : 'The malware check is unavailable. The file stays private; retry this unfinished upload when checking is available.';
            }
            $ids = array_values(array_filter($content['file_ids'] ?? [], fn ($id) => (int) $id !== $replace));
            $change = ['lock_version' => $version, 'file_ids' => [...$ids, $file->id]];
            if ($replace && $this->referencesImage($content['diagrams'] ?? [], $replace)) {
                if (! in_array($file->mime, ItKnowledgeRaster::MIME_TYPES, true)) {
                    return 'This file is used as a diagram image. Choose a PNG or JPEG replacement.';
                }
                $change['diagrams'] = $content['diagrams'];
                foreach ($change['diagrams'] as &$diagram) {
                    if (($diagram['schema_version'] ?? null) !== 2) {
                        continue;
                    }
                    foreach ($diagram['pages'] as &$page) {
                        foreach ($page['nodes'] as &$node) {
                            if ($node['type'] === 'image' && (int) $node['imageFileId'] === $replace) {
                                $node['imageFileId'] = $file->id;
                            }
                        }
                        unset($node);
                    }
                    unset($page);
                }
                unset($diagram);
            }
            $file->update(['state' => 'ready']);
            app(ItKbLifecycleService::class)->update($locked, $actor, $change);
            AuditLogger::logOrFail('it.knowledge.file.added', $locked, ['actor_id' => $actor->id, 'file_id' => $file->id, 'replaces_file_id' => $replace]);

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['file' => $error]);
        }
    }

    private function referencesImage(array $diagrams, int $fileId): bool
    {
        foreach ($diagrams as $diagram) {
            if (($diagram['schema_version'] ?? null) !== 2) {
                continue;
            }
            foreach ($diagram['pages'] as $page) {
                foreach ($page['nodes'] as $node) {
                    if ($node['type'] === 'image' && (int) $node['imageFileId'] === $fileId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function guardReservation(User $actor, ItKbArticle $article, ItKbFile $file): void
    {
        abort_unless($file->article_id === $article->id && $file->uploaded_by_user_id === $actor->id
            && app(ItKbAccessService::class)->canAuthor($actor, $article)
            && app(ItKbRevisionService::class)->canAccessScope($actor, $file->audience, $file->site_scope), 404);
    }

    public function dismiss(ItKbArticle $article, User $actor, ItKbFile $file): void
    {
        DB::transaction(function () use ($article, $actor, $file): void {
            $article = ItKbArticle::query()->lockForUpdate()->findOrFail($article->id);
            $file = ItKbFile::query()->lockForUpdate()->findOrFail($file->id);
            $actor = app(ItTicketVersionService::class)->currentActor($actor);
            $this->guardReservation($actor, $article, $file);
            abort_if($file->state === 'ready', 409, 'This file was saved. Refresh the document to see it.');
            if ($file->state === 'abandoned') {
                return;
            }
            $file->update(['state' => 'abandoned']);
            AuditLogger::logOrFail('it.knowledge.file.abandoned', $article, ['actor_id' => $actor->id, 'file_id' => $file->id]);
        });
    }

    public function unfinished(User $actor, ItKbArticle $article, ?int $before = null): array
    {
        if (! app(ItKnowledgeMedia::class)->ready() || ! app(ItKbAccessService::class)->canAuthor($actor, $article)) {
            return ['files' => [], 'next_before_id' => null];
        }
        $query = ItKbFile::query()->where('article_id', $article->id)->where('uploaded_by_user_id', $actor->id)
            ->whereNotIn('state', ['ready', 'abandoned'])->when($before, fn ($query) => $query->where('id', '<', $before));
        $rows = app(ItKbRevisionService::class)->applyOriginalScope($query, $actor)->orderByDesc('id')->limit(21)->get();
        $page = $rows->take(20);

        return ['files' => $page->map(fn ($file) => ['id' => $file->id, 'name' => $file->name, 'state' => $file->state,
            'expected_version' => $file->expected_version, 'created_at' => $file->created_at->toIso8601String(),
            'can_retry' => in_array($file->state, ['reserved', 'scan_unavailable'], true) && $file->expected_version === $article->lock_version])->values()->all(),
            'next_before_id' => $rows->count() > 20 ? $page->last()->id : null];
    }

    public function canOpen(User $actor, ItKbArticle $article, ItKbFile $file): bool
    {
        if ($file->article_id !== $article->id || $file->state !== 'ready') {
            return false;
        }

        return $this->visibleQuery($actor, $article)->whereKey($file->id)->exists();
    }

    private function currentIds(User $actor, ItKbArticle $article): array
    {
        $ids = $article->file_ids ?? [];
        $revisions = app(ItKbRevisionService::class);
        $copy = $revisions->workingCopy($article);
        if (app(ItKbAccessService::class)->canManage($actor, $article) && $copy && $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
            $ids = [...$ids, ...($copy->snapshot['file_ids'] ?? [])];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Apply current parent access and original revision scope before limiting rows. */
    private function visibleQuery(User $actor, ItKbArticle $article): Builder
    {
        $query = ItKbFile::query()->where('article_id', $article->id)->where('state', 'ready');
        $access = app(ItKbAccessService::class);
        if (! app(ItKnowledgeMedia::class)->ready() || ! $access->applyViewScope(ItKbArticle::query(), $actor)->whereKey($article->id)->exists()) {
            return $query->whereRaw('1 = 0');
        }
        $ids = $this->currentIds($actor, $article);
        $manage = $access->canManage($actor, $article);
        $history = app(ItKbRevisionService::class)->applyOriginalScope(ItKbRevision::query()->where('article_id', $article->id), $actor)->select('id');

        $query = $manage ? app(ItKbRevisionService::class)->applyOriginalScope($query, $actor)
            : app(ItKnowledgePublishedRevisions::class)->audienceScope($query, $actor);

        return $query->where(function ($query) use ($ids, $manage, $history): void {
            $query->whereIn('id', $ids);
            if ($manage) {
                $query->orWhereIn('id', DB::table('it_kb_revision_files')->select('file_id')->whereIn('revision_id', $history));
            }
        });
    }

    public function presentation(User $actor, ItKbArticle $article): array
    {
        if (! app(ItKnowledgeMedia::class)->ready()) {
            return [];
        }

        return $this->visibleQuery($actor, $article)->whereKey($this->currentIds($actor, $article))->orderByDesc('id')->get()
            ->map(fn ($file) => $this->present($file))->all();
    }

    public function history(User $actor, ItKbArticle $article, ?int $before = null): array
    {
        if (! app(ItKnowledgeMedia::class)->ready()) {
            return ['files' => [], 'next_before_id' => null];
        }
        $rows = $this->visibleQuery($actor, $article)->whereNotIn('id', $this->currentIds($actor, $article))
            ->when($before, fn ($query) => $query->where('id', '<', $before))->orderByDesc('id')->limit(21)->get();
        $page = $rows->take(20);

        return ['files' => $page->map(fn ($file) => $this->present($file))->values()->all(),
            'next_before_id' => $rows->count() > 20 ? $page->last()->id : null];
    }

    private function present(ItKbFile $file): array
    {
        return ['id' => $file->id, 'series_id' => $file->series_id, 'version' => $file->version,
            'name' => $file->name, 'size' => $file->size, 'created_at' => $file->created_at->toIso8601String(),
            'href' => "/it/knowledge/{$file->article_id}/files/{$file->id}"];
    }

    /** Text-only Word preview: bounded XML, no entities, links or active content. */
    public function wordText(string $path): ?string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            $info = $zip->statName('word/document.xml');
            if (! $info || $info['size'] > 4 * 1024 * 1024) {
                return null;
            }
            $xml = $zip->getFromName('word/document.xml');
            if (! is_string($xml) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                return null;
            }
            $document = new \DOMDocument;
            if (! @$document->loadXML($xml, LIBXML_NONET)) {
                return null;
            }
            $lines = [];
            foreach ($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
                $text = '';
                foreach ($paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't') as $run) {
                    $text .= $run->textContent;
                }
                $lines[] = $text;
            }

            return trim(implode("\n", $lines));
        } finally {
            $zip->close();
        }
    }
}

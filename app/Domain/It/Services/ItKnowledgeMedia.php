<?php

namespace App\Domain\It\Services;

use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Bounded, inert diagram source; SVG/HTML and external resources are never accepted. */
final class ItKnowledgeMedia
{
    public function ready(): bool
    {
        return Schema::hasColumn('it_kb_articles', 'diagrams') && Schema::hasTable('it_kb_files') && Schema::hasTable('it_kb_revision_files');
    }

    public static function rules(): array
    {
        return [
            'diagrams' => ['sometimes', 'nullable', 'array', 'max:12', static function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (app(ItKnowledgeDiagramSource::class)->issues($value) as $issue) {
                    $fail($issue['message']);
                }
            }],
            'file_ids' => ['sometimes', 'nullable', 'array', 'max:30'],
            'file_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function validate(?ItKbArticle $article, User $actor, array $data): array
    {
        if (! $this->ready()) {
            if (! empty($data['diagrams']) || ! empty($data['file_ids'])) {
                throw new \DomainException('Document media storage is not available yet. Your draft has not been saved.');
            }

            return Arr::except($data, ['diagrams', 'file_ids']);
        }
        validator($data, self::rules())->validate();
        $revisions = app(ItKbRevisionService::class);
        $current = $article ? ($revisions->workingCopy($article)?->snapshot ?? $revisions->content($article)) : [];
        $content = array_replace($current, Arr::only($data, ['diagrams', 'file_ids']));
        $ids = array_map('intval', $content['file_ids'] ?? []);
        $files = collect();
        if ($ids) {
            $files = $article ? ItKbFile::query()->where('article_id', $article->id)->where('state', 'ready')->whereKey($ids)->get() : collect();
            $access = app(ItKnowledgeFiles::class);
            if ($files->count() !== count($ids) || $files->contains(fn (ItKbFile $file) => ! $access->canOpen($actor, $article, $file)
                && ! $revisions->canAccessScope($actor, $file->audience, $file->site_scope))) {
                throw new \DomainException('One or more document files are unavailable. Refresh the document files before saving.');
            }
        }
        $imageIds = [];
        foreach ($content['diagrams'] ?? [] as $diagram) {
            if (($diagram['schema_version'] ?? null) !== 2) {
                continue;
            }
            foreach ($diagram['pages'] as $page) {
                foreach ($page['nodes'] as $node) {
                    if ($node['type'] === 'image') {
                        $imageIds[(int) $node['imageFileId']] = true;
                    }
                }
            }
        }
        foreach (array_keys($imageIds) as $imageId) {
            $file = $files->firstWhere('id', $imageId);
            if (! in_array($imageId, $ids, true) || ! $file || ! in_array($file->mime, ItKnowledgeRaster::MIME_TYPES, true)) {
                throw new \DomainException('Each diagram image must use an available PNG or JPEG file retained in this document. Remove the image from the diagram before removing its file.');
            }
            $path = Storage::disk(ItAttachment::DISK)->path($file->path);
            $raster = app(ItKnowledgeRaster::class)->inspect($path, $file->mime);
            if ($raster['bytes'] !== (int) $file->size || ! hash_equals($file->sha256, hash_file('sha256', $path))) {
                throw new \DomainException('A diagram image could not be verified. Restore a verified file before saving.');
            }
        }

        return $data;
    }
}

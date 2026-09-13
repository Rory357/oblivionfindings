<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItKnowledgeFiles;
use App\Domain\It\Services\ItKnowledgeNavigation;
use App\Domain\It\Services\ItKnowledgePublishedRevisions;
use App\Domain\It\Services\ItKnowledgeRaster;
use App\Http\Controllers\Controller;
use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItKbFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ItKnowledgeFileController extends Controller
{
    public function history(Request $request, ItKbArticle $article, ItKnowledgeFiles $files)
    {
        $actor = $request->user();
        $article = app(ItKbAccessService::class)->applyViewScope(ItKbArticle::query(), $actor)->findOrFail($article->id);
        $data = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['actor_user_id' => $actor->id, 'article_id' => $article->id,
            ...$files->history($actor, $article, $data['before_id'] ?? null)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ItKbArticle $article, ItKnowledgeFiles $files)
    {
        $data = $request->validate([
            'actor_user_id' => ['required', 'integer', 'in:'.$request->user()->id],
            'lock_version' => ['required', 'integer', 'min:1'],
            'request_uuid' => ['nullable', 'uuid'],
            'replace_file_id' => ['nullable', 'integer', 'min:1'],
            'diagram_image' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:20480'],
        ]);
        if ($request->boolean('diagram_image') && ! in_array($request->file('file')->getMimeType(), ItKnowledgeRaster::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => 'Choose a PNG or JPEG image for the diagram.']);
        }
        try {
            $file = $files->upload($article, $request->user(), $request->file('file'), $data['lock_version'], $data['replace_file_id'] ?? null, $data['request_uuid'] ?? null);
        } catch (\DomainException $exception) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }

            return back()->withErrors(['file' => $exception->getMessage()]);
        }
        if ($request->expectsJson()) {
            $article->refresh();
            $content = app(ItKbRevisionService::class)->workingCopy($article)?->snapshot ?? app(ItKbRevisionService::class)->content($article);
            $raster = in_array($file->mime, ItKnowledgeRaster::MIME_TYPES, true)
                ? app(ItKnowledgeRaster::class)->inspect(Storage::disk(ItAttachment::DISK)->path($file->path), $file->mime) : null;

            return response()->json(['actor_user_id' => $request->user()->id, 'article_id' => $article->id,
                'lock_version' => $article->lock_version, 'file_ids' => $content['file_ids'] ?? [],
                'file' => ['fileId' => $file->id, ...($raster ?? ['mime' => $file->mime, 'bytes' => $file->size])]])
                ->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', 'File saved in the document draft. Send the draft for review when it is ready.');
    }

    public function unfinished(Request $request, ItKbArticle $article, ItKnowledgeFiles $files)
    {
        abort_unless(app(ItKbAccessService::class)->canAuthor($request->user(), $article), 404);
        $data = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['actor_user_id' => $request->user()->id, 'article_id' => $article->id,
            ...$files->unfinished($request->user(), $article, $data['before_id'] ?? null)])->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, ItKbArticle $article, ItKbFile $file, ItKnowledgeFiles $files)
    {
        $data = $request->validate(['actor_user_id' => ['required', 'integer', 'in:'.$request->user()->id], 'lock_version' => ['required', 'integer', 'min:1']]);
        $files->resume($article, $request->user(), $file, $data['lock_version']);

        return response()->json(['actor_user_id' => $request->user()->id, 'article_id' => $article->id, 'file_id' => $file->id, 'state' => 'ready'])->header('Cache-Control', 'private, no-store');
    }

    public function dismiss(Request $request, ItKbArticle $article, ItKbFile $file, ItKnowledgeFiles $files)
    {
        $request->validate(['actor_user_id' => ['required', 'integer', 'in:'.$request->user()->id]]);
        $files->dismiss($article, $request->user(), $file);

        return response()->json(['actor_user_id' => $request->user()->id, 'article_id' => $article->id, 'file_id' => $file->id, 'state' => 'abandoned'])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, ItKbArticle $article, ItKbFile $file, ItKnowledgeFiles $files)
    {
        $request->validate(['revision' => ['nullable', 'integer', 'min:1'],
            'actor_user_id' => [$request->query('raster') === '1' ? 'required' : 'sometimes', 'integer', 'in:'.$request->user()->id]]);
        $revisionId = $request->filled('revision') ? $request->integer('revision') : null;
        abort_unless($revisionId
            ? app(ItKnowledgePublishedRevisions::class)->canOpenFile($request->user(), $article, $file, $revisionId)
            : $files->canOpen($request->user(), $article, $file), 404);
        $disk = Storage::disk(ItAttachment::DISK);
        abort_unless($disk->exists($file->path), 404);
        $path = $disk->path($file->path);
        abort_unless(is_readable($path) && filesize($path) === (int) $file->size, 409, 'The stored file could not be verified. Ask the document owner to restore a verified copy.');
        $digest = hash_file('sha256', $path);
        abort_unless(is_string($digest) && hash_equals($file->sha256, $digest), 409, 'The stored file could not be verified. Ask the document owner to restore a verified copy.');
        $href = "/it/knowledge/{$article->id}/files/{$file->id}";
        $fileQuery = $revisionId ? '?revision='.$revisionId.'&' : '?';
        if ($request->query('raster') === '1') {
            abort_unless(in_array($file->mime, ItKnowledgeRaster::MIME_TYPES, true), 404);
            $raster = app(ItKnowledgeRaster::class)->inspect($path, $file->mime);

            return response()->file($path, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'",
                'X-Knowledge-Actor-Id' => (string) $request->user()->id, 'X-Knowledge-Article-Id' => (string) $article->id,
                'X-Knowledge-File-Id' => (string) $file->id, 'X-Image-Width' => (string) $raster['width'], 'X-Image-Height' => (string) $raster['height']]);
        }
        if ($request->query('original') === '1') {
            return response()->download($path, $file->name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        if ($request->query('inline') === '1') {
            abort_unless($file->mime === 'application/pdf', 404);

            return response()->file($path, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'"]);
        }
        $text = strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) === 'docx' ? $files->wordText($path) : null;
        $navigation = app(ItKnowledgeNavigation::class);
        $context = $navigation->context($request);
        $documentHref = $navigation->documentHref($article->id, $context);
        $filesHref = $navigation->documentHref($article->id, $context, 'files');
        if ($revisionId) {
            $documentHref .= (str_contains($documentHref, '?') ? '&' : '?').'revision='.$revisionId;
            $filesHref .= '&revision='.$revisionId;
        }
        Inertia::encryptHistory();

        return Inertia::render('it/knowledge/file', [
            'document' => ['id' => $article->id, 'title' => $article->title,
                'href' => $documentHref,
                'files_href' => $filesHref,
                'library_href' => $navigation->libraryHref($context)],
            'file' => ['name' => $file->name, 'version' => $file->version, 'size' => $file->size,
                'original_href' => $href.$fileQuery.'original=1', 'pdf_href' => $file->mime === 'application/pdf' ? $href.$fileQuery.'inline=1' : null,
                'raster_href' => in_array($file->mime, ItKnowledgeRaster::MIME_TYPES, true) ? $href.$fileQuery.'raster=1&actor_user_id='.$request->user()->id : null,
                'text' => $text],
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}

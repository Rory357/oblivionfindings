<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HubMediaController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['library' => 'nullable|in:page,all', 'q' => 'nullable|string|max:160', 'album_id' => 'nullable|integer|min:1', 'type' => 'nullable|in:image,all', 'cursor_page' => 'nullable|integer|min:1|max:10000']);
    }

    public function index(Request $request, HubPage $page, HubMediaLibrary $library)
    {
        return response()->json($library->listing($request->user(), $page, $this->filters($request)));
    }

    public function workspace(Request $request, HubMediaLibrary $library)
    {
        return response()->json($library->listing($request->user(), null, $this->filters($request)));
    }

    public function album(Request $request, HubMediaLibrary $library)
    {
        $data = $request->validate(['name' => 'required|string|max:80']);

        return response()->json(['id' => $library->album($request->user(), $data['name']), 'name' => $data['name']]);
    }

    public function crop(Request $request, HubPage $page, HubMedia $media, HubMediaLibrary $library)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'ratio' => 'required|in:16:9,1:1,4:3', 'x' => 'required|integer|min:0|max:100', 'y' => 'required|integer|min:0|max:100']);

        return response()->json($library->describe($library->crop($request->user(), $page, $media, $data), $page));
    }

    public function reuse(Request $request, HubPage $page, HubAccess $access, HubMediaLibrary $library)
    {
        $access->assertEdit($request->user(), $page);
        $data = $request->validate(['media_id' => 'required|integer', 'version' => 'nullable|integer|min:1', 'copy' => 'nullable|boolean']);
        $source = HubMedia::findOrFail($data['media_id']);
        $access->assertEdit($request->user(), HubPage::findOrFail($source->hub_page_id));
        abort_if(isset($data['version']) && $source->version !== (int) $data['version'], 409, 'This file changed. Refresh the library before selecting it.');
        if ((int) $source->hub_page_id === (int) $page->id && ! ($data['copy'] ?? false)) {
            return response()->json($library->describe($source, $page));
        }
        $extension = match ($source->mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf', default => null,
        };
        abort_unless($extension && Storage::disk('private')->exists($source->path), 404);
        $path = 'company-hub/'.$page->id.'/'.Str::uuid().'.'.$extension;
        abort_unless(Storage::disk('private')->copy($source->path, $path), 500, 'The file could not be copied. Try again.');
        try {
            $media = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $request->user()->id, 'path' => $path, 'name' => ($data['copy'] ?? false) ? Str::limit(pathinfo($source->name, PATHINFO_FILENAME), 140, '').' — copy.'.$extension : $source->name, 'mime' => $source->mime, 'size' => $source->size, 'alt' => $source->alt, 'album_id' => $source->album_id, 'source_media_id' => $source->id, 'crop' => $source->crop, 'version' => 1]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return response()->json($library->describe($media, $page));
    }

    public function rename(Request $request, HubPage $page, HubMedia $media, HubAccess $access, HubMediaLibrary $library)
    {
        $access->assertEdit($request->user(), $page);
        abort_unless((int) $media->hub_page_id === (int) $page->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1f\/\\\\]/'], 'alt' => 'nullable|string|max:300', 'album_id' => 'nullable|integer|min:1', 'version' => 'required|integer|min:1']);
        $media = $library->update($request->user(), $page, $media, $data);

        return response()->json($library->describe($media, $page));
    }

    public function store(Request $request, HubPage $page, HubAccess $access, HubMediaLibrary $library)
    {
        $access->assertEdit($request->user(), $page);
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:10240']);
        $file = $request->file('file');
        $path = $file->store('company-hub/'.$page->id, 'private');
        try {
            $media = HubMedia::create([
                'hub_page_id' => $page->id, 'uploaded_by' => $request->user()->id,
                'path' => $path, 'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                'alt' => '', 'version' => 1,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        return response()->json($library->describe($media, $page));
    }

    public function show(Request $request, HubMedia $media, HubAccess $access)
    {
        $page = HubPage::findOrFail($media->hub_page_id);
        $access->assertEnter($request->user());
        if (! $access->canEdit($request->user(), $page)) {
            $content = $access->assertRead($request->user(), $page)->content;
            $referenced = collect($content['sections'])->flatMap(fn ($s) => $s['blocks'])
                ->pluck('media_id')->push($content['cover_id'] ?? null)->filter()->map(fn ($id) => (int) $id);
            abort_unless($referenced->contains((int) $media->id), 404);
        }
        abort_unless(Storage::disk('private')->exists($media->path), 404);
        $inline = in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);

        return Storage::disk('private')->response($media->path, $media->name, [
            'Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}

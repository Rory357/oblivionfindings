<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HubMediaController extends Controller
{
    private function filters(Request $request): array
    {
        return $request->validate(['library' => 'nullable|in:page,all', 'state' => 'nullable|in:active,removed', 'q' => 'nullable|string|max:160', 'album_id' => 'nullable|integer|min:1', 'type' => 'nullable|in:image,file,all', 'cursor_page' => 'nullable|integer|min:1|max:10000']);
    }

    public function index(Request $request, HubPage $page, HubMediaLibrary $library)
    {
        return response()->json($library->listing($request->user(), $page, $this->filters($request)))->header('Cache-Control', 'private, no-store');
    }

    public function details(Request $request, HubPage $page, HubMedia $media, HubMediaLibrary $library)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1|max:2147483647']);
        $page = HubPage::findOrFail($page->id);
        $media = HubMedia::findOrFail($media->id);
        $actor = $library->editorFor($request->user(), $page, $media);
        abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reopen this file before continuing.');

        return response()->json(['actor_id' => (int) $actor->id, 'page_id' => (int) $page->id,
            'media' => $library->describe($media, $page)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function workspace(Request $request, HubMediaLibrary $library)
    {
        return response()->json($library->listing($request->user(), null, $this->filters($request)))->header('Cache-Control', 'private, no-store');
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
        $library->editorFor($request->user(), $page);
        $data = $request->validate(['media_id' => 'required|integer', 'version' => 'nullable|integer|min:1', 'copy' => 'nullable|boolean']);
        $source = HubMedia::findOrFail($data['media_id']);
        $media = $library->reuse($request->user(), $page, $source, isset($data['version']) ? (int) $data['version'] : null, $data['copy'] ?? false);

        return response()->json($library->describe($media, $page));
    }

    public function rename(Request $request, HubPage $page, HubMedia $media, HubAccess $access, HubMediaLibrary $library)
    {
        $library->editorFor($request->user(), $page, $media);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1f\/\\\\]/'],
            'alt' => 'nullable|string|max:300', 'album_id' => 'nullable|integer|min:1', 'version' => 'required|integer|min:1',
            'actor_id' => 'sometimes|integer|min:1', 'caption' => 'sometimes|nullable|string|max:2000',
            'focal_x' => 'sometimes|required|integer|min:0|max:100', 'focal_y' => 'sometimes|required|integer|min:0|max:100',
        ]);
        $media = $library->update($request->user(), $page, $media, $data);

        return response()->json($library->describe($media, $page));
    }

    public function store(Request $request, HubPage $page, HubAccess $access, HubMediaLibrary $library)
    {
        $library->editorFor($request->user(), $page);
        $request->validate(['file' => 'required|file|max:10240']);
        $media = $library->upload($request->user(), $page, $request->file('file'));

        return response()->json($library->describe($media, $page));
    }

    public function availability(Request $request, HubPage $page, HubMedia $media, HubMediaLibrary $library)
    {
        $library->editorFor($request->user(), $page, $media);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'action' => 'required|in:remove,restore']);
        $media = $library->availability($request->user(), $page, $media, $data);

        return response()->json($library->describe($media, $page->fresh()))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, HubMedia $media, HubMediaLibrary $library)
    {
        $media = $library->fileFor($request->user(), $media);
        abort_unless(Storage::disk('private')->exists($media->path), 404);
        $inline = ! $request->boolean('download') && in_array($media->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);

        return Storage::disk('private')->response($media->path, $media->name, [
            'Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function preview(Request $request, HubMedia $media, HubMediaLibrary $library)
    {
        $media = $library->fileFor($request->user(), $media);
        abort_unless(HubMediaLibrary::previewable($media->mime) && Storage::disk('private')->exists($media->path), 404);

        return Storage::disk('private')->response($media->path, $media->name, [
            'Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox",
        ], 'inline');
    }
}

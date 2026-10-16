<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubDocuments;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HubDocumentController extends Controller
{
    public function options(Request $request, HubDocuments $documents)
    {
        return response()->json($documents->options($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, HubDocuments $documents)
    {
        $data = $request->validate([...$this->detailRules(), 'actor_id' => 'required|integer|min:1|max:2147483647', 'request_id' => 'required|uuid',
            'title' => 'required|string|max:160', 'description' => 'sometimes|nullable|string|max:2000', 'file' => 'required|file|max:10240',
            'user_id' => 'prohibited', 'content' => 'prohibited', 'published_at' => 'prohibited']);
        $receipt = $documents->create($request->user(), $data, $request->file('file'));

        return response()->json($receipt, $receipt['replayed'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, string $requestId, HubDocuments $documents)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1|max:2147483647']);

        return response()->json($documents->uploadReceipt($request->user(), $requestId, (int) $data['actor_id']))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, HubMedia $media, HubDocuments $documents, HubFeed $feed)
    {
        $data = $request->validate(['edition' => ['sometimes', Rule::in(['draft', 'published'])],
            'actor_id' => 'required_if:edition,draft|integer|min:1|max:2147483647',
            'page_version' => 'required_if:edition,draft|integer|min:1|max:2147483647']);
        $document = $documents->read($request->user(), $media, $data['edition'] ?? 'published', isset($data['actor_id'])
            ? ['actor_id' => (int) $data['actor_id'], 'page_version' => (int) ($data['page_version'] ?? 0)] : null);

        return Inertia::render('company-hub/document', [...$feed->forUser($request->user()), 'view' => 'resources',
            'selectedPage' => null, 'document' => $document]);
    }

    public function context(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents)
    {
        $data = $request->validate(['actor_id' => 'sometimes|integer|min:1|max:2147483647']);

        return response()->json($documents->context($request->user(), $page, $media, isset($data['actor_id']) ? (int) $data['actor_id'] : null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function edit(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents, HubFeed $feed)
    {
        $data = $request->validate(['actor_id' => 'sometimes|integer|min:1|max:2147483647']);
        $context = $documents->context($request->user(), $page, $media, isset($data['actor_id']) ? (int) $data['actor_id'] : null);

        return Inertia::render('company-hub/document-editor', [...$feed->forUser($request->user()), 'view' => 'resources',
            'selectedPage' => null, 'documentContext' => $context]);
    }

    public function update(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents)
    {
        $data = $request->validate([...$this->versionRules(), ...$this->detailRules(),
            'title' => 'sometimes|required|string|max:160', 'description' => 'sometimes|nullable|string|max:2000',
            'user_id' => 'prohibited', 'content' => 'prohibited', 'file' => 'prohibited', 'published_at' => 'prohibited']);

        return response()->json($documents->update($request->user(), $page, $media, $data))->header('Cache-Control', 'private, no-store');
    }

    public function transition(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents)
    {
        $data = $request->validate([...$this->versionRules(), 'action' => ['required', Rule::in(['submit', 'publish'])],
            'publish_at' => 'nullable|date|prohibited_unless:action,publish', 'expires_at' => 'nullable|date|prohibited_unless:action,publish',
            'user_id' => 'prohibited', 'owner_id' => 'prohibited', 'content' => 'prohibited', 'published_at' => 'prohibited']);

        return response()->json($documents->transition($request->user(), $page, $media, $data))->header('Cache-Control', 'private, no-store');
    }

    private function versionRules(): array
    {
        return ['actor_id' => 'required|integer|min:1|max:2147483647', 'page_version' => 'required|integer|min:1|max:2147483647',
            'document_version' => 'required|integer|min:1|max:2147483647'];
    }

    private function detailRules(): array
    {
        return ['category' => 'sometimes|required|string|max:60', 'resource_type' => ['sometimes', 'nullable', Rule::in(HubContent::RESOURCE_TYPES)],
            'collection_id' => 'sometimes|nullable|integer|min:1|max:2147483647', 'owner_id' => 'sometimes|integer|min:1|max:2147483647',
            'review_on' => 'sometimes|nullable|date_format:Y-m-d', 'requires_approval' => 'sometimes|boolean',
            'team_ids' => 'sometimes|array|max:100', 'team_ids.*' => 'integer|min:1|max:2147483647|distinct',
            'audience' => 'sometimes|array:type,ids', 'audience.type' => ['required_with:audience', Rule::in(['all', 'sites', 'departments'])],
            'audience.ids' => 'present_with:audience|array|max:100', 'audience.ids.*' => 'integer|min:1|max:2147483647|distinct'];
    }

    public function replace(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1|max:2147483647',
            'page_version' => 'required|integer|min:1|max:2147483647', 'document_version' => 'required|integer|min:1|max:2147483647',
            'file' => 'required|file|max:10240', 'user_id' => 'prohibited', 'content' => 'prohibited']);

        return response()->json($documents->replace($request->user(), $page, $media, $data, $request->file('file')))
            ->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, HubPage $page, HubMedia $media, HubDocuments $documents)
    {
        $data = $request->validate(['actor_id' => 'required|integer|min:1|max:2147483647',
            'page_version' => 'required|integer|min:1|max:2147483647', 'document_version' => 'required|integer|min:1|max:2147483647',
            'history_id' => 'required|integer|min:1|max:2147483647', 'user_id' => 'prohibited', 'content' => 'prohibited']);

        return response()->json($documents->replace($request->user(), $page, $media, $data))->header('Cache-Control', 'private, no-store');
    }
}

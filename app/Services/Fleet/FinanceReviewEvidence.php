<?php

namespace App\Services\Fleet;

use App\Models\AssetDocument;
use App\Models\FleetFinanceReviewRequest;

final class FinanceReviewEvidence
{
    public function manifest(FleetFinanceReviewRequest $request): array
    {
        $files = AssetDocument::query()->where('asset_id', $request->asset_id)
            ->where('source_type', 'finance_review_request')->where('source_id', $request->id)
            ->whereNull('archived_at')->orderBy('id')->get()
            ->groupBy(fn ($file) => $file->document_set_id ?: 'file-'.$file->id)
            ->flatMap(fn ($group) => $group->where('revision', $group->max('revision')));
        if ($request->existing_document_id) {
            $file = AssetDocument::query()->where('asset_id', $request->asset_id)->find($request->existing_document_id);
            if ($file) {
                $files->push($file);
            }
        }

        return $files->unique('id')->sortBy('id')->map(fn ($file) => $file->only([
            'id', 'document_set_id', 'revision', 'sha256', 'state', 'scan_disposition', 'size_bytes', 'original_name', 'archived_at',
        ]))->values()->all();
    }

    public function token(FleetFinanceReviewRequest $request): string
    {
        return hash_hmac('sha256', json_encode([
            'id' => $request->id, 'version' => $request->lock_version, 'files' => $this->manifest($request),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function assertReady(FleetFinanceReviewRequest $request): array
    {
        $manifest = $this->manifest($request);
        abort_if($request->existing_document_id && ! collect($manifest)->contains('id', $request->existing_document_id),
            409, 'The selected vehicle document is no longer available. Restore it before submitting or deciding this request.');
        $ownedCount = count(array_filter($manifest, fn ($file) => (int) $file['id'] !== (int) $request->existing_document_id));
        abort_unless($ownedCount >= $request->expected_file_count, 409, 'Some selected files have not finished uploading. Add them before submitting.');
        foreach ($manifest as $file) {
            abort_unless($file['state'] === 'available' && $file['scan_disposition'] === 'clean' && $file['sha256'] && ! $file['archived_at'],
                409, 'Every selected file must pass its virus check before this evidence can be submitted or decided.');
        }

        return $manifest;
    }

    /** Called while the owning asset is locked, before changing request-owned evidence. */
    public function changing(int $assetId, ?string $source, ?int $id): void
    {
        if ($source !== 'finance_review_request') {
            return;
        }
        $request = FleetFinanceReviewRequest::query()->where('asset_id', $assetId)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_if(in_array($request->status, ['resolved', 'declined'], true), 409, 'Evidence used for a Finance decision is retained. Raise a new request for new evidence.');
        $request->forceFill(['status' => $request->status === 'changes_requested' ? 'changes_requested' : 'preparing',
            'evidence_ready_at' => null, 'lock_version' => $request->lock_version + 1])->save();
    }
}

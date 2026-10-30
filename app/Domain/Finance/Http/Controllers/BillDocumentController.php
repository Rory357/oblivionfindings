<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillDocument;
use App\Domain\Finance\Services\BillDocumentService;
use App\Domain\Finance\Services\BillWorkContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class BillDocumentController extends Controller
{
    public function __construct(private readonly BillDocumentService $documents) {}

    public function store(Request $request, FinBill $bill)
    {
        $request->validate(['file' => ['required', 'file']]);

        return response()->json(['document' => $this->documents->upload($request->user(), $bill, $request->file('file'), (string) $request->header('Idempotency-Key'))]);
    }

    public function retry(Request $request, FinBill $bill, FinBillDocument $document)
    {
        return response()->json(['document' => $this->documents->retry($request->user(), $bill, $document)]);
    }

    public function withdraw(Request $request, FinBill $bill, FinBillDocument $document)
    {
        $this->documents->withdraw($request->user(), $bill, $document);

        return response()->json(['document' => $document->fresh()]);
    }

    public function show(Request $request, FinBill $bill, FinBillDocument $document)
    {
        return $this->documents->open($request->user(), $bill, $document, $request->boolean('inline'));
    }

    public function workEvidence(Request $request, FinBill $bill, int $document)
    {
        $this->authorize('view', $bill);
        $file = app(BillWorkContext::class)->documents($bill)->firstWhere('id', $document);
        abort_unless($file && ! $file->archived_at && $file->isOpenable() && $file->scan_disposition === 'clean', 404);
        $disk = Storage::disk($file->storage_disk ?: 'private');
        abort_unless($disk->exists($file->storage_path), 404);

        return $disk->response($file->storage_path, $file->original_name, [
            'Content-Type' => $file->detected_mime ?: $file->mime_type,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'",
        ], $request->boolean('inline') ? 'inline' : 'attachment');
    }
}

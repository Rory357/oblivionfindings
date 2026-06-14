<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Services\ESignatureService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ESignatureController extends Controller
{
    public function __construct(
        private readonly ESignatureService $signatureService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Pending — list documents awaiting user's signature */
    /* ------------------------------------------------------------------ */

    public function pending(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $signatures = $this->signatureService->getPendingForUser($user->id);

        $mapped = $signatures->map(fn ($sig) => [
            'id' => $sig->id,
            'document_title' => $sig->document?->title ?? 'Unknown Document',
            'document_category' => $sig->document?->category,
            'requested_by' => $sig->requestedBy?->name ?? 'Unknown',
            'requested_at' => $sig->requested_at?->toDateTimeString(),
            'status' => $sig->status,
        ]);

        return Inertia::render('hr/signatures/pending', [
            'signatures' => $mapped,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — document view + signature pad */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($user && $signature->signer_user_id === $user->id, 403);

        $signature->load(['document', 'requestedBy:id,name']);

        return Inertia::render('hr/signatures/sign', [
            'signature' => [
                'id' => $signature->id,
                'status' => $signature->status,
                'document_title' => $signature->document?->title ?? 'Unknown Document',
                'document_category' => $signature->document?->category,
                'document_original_name' => $signature->document?->original_name,
                'document_download_url' => $signature->document
                    ? route('hr.signatures.document', $signature)
                    : null,
                'requested_by' => $signature->requestedBy?->name ?? 'Unknown',
                'requested_at' => $signature->requested_at?->toDateTimeString(),
                'signed_at' => $signature->signed_at?->toDateTimeString(),
                'declined_reason' => $signature->declined_reason,
            ],
            'can' => [
                'sign' => $signature->status === 'pending',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Download document — signer-scoped (so the signer can review it)    */
    /* ------------------------------------------------------------------ */

    public function downloadDocument(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        // The signer is authorised to view the document they were asked to sign,
        // regardless of whether they hold hr.documents.view.
        abort_unless($user && $signature->signer_user_id === $user->id, 403);

        $document = $signature->document;
        abort_unless($document, 404, 'Document not found.');

        abort_unless(
            Storage::disk($document->storage_disk)->exists($document->storage_path),
            404,
            'Document file is missing from storage.',
        );

        $filename = $document->original_name ?: basename($document->storage_path);

        return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);
    }

    /* ------------------------------------------------------------------ */
    /*  Sign — capture signature */
    /* ------------------------------------------------------------------ */

    public function sign(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($user && $signature->signer_user_id === $user->id, 403);

        $validated = $request->validate([
            'signature_data' => ['required', 'string'],
        ]);

        try {
            $this->signatureService->sign($signature, $validated['signature_data'], $request);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('hr.signatures.pending')->with('success', 'Document signed successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Decline — decline to sign */
    /* ------------------------------------------------------------------ */

    public function decline(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($user && $signature->signer_user_id === $user->id, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->signatureService->decline($signature, $validated['reason']);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('hr.signatures.pending')->with('success', 'Signature request declined.');
    }

    /* ------------------------------------------------------------------ */
    /*  Request — HR sends document for signature */
    /* ------------------------------------------------------------------ */

    public function request(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.signatures.manage') || $user->canDo('hr.documents.manage')), 403);

        $validated = $request->validate([
            'document_id' => ['required', 'integer', 'exists:hr_documents,id'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id'],
        ]);

        $document = HrDocument::findOrFail($validated['document_id']);

        $this->signatureService->bulkRequestSignatures(
            $document,
            $validated['user_ids'],
            $user->id,
        );

        return redirect()->back()->with('success', 'Signature requests sent.');
    }
}

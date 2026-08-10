<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Services\ESignatureService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrDocumentAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ESignatureController extends Controller
{
    public function __construct(
        private readonly ESignatureService $signatureService,
        private readonly HrDocumentAccessService $documentAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Pending — list documents awaiting user's signature */
    /* ------------------------------------------------------------------ */

    public function pending(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $signatures = $this->signatureService->getPendingForUser($user);

        $mapped = $signatures->map(fn ($sig) => [
            'id' => $sig->id,
            'document_title' => $sig->document?->title ?? 'Unknown Document',
            'document_category' => $sig->document?->category,
            'requested_by' => $sig->requestedBy?->name ?? 'HR team',
            'requested_at' => $sig->requested_at?->toIso8601String(),
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
        abort_unless($user, 403);
        $signature = $this->signatureForSigner($user, $signature)
            ->load(['document', 'requestedBy:id,name']);
        $this->signatureService->concealUnavailableRequesters(collect([$signature]), $user);

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
                'requested_by' => $signature->requestedBy?->name ?? 'HR team',
                'requested_at' => $signature->requested_at?->toIso8601String(),
                'signed_at' => $signature->signed_at?->toIso8601String(),
                'declined_reason' => $signature->declined_reason,
            ],
            'can' => [
                'sign' => $signature->status === 'pending',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Download document — signer-scoped (so the signer can review it) */
    /* ------------------------------------------------------------------ */

    public function downloadDocument(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        // The signer is authorised to view the document they were asked to sign,
        // regardless of whether they hold hr.documents.view.
        abort_unless($user, 403);
        $signature = $this->signatureForSigner($user, $signature);

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
        abort_unless($user, 403);
        $signature = $this->signatureForSigner($user, $signature);

        $validated = $request->validate([
            'signature_data' => ['required', 'string', 'max:500000'],
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
        abort_unless($user, 403);
        $signature = $this->signatureForSigner($user, $signature);

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
            'document_id' => ['required', 'integer'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct'],
            'order' => ['nullable', 'in:parallel,sequential'],
            'due_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $document = $this->documentAccess->readableDocument($user, (int) $validated['document_id']);

        try {
            $this->signatureService->bulkRequestSignatures(
                $document,
                $validated['user_ids'],
                $user->id,
                [
                    'order' => $validated['order'] ?? 'parallel',
                    'due_at' => $validated['due_at'] ?? null,
                    'message' => $validated['message'] ?? null,
                ],
            );
        } catch (\LogicException) {
            throw ValidationException::withMessages([
                'user_ids' => 'The selected signers are not available.',
            ]);
        }

        return redirect()->back()->with('success', 'Signature requests sent.');
    }

    /* ------------------------------------------------------------------ */
    /*  Sender-side actions (nudge / resend / cancel) */
    /* ------------------------------------------------------------------ */

    public function nudge(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.signatures.manage') || $user->canDo('hr.documents.manage')), 403);
        $signature = $this->managedSignature($user, $signature);

        try {
            $this->signatureService->nudge($signature);
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Reminder sent to signer.');
    }

    public function resend(Request $request, HrDocumentSignature $signature)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.signatures.manage') || $user->canDo('hr.documents.manage')), 403);
        $signature = $this->managedSignature($user, $signature);

        try {
            $this->signatureService->resend($signature);
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Signature request resent.');
    }

    public function cancel(Request $request, HrDocument $document)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.signatures.manage') || $user->canDo('hr.documents.manage')), 403);
        $document = $this->documentAccess->readableDocument($user, $document);
        $pendingSignatures = $document->signatures()->where('status', 'pending')->get();
        abort_if(
            $pendingSignatures->contains(
                fn (HrDocumentSignature $signature) => ! $this->signatureService
                    ->participantIsAvailable($document, (int) $signature->signer_user_id, $user),
            ),
            404,
        );

        $count = $this->signatureService->cancelForDocument($document);

        return redirect()->back()->with('success', $count.' outstanding request(s) cancelled.');
    }

    private function signatureForSigner(User $signer, HrDocumentSignature $signature): HrDocumentSignature
    {
        abort_unless($this->currentStaff->isCurrent($signer), 404);

        $signature = HrDocumentSignature::query()
            ->whereKey($signature->getKey())
            ->where('signer_user_id', $signer->id)
            ->firstOrFail();
        $document = $this->documentAccess->siteDocument($signer, (int) $signature->document_id);
        $signature->setRelation('document', $document);

        return $signature;
    }

    private function managedSignature(User $manager, HrDocumentSignature $signature): HrDocumentSignature
    {
        $signature = HrDocumentSignature::query()->findOrFail($signature->getKey());
        $document = $this->documentAccess->readableDocument($manager, (int) $signature->document_id);
        abort_unless(
            $this->signatureService->participantIsAvailable(
                $document,
                (int) $signature->signer_user_id,
                $manager,
            ),
            404,
        );
        $signature->setRelation('document', $document);

        return $signature;
    }
}

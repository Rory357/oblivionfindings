<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Domain\Hr\Notifications\SignatureReminderNotification;
use App\Domain\Hr\Notifications\SignatureRequestedNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ESignatureService
{
    public function __construct(
        private readonly HrDocumentMergeService $mergeService,
    ) {}

    /**
     * Request a signature from a specific user on a document.
     */
    public function requestSignature(HrDocument $document, int $signerUserId, int $requestedBy): HrDocumentSignature
    {
        return DB::transaction(function () use ($document, $signerUserId, $requestedBy) {
            return HrDocumentSignature::create([
                'tenant_id' => $document->tenant_id,
                'document_id' => $document->id,
                'signer_user_id' => $signerUserId,
                'status' => 'pending',
                'requested_by' => $requestedBy,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Capture a signature on a document.
     *
     * Records the base64 signature data along with the signer's IP address and
     * user agent for audit purposes. When the final required signature lands the
     * document is finalised: an audit-grade signed PDF (certificate) is rendered.
     *
     * @param  string  $signatureData  Base64-encoded PNG (drawn) or typed name rendered to an image
     *
     * @throws \LogicException If signature is not pending
     */
    public function sign(HrDocumentSignature $signature, string $signatureData, Request $request): HrDocumentSignature
    {
        if ($signature->status !== 'pending') {
            throw new \LogicException("Cannot sign a '{$signature->status}' signature request.");
        }

        return DB::transaction(function () use ($signature, $signatureData, $request) {
            $signature->update([
                'signature_data' => $signatureData,
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'signed',
            ]);

            $fresh = $signature->fresh();
            $this->finaliseIfComplete($fresh->document);

            return $fresh;
        });
    }

    /**
     * Decline to sign a document.
     *
     * @throws \LogicException If signature is not pending
     */
    public function decline(HrDocumentSignature $signature, string $reason): HrDocumentSignature
    {
        if ($signature->status !== 'pending') {
            throw new \LogicException("Cannot decline a '{$signature->status}' signature request.");
        }

        return DB::transaction(function () use ($signature, $reason) {
            $signature->update([
                'status' => 'declined',
                'declined_reason' => $reason,
            ]);

            return $signature->fresh();
        });
    }

    /**
     * Send a document for signature to multiple users at once.
     *
     * @param  list<int>  $userIds
     * @param  array{order?: string, due_at?: string|null, message?: string|null}  $options
     * @return list<HrDocumentSignature>
     */
    public function bulkRequestSignatures(HrDocument $document, array $userIds, int $requestedBy, array $options = []): array
    {
        $signatures = [];
        $order = ($options['order'] ?? 'parallel') === 'sequential' ? 'sequential' : 'parallel';
        $dueAt = $options['due_at'] ?? null;
        $message = $options['message'] ?? null;

        DB::transaction(function () use ($document, $userIds, $requestedBy, $order, $dueAt, $message, &$signatures) {
            $index = 0;
            foreach ($userIds as $userId) {
                $signatures[] = HrDocumentSignature::create([
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'signer_user_id' => $userId,
                    'status' => 'pending',
                    'signing_order' => $order,
                    'order_index' => $index++,
                    'requested_by' => $requestedBy,
                    'requested_at' => now(),
                    'due_at' => $dueAt,
                    'message' => $message,
                ]);
            }

            $document->update([
                'sent_to_employee' => true,
                'sent_at' => now(),
            ]);
        });

        // After commit: tell each signer a document is waiting for them.
        // Best-effort — a notification hiccup never rolls back the requests.
        $signers = User::query()
            ->whereIn('id', collect($signatures)->pluck('signer_user_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($signatures as $signature) {
            $signer = $signers->get($signature->signer_user_id);
            if (! $signer) {
                continue;
            }

            try {
                $signer->notify(new SignatureRequestedNotification([
                    'signature_id' => $signature->id,
                    'document_title' => $document->title ?? 'a document',
                    'due_at' => $signature->due_at?->toDateString(),
                    'message' => $message,
                ]));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send signature requested notification', [
                    'signature_id' => $signature->id,
                    'signer_user_id' => $signature->signer_user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $signatures;
    }

    /**
     * Get all pending signature requests for a user (signer side).
     */
    public function getPendingForUser(int $userId): Collection
    {
        return HrDocumentSignature::forSigner($userId)
            ->pending()
            ->with(['document', 'requestedBy:id,name'])
            ->orderByDesc('requested_at')
            ->get();
    }

    /**
     * Nudge a pending signature — stamp the reminder time so the inbox shows it
     * and reminder jobs don't double-send. (Notification delivery is handled by
     * the caller / reminder job.)
     */
    public function nudge(HrDocumentSignature $signature): void
    {
        if ($signature->status !== 'pending') {
            return;
        }

        $signature->update(['reminder_sent_at' => now()]);

        // Actually deliver the reminder. Previously nudge() only stamped the
        // timestamp, so the controller's "Reminder sent to signer" flash was a
        // no-op and SignatureReminderNotification had no caller at all.
        $signer = $signature->signer ?? User::find($signature->signer_user_id);
        if (! $signer) {
            return;
        }

        try {
            $signer->notify(new SignatureReminderNotification([
                'signature_id' => $signature->id,
                'document_title' => $signature->document?->title ?? 'a document',
                'due_at' => $signature->due_at?->toDateString(),
            ]));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send signature reminder notification', [
                'signature_id' => $signature->id,
                'signer_user_id' => $signature->signer_user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Resend a declined request — reopen it as pending so the signer can act
     * again. Clears the prior decline reason and stamps a fresh request time.
     */
    public function resend(HrDocumentSignature $signature): HrDocumentSignature
    {
        $signature->update([
            'status' => 'pending',
            'declined_reason' => null,
            'signature_data' => null,
            'signed_at' => null,
            'requested_at' => now(),
            'reminder_sent_at' => null,
        ]);

        $fresh = $signature->fresh();

        // Re-notify the signer that the document is waiting again. resend() was
        // previously a silent status flip, so the "Signature request resent"
        // flash reached no one.
        $signer = $fresh->signer ?? User::find($fresh->signer_user_id);
        if ($signer) {
            try {
                $signer->notify(new SignatureRequestedNotification([
                    'signature_id' => $fresh->id,
                    'document_title' => $fresh->document?->title ?? 'a document',
                    'due_at' => $fresh->due_at?->toDateString(),
                    'message' => $fresh->message,
                ]));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send signature resend notification', [
                    'signature_id' => $fresh->id,
                    'signer_user_id' => $fresh->signer_user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $fresh;
    }

    /**
     * Cancel all outstanding (pending) signature requests for a document.
     *
     * @return int Number of requests cancelled
     */
    public function cancelForDocument(HrDocument $document): int
    {
        return $document->signatures()->where('status', 'pending')->delete();
    }

    /**
     * If every signature request on the document is signed, finalise it:
     * render an audit-grade signed PDF (certificate page) and flag the document.
     */
    public function finaliseIfComplete(HrDocument $document): void
    {
        $signatures = $document->signatures()->get();
        if ($signatures->isEmpty()) {
            return;
        }
        if ($signatures->contains(fn (HrDocumentSignature $s) => $s->status !== 'signed')) {
            return;
        }

        $hash = $this->documentHash($document);
        $html = $this->buildCertificateHtml($document, $signatures, $hash);
        $pdf = $this->mergeService->renderPdf($html);

        $path = "hr-documents/{$document->tenant_id}/{$document->employee_profile_id}/signed_{$document->id}_" . now()->format('Ymd_His') . '.pdf';
        Storage::disk('private')->put($path, $pdf);

        $document->update([
            'signed_by_employee' => true,
            'signed_at' => now(),
            'signed_document_path' => $path,
        ]);
    }

    private function documentHash(HrDocument $document): string
    {
        try {
            if ($document->storage_path && Storage::disk($document->storage_disk ?? 'private')->exists($document->storage_path)) {
                return hash('sha256', (string) Storage::disk($document->storage_disk ?? 'private')->get($document->storage_path));
            }
        } catch (\Throwable) {
            // fall through
        }

        return hash('sha256', (string) $document->id . '|' . (string) $document->title);
    }

    /**
     * @param  Collection<int, HrDocumentSignature>  $signatures
     */
    private function buildCertificateHtml(HrDocument $document, Collection $signatures, string $hash): string
    {
        $title = e($document->title);
        $original = e($document->original_name ?: basename((string) $document->storage_path));
        $generatedAt = now()->format('d F Y H:i');

        $rows = $signatures->map(function (HrDocumentSignature $s) {
            $name = e($s->signer?->name ?? ('User #' . $s->signer_user_id));
            $when = $s->signed_at?->format('d M Y H:i') ?? '—';
            $ip = e($s->ip_address ?? '—');
            $ua = e(\Illuminate\Support\Str::limit((string) $s->user_agent, 80));
            $img = '';
            $data = (string) $s->signature_data;
            if (str_starts_with($data, 'data:image')) {
                $safe = e($data);
                $img = "<img src=\"{$safe}\" style=\"max-height:46px; max-width:200px;\" alt=\"signature\">";
            } elseif ($data !== '') {
                $img = '<span style="font-family: DejaVu Sans; font-style: italic; font-size: 18px;">' . e($data) . '</span>';
            }

            return "<tr>
                <td style=\"padding:8px 10px; border-bottom:1px solid #e6e2ee;\"><strong>{$name}</strong><br><span style=\"color:#6b6477; font-size:10px;\">{$ip}</span></td>
                <td style=\"padding:8px 10px; border-bottom:1px solid #e6e2ee;\">{$img}</td>
                <td style=\"padding:8px 10px; border-bottom:1px solid #e6e2ee; white-space:nowrap;\">{$when}</td>
            </tr>";
        })->implode('');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><style>
        @page { margin: 24mm 20mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1a1523; font-size: 12px; }
        h1 { font-size: 19px; margin: 0 0 4px; }
        .muted { color: #6b6477; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #6b6477; border-bottom: 2px solid #1a1523; }
        .meta { margin-top: 18px; padding: 12px 14px; background: #f6f4fb; border-radius: 8px; font-size: 11px; line-height: 1.7; }
        .hash { font-family: DejaVu Sans Mono, monospace; word-break: break-all; font-size: 10px; }
        </style></head><body>
        <h1>Certificate of Completion</h1>
        <div class="muted">Electronic signature audit record</div>
        <div class="meta">
            <div><strong>Document:</strong> {$title}</div>
            <div><strong>File:</strong> {$original}</div>
            <div><strong>Completed:</strong> {$generatedAt}</div>
            <div><strong>Document hash (SHA-256):</strong> <span class="hash">{$hash}</span></div>
        </div>
        <table>
            <thead><tr><th>Signer</th><th>Signature</th><th>Signed at</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        <p class="muted" style="margin-top:22px;">This certificate was generated automatically. Each signature above was captured with the signer's IP address and timestamp. The document hash binds this record to the file content at the time of signing.</p>
        </body></html>
        HTML;
    }
}

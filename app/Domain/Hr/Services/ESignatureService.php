<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentSignature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ESignatureService
{
    /**
     * Request a signature from a specific user on a document.
     *
     * @param  HrDocument  $document
     * @param  int         $signerUserId
     * @param  int         $requestedBy
     * @return HrDocumentSignature
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
     * Records the base64 signature data along with the signer's IP
     * address and user agent for audit purposes.
     *
     * @param  HrDocumentSignature  $signature
     * @param  string               $signatureData  Base64-encoded PNG/SVG
     * @param  Request              $request
     * @return HrDocumentSignature
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

            return $signature->fresh();
        });
    }

    /**
     * Decline to sign a document.
     *
     * @param  HrDocumentSignature  $signature
     * @param  string               $reason
     * @return HrDocumentSignature
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
     * @param  HrDocument  $document
     * @param  array       $userIds
     * @param  int         $requestedBy
     * @return array
     */
    public function bulkRequestSignatures(HrDocument $document, array $userIds, int $requestedBy): array
    {
        $signatures = [];

        DB::transaction(function () use ($document, $userIds, $requestedBy, &$signatures) {
            foreach ($userIds as $userId) {
                $signatures[] = HrDocumentSignature::create([
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'signer_user_id' => $userId,
                    'status' => 'pending',
                    'requested_by' => $requestedBy,
                    'requested_at' => now(),
                ]);
            }
        });

        return $signatures;
    }

    /**
     * Get all pending signature requests for a user.
     *
     * @param  int  $userId
     * @return Collection
     */
    public function getPendingForUser(int $userId): Collection
    {
        return HrDocumentSignature::forSigner($userId)
            ->pending()
            ->with(['document', 'requestedBy:id,name'])
            ->orderByDesc('requested_at')
            ->get();
    }
}

<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectorEnrollmentController extends Controller
{
    public function __invoke(Request $request, CollectorEnrollmentService $enrollments): JsonResponse
    {
        $collectorUuid = $request->input('collector_id');
        $encodedPublicKey = $request->input('collector_public_key');
        $plainToken = $request->bearerToken();
        $publicKey = is_string($encodedPublicKey) ? base64_decode($encodedPublicKey, true) : false;
        if (! is_string($collectorUuid) || ! is_string($plainToken) || ! is_string($publicKey)) {
            return response()->json(['message' => 'Collector enrolment failed.'], 422);
        }

        try {
            $result = $enrollments->enrol($plainToken, $collectorUuid, $publicKey);
        } catch (DomainException) {
            return response()->json(['message' => 'Collector enrolment failed.'], 422);
        }

        return response()->json([
            'collector_id' => $result->collector->collector_uuid,
            'site_id' => (int) $result->collector->site_id,
            'central_signing_public_key' => base64_encode($result->centralSigningPublicKey),
            'client_certificate' => $result->certificate->certificatePem,
            'client_private_key' => $result->certificate->privateKeyPem,
            'client_certificate_fingerprint' => $result->certificate->fingerprint,
            'client_certificate_expires_at' => $result->certificate->expiresAt->toISOString(),
            'acknowledged_source_sequence' => (int) $result->collector->acknowledged_source_sequence,
        ], 201);
    }
}

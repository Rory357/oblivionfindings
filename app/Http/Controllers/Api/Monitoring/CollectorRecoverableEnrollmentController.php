<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Domain\Monitoring\Services\CollectorRecoverableEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Monitoring\StrictJsonObjectDecoder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CollectorRecoverableEnrollmentController extends Controller
{
    public function __invoke(Request $request, CollectorRecoverableEnrollment $enrollments): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
        if (! $request->isSecure()) {
            return response()->json(['message' => 'Secure collector enrolment is required.'], 400, $headers);
        }
        try {
            $raw = $request->getContent();
            if (strlen($raw) > 16_384) {
                throw new DomainException;
            }
            $input = (new StrictJsonObjectDecoder)->decode($raw, 4);
            $keys = ['collector_id', 'collector_public_key', 'proof'];
            if (count($input) !== count($keys) || array_diff(array_keys($input), $keys) !== []
                || ! is_string($input['collector_id']) || ! is_string($input['collector_public_key']) || ! is_string($input['proof'])) {
                throw new DomainException;
            }
            $public = base64_decode($input['collector_public_key'], true);
            $proof = base64_decode($input['proof'], true);
            $token = $request->bearerToken();
            if (! is_string($public) || base64_encode($public) !== $input['collector_public_key']
                || ! is_string($proof) || base64_encode($proof) !== $input['proof'] || ! is_string($token)) {
                throw new DomainException;
            }
            $result = $enrollments->enrol($token, $input['collector_id'], $public, $proof);
        } catch (DomainException|InvalidArgumentException) {
            return response()->json(['message' => 'Collector enrolment failed.'], 422, $headers);
        }

        return response()->json($result, 201, $headers);
    }
}

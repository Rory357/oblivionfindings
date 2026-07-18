<?php

namespace App\Http\Middleware;

use App\Models\ItServiceIdentity;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateItServiceIdentity
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $credential = $this->parseCredential($request->bearerToken());
        if ($credential === null) {
            return $this->deny('credential_invalid', 'A valid IT service credential is required.');
        }

        [$publicId, $secret] = $credential;
        $identity = ItServiceIdentity::query()->with('actor')->where('public_id', $publicId)->first();
        if (! $identity || ! hash_equals((string) $identity->token_hash, hash('sha256', $secret))) {
            return $this->deny('credential_invalid', 'A valid IT service credential is required.');
        }

        $actor = $identity->actor;
        if (! $identity->isActive()
            || ! $actor
            || $actor->approved_at === null
            || (int) $actor->organization_id !== (int) $identity->tenant_id
            || ! $actor->canDo('it.manage')) {
            return $this->deny('identity_inactive', 'This IT service identity is inactive.');
        }

        if ($identity->require_signature) {
            $signatureFailure = $this->signatureFailure($request, $secret);
            if ($signatureFailure !== null) {
                return $signatureFailure;
            }
        }

        $rateKey = "it-service-api:{$identity->public_id}";
        $limit = max(1, (int) $identity->rate_limit_per_minute);
        if ($this->limiter->tooManyAttempts($rateKey, $limit)) {
            $retryAfter = $this->limiter->availableIn($rateKey);

            return response()->json([
                'message' => 'This service identity has exceeded its request limit.',
                'code' => 'rate_limited',
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }
        $this->limiter->hit($rateKey, 60);

        $identity->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('it_service_identity', $identity);
        $request->setUserResolver(fn () => $actor);

        return $next($request);
    }

    /** @return array{string, string}|null */
    private function parseCredential(?string $token): ?array
    {
        if (! is_string($token)
            || preg_match('/^ofi_([a-zA-Z0-9]{20})_([a-zA-Z0-9]{64})$/', $token, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    private function signatureFailure(Request $request, string $secret): ?JsonResponse
    {
        $timestamp = $request->header('X-OF-Timestamp');
        $provided = $request->header('X-OF-Signature');
        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($provided)) {
            return $this->deny('signature_required', 'A timestamped v1 request signature is required.');
        }
        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return $this->deny('signature_stale', 'The request signature timestamp is outside the five-minute window.');
        }

        $canonical = implode("\n", [
            $timestamp,
            strtoupper($request->method()),
            '/'.$request->path(),
            hash('sha256', $request->getContent()),
        ]);
        $expected = 'v1='.hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expected, $provided)) {
            return $this->deny('signature_invalid', 'The request signature is invalid.');
        }

        return null;
    }

    private function deny(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 401);
    }
}

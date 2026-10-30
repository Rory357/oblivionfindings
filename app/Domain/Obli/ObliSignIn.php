<?php

namespace App\Domain\Obli;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** One fixed native public client. These credentials work only on /api/obli routes. */
final class ObliSignIn
{
    public const CLIENT = 'obli-windows';

    public function __construct(private readonly ObliStaffAccess $access) {}

    public static function issuer(): string
    {
        return self::canonicalIssuer((string) config('endpoint_management.server_url'));
    }

    public static function canonicalIssuer(string $origin): string
    {
        $parts = parse_url(EndpointBootstrap::serverOrigin($origin));
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port;
    }

    public static function authorizationInput(array $input): array
    {
        $data = Validator::make($input, [
            'client_id' => ['required', Rule::in([self::CLIENT])],
            'response_type' => ['required', Rule::in(['code'])],
            'code_challenge_method' => ['required', Rule::in(['S256'])],
            'code_challenge' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'state' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43,128}\z/'],
            'redirect_uri' => ['required', 'string', 'max:120', function ($attribute, $value, $fail): void {
                if (! preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{3,4})/obli/callback\z~', $value, $matches)
                    || (int) $matches[1] < 1024 || (int) $matches[1] > 65535) {
                    $fail('Use the callback opened by Obli on this computer.');
                }
            }],
        ])->validate();

        return $data;
    }

    public function approve(User $actor, array $input): string
    {
        $data = self::authorizationInput($input);

        return DB::transaction(function () use ($actor, $data): string {
            $actor = $this->access->actor((int) $actor->id);
            $code = bin2hex(random_bytes(32));
            DB::table('obli_authorization_codes')->insert([
                'code_hash' => hash('sha256', $code), 'user_id' => $actor->id,
                'challenge' => $data['code_challenge'], 'redirect_uri' => $data['redirect_uri'],
                'issuer' => self::issuer(), 'account_fingerprint' => ObliStaffAccess::fingerprint($actor),
                'expires_at' => now()->addSeconds(90), 'used_at' => null,
            ]);

            return $data['redirect_uri'].'?'.http_build_query([
                'code' => $code, 'state' => $data['state'], 'iss' => self::issuer(),
            ], '', '&', PHP_QUERY_RFC3986);
        });
    }

    public function exchange(array $input): array
    {
        $data = Validator::make($input, [
            'client_id' => ['required', Rule::in([self::CLIENT])],
            'grant_type' => ['required', Rule::in(['authorization_code'])],
            'code' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'code_verifier' => ['required', 'string', 'regex:/\A[A-Za-z0-9._~-]{43,128}\z/'],
            'redirect_uri' => ['required', 'string', 'max:120'],
        ])->validate();

        return DB::transaction(function () use ($data): array {
            $hash = hash('sha256', $data['code']);
            $candidate = DB::table('obli_authorization_codes')->where('code_hash', $hash)->first();
            abort_unless($candidate, 401);
            // User first matches all subsequent token operations and account writers.
            $actor = $this->access->actor((int) $candidate->user_id);
            $code = DB::table('obli_authorization_codes')->where('code_hash', $hash)->lockForUpdate()->first();
            abort_unless($code && ! $code->used_at && CarbonImmutable::parse($code->expires_at)->isFuture()
                && hash_equals($code->redirect_uri, $data['redirect_uri'])
                && hash_equals($code->issuer, self::issuer())
                && hash_equals($code->account_fingerprint, ObliStaffAccess::fingerprint($actor))
                && hash_equals($code->challenge, rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=')), 401);
            DB::table('obli_authorization_codes')->where('code_hash', $hash)->update(['used_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $seconds = max(5, min(60, (int) config('endpoint_management.obli.session_minutes', 30))) * 60;
            DB::table('obli_staff_sessions')->insert([
                'token_hash' => hash('sha256', $token), 'user_id' => $actor->id,
                'issuer' => self::issuer(), 'account_fingerprint' => ObliStaffAccess::fingerprint($actor),
                'expires_at' => now()->addSeconds($seconds), 'revoked_at' => null,
            ]);

            $auditRequest = request()->duplicate();
            $auditRequest->setUserResolver(fn () => $actor);
            AuditLogger::logOrFail('obli.staff.signed_in', $actor, ['actor_id' => (int) $actor->id], $auditRequest);

            return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $seconds, 'issuer' => self::issuer()];
        });
    }

    /** The action commits with the current account and token checks; no cached actor. */
    public function withSession(?string $token, Closure $operation): mixed
    {
        abort_unless(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token), 401);

        return $this->withSessionHash(hash('sha256', $token), $operation);
    }

    /** Internal proof binding only: the endpoint never receives a staff bearer. */
    public function withSessionHash(string $hash, Closure $operation): mixed
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $hash), 401);

        return DB::transaction(function () use ($hash, $operation): mixed {
            $candidate = DB::table('obli_staff_sessions')->where('token_hash', $hash)->first();
            abort_unless($candidate, 401);
            $actor = $this->access->actor((int) $candidate->user_id);
            $session = DB::table('obli_staff_sessions')->where('token_hash', $hash)->lockForUpdate()->first();
            abort_unless($session && ! $session->revoked_at && CarbonImmutable::parse($session->expires_at)->isFuture()
                && hash_equals($session->issuer, self::issuer())
                && hash_equals($session->account_fingerprint, ObliStaffAccess::fingerprint($actor)), 401);

            $request = request();
            $originalResolver = $request->getUserResolver();
            $request->setUserResolver(fn () => $actor);
            try {
                return $operation($actor, $hash);
            } finally {
                $request->setUserResolver($originalResolver);
            }
        });
    }
}

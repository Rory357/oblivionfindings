<?php

namespace App\Services\Sites;

use App\Models\SiteCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

final class SiteCredentialStepUp
{
    public function verify(Request $request, SiteCredential $credential): bool
    {
        $actor = $request->user();
        $key = 'vault_step_up.'.$actor->id.'.'.$credential->id;
        $grant = $request->session()->get($key);
        $binding = hash_hmac('sha256', json_encode([$actor->password, $actor->two_factor_secret, $actor->two_factor_confirmed_at, $credential->lock_version]), config('app.key'));
        if (is_array($grant) && ($grant['expires'] ?? 0) > now()->timestamp && hash_equals($binding, $grant['binding'] ?? '')) return false;
        $password = $request->input('password');
        $code = $request->input('verification_code');
        $valid = is_string($password) && $password !== '' && strlen($password) <= 4096
            && is_string($actor->password) && Hash::check($password, $actor->password);
        $method = 'password';
        if (! $valid && is_string($code) && preg_match('/^\d{6}$/', $code) && $actor->two_factor_confirmed_at && $actor->two_factor_secret) {
            try {
                // Fortify encrypts the personal secret with serialized encryption.
                // Shared service TOTP uses its existing encryptString format.
                $secret = Fortify::currentEncrypter()->decrypt($actor->two_factor_secret);
                $window = (new Google2FA)->verifyKeyNewer($secret, $code, 0, 1);
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                $window = false;
            }
            // Durable uniqueness covers different sessions and simultaneous requests;
            // no personal/shared TOTP secret or one-time code is retained.
            $valid = is_int($window) && $window > 0 && DB::table('site_credential_step_up_uses')->insertOrIgnore([
                'user_id' => $actor->id, 'authenticator_window' => $window, 'created_at' => now(),
            ]) === 1;
            $method = 'personal_authenticator';
        }
        if (! $valid) throw ValidationException::withMessages(['verification_code' => 'Confirm your identity with your account password or a new code from your personal authenticator. SSO-only accounts need an enrolled personal authenticator.']);
        $request->session()->put($key, ['expires' => now()->addMinutes(5)->timestamp, 'binding' => $binding, 'method' => $method]);
        return true;
    }
}

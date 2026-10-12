<?php

namespace App\Services\Medication\BackupDelivery;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step-up for protected backups: the person's own current account password
 * and a new personal authenticator code; each authenticator window is used
 * once. Shared by the password reveal and the backup passphrase (EA-141).
 * Call inside the transaction that holds the actor's lock.
 */
final class BackupStepUp
{
    public function verify(User $actor, string $password, string $code): void
    {
        $valid = $actor->two_factor_confirmed_at && $actor->two_factor_secret && is_string($actor->password) && Hash::check($password, $actor->password);
        $window = false;
        if ($valid) {
            try {
                $secret = Fortify::currentEncrypter()->decrypt($actor->two_factor_secret);
                $window = (new Google2FA)->verifyKeyNewer($secret, $code, 0, 1);
            } catch (DecryptException) {
                $window = false;
            }
        }
        if (! is_int($window) || $window < 1 || DB::table('medication_backup_step_up_uses')->insertOrIgnore(['user_id' => $actor->id, 'authenticator_window' => $window, 'created_at' => now()]) !== 1) {
            throw ValidationException::withMessages(['verification_code' => 'Use your own current account password and a new personal authenticator code.']);
        }
    }
}

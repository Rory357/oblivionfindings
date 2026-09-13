<?php

namespace App\Services\Sites;

use App\Models\SiteCredential;
use App\Models\SiteCredentialVersion;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class SiteCredentialHistory
{
    public function check(SiteCredential $credential, mixed $version): void
    {
        if (! is_numeric($version) || (int) $credential->lock_version !== (int) $version) throw ValidationException::withMessages(['lock_version' => 'This credential changed. Your input is retained; refresh and review the latest version before reapplying.']);
    }

    public function retain(SiteCredential $credential, User $actor, string $action, ?string $evidence = null): void
    {
        SiteCredentialVersion::firstOrCreate(['credential_id' => $credential->id, 'version' => $credential->lock_version], [
            'encrypted_snapshot' => Crypt::encryptString(json_encode([...$credential->getAttributes(), '_evidence' => $evidence], JSON_THROW_ON_ERROR)),
            'action' => $action, 'user_id' => $actor->id, 'created_at' => now(),
        ]);
    }
}

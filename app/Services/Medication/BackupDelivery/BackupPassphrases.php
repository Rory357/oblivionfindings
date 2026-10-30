<?php

namespace App\Services\Medication\BackupDelivery;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\MedicationBackupDelivery;
use App\Models\MedicationBackupPassphrase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * EA-141 (Stephan, 10 Oct): a backup recipient sets their own passphrase,
 * ahead of time, in their own account, with step-up authentication. Their
 * copy of each later backup opens with it, so the app isn't needed during an
 * outage. Only the encryption template and its owner password are kept.
 * A new passphrase applies to backups made from then on; backups already
 * received keep the passphrase they were made with.
 */
final class BackupPassphrases
{
    public function __construct(
        private readonly BackupPdfEncryption $encryption,
        private readonly BackupDeliveryAccess $access,
    ) {}

    /** Could this person hold a backup passphrase (and is anything stopping them)? */
    public function eligibility(User $user): array
    {
        if (! $user->isApproved() || ! $user->canDo('medications.reports.view') || ! $user->canDo('medications.reports.export')
            || $this->access->financeOnly($user) || ! app(HrCurrentStaffService::class)->isCurrent($user)) {
            return ['can_set' => false, 'reason' => null];
        }
        if (! $this->access->canOpen($user)) {
            return ['can_set' => false, 'reason' => 'Set up authenticator two-step sign-in in your account first.'];
        }
        if (! $this->encryption->ready()) {
            return ['can_set' => false, 'reason' => 'Protected backups need strong PDF encryption, which isn’t set up yet.'];
        }

        return ['can_set' => true, 'reason' => null];
    }

    /** @return array{set: bool, set_at: ?string} */
    public function status(User $user): array
    {
        $row = MedicationBackupPassphrase::query()->where('user_id', $user->id)->first(['id', 'set_at']);

        return ['set' => $row !== null, 'set_at' => $row?->set_at?->toIso8601String()];
    }

    /** @param list<int> $userIds @return list<int> */
    public function withPassphrase(array $userIds): array
    {
        return $userIds === [] ? [] : MedicationBackupPassphrase::query()->whereIn('user_id', $userIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function set(User $actor, array $input): void
    {
        $data = Validator::make($input, [
            // Printable characters, no leading dash or spaces at either end, so
            // every PDF reader takes it as typed.
            'passphrase' => ['required', 'string', 'confirmed', 'regex:/^[\x21-\x2C\x2E-\x7E][\x20-\x7E]{10,126}[\x21-\x7E]$/D'],
            'password' => 'required|string|max:4096',
            'verification_code' => ['required', 'regex:/^\d{6}$/'],
        ], ['passphrase.regex' => 'Use 12 to 128 ordinary keyboard characters, with no space at the start or end.'])->validate();
        abort_unless($this->eligibility($actor)['can_set'], 403);
        if (is_string($actor->password) && Hash::check($data['passphrase'], $actor->password)) {
            throw ValidationException::withMessages(['passphrase' => 'Choose a passphrase that isn’t your account password.']);
        }
        // Rendering with qpdf happens before any lock is held.
        $template = $this->encryption->passphraseTemplate($data['passphrase']);
        DB::transaction(function () use ($actor, $data, $template): void {
            $locked = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            abort_unless($this->eligibility($locked)['can_set'], 403);
            app(BackupStepUp::class)->verify($locked, $data['password'], $data['verification_code']);
            $row = MedicationBackupPassphrase::query()->where('user_id', $locked->id)->lockForUpdate()->first();
            $row ??= new MedicationBackupPassphrase(['user_id' => $locked->id]);
            $changed = $row->exists;
            $row->forceFill(['template' => base64_encode($template['template']), 'owner_secret' => $template['owner'], 'set_at' => now()])->saveOrFail();
            AuditLogger::logOrFail($changed ? 'medications.backup_passphrase.changed' : 'medications.backup_passphrase.set', $row, ['actor_id' => $locked->id]);
        }, 1);
    }

    /**
     * Each recipient's own copy of a protected backup, for those with a
     * passphrase: user id => bytes. Fails loudly: a copy that can't be made
     * stops the send before anything is submitted.
     *
     * @param  list<int>  $recipientIds
     * @return array<int, string>
     */
    public function copiesFor(MedicationBackupDelivery $row, array $recipientIds, string $protected): array
    {
        $copies = [];
        foreach (MedicationBackupPassphrase::query()->whereIn('user_id', $recipientIds)->orderBy('user_id')->get() as $passphrase) {
            $template = base64_decode((string) $passphrase->template, true);
            if (! is_string($template)) {
                throw new \RuntimeException('backup_passphrase_unreadable');
            }
            $copies[(int) $passphrase->user_id] = $this->encryption->encryptForRecipient($protected, (string) $row->password, $template, (string) $passphrase->owner_secret);
        }

        return $copies;
    }
}

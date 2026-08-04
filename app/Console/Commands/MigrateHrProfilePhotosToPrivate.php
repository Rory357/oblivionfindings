<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrProfilePhotoStorageService;
use Illuminate\Console\Command;

class MigrateHrProfilePhotosToPrivate extends Command
{
    protected $signature = 'hr:profile-photos:migrate-private
        {--rollback : Restore verified objects to the legacy public disk for an explicit application rollback}';

    protected $description = 'Safely migrate canonical HR profile photos between legacy public and protected private storage.';

    public function handle(HrProfilePhotoStorageService $photos): int
    {
        $rollback = (bool) $this->option('rollback');
        $counts = array_fill_keys([
            HrProfilePhotoStorageService::MOVED,
            HrProfilePhotoStorageService::ALREADY_PRESENT,
            HrProfilePhotoStorageService::MISSING,
            HrProfilePhotoStorageService::INVALID,
            HrProfilePhotoStorageService::CONFLICT,
            HrProfilePhotoStorageService::FAILED,
            HrProfilePhotoStorageService::CLEANUP_FAILED,
        ], 0);

        HrEmployeeProfile::withTrashed()
            ->whereNotNull('profile_photo_path')
            ->select(['id', 'profile_photo_path'])
            ->chunkById(200, function ($profiles) use (&$counts, $photos, $rollback): void {
                foreach ($profiles as $profile) {
                    $result = $rollback
                        ? $photos->rollbackToPublic($profile->profile_photo_path, (int) $profile->id)
                        : $photos->migrateToPrivate($profile->profile_photo_path, (int) $profile->id);
                    $counts[$result] = ($counts[$result] ?? 0) + 1;
                }
            });

        // A successful forward run is a proof that the public bypass is gone,
        // not merely that every database row was visited. Ambiguous or orphaned
        // objects are reported as a redacted count and left untouched for an
        // operator to investigate safely.
        $publicResidue = $rollback ? 'skipped' : $photos->publicResidueCount();

        $this->line(sprintf(
            'HR profile photo storage: moved=%d already=%d missing=%d invalid=%d conflicts=%d failed=%d cleanup_failed=%d public_residue=%s',
            $counts[HrProfilePhotoStorageService::MOVED],
            $counts[HrProfilePhotoStorageService::ALREADY_PRESENT],
            $counts[HrProfilePhotoStorageService::MISSING],
            $counts[HrProfilePhotoStorageService::INVALID],
            $counts[HrProfilePhotoStorageService::CONFLICT],
            $counts[HrProfilePhotoStorageService::FAILED],
            $counts[HrProfilePhotoStorageService::CLEANUP_FAILED],
            $publicResidue === null ? 'unavailable' : (string) $publicResidue,
        ));

        $unsafe = $counts[HrProfilePhotoStorageService::CONFLICT]
            + $counts[HrProfilePhotoStorageService::FAILED]
            + $counts[HrProfilePhotoStorageService::CLEANUP_FAILED]
            + $counts[HrProfilePhotoStorageService::MISSING]
            + $counts[HrProfilePhotoStorageService::INVALID]
            + ($rollback || $publicResidue === 0 ? 0 : 1);

        return $unsafe > 0 ? self::FAILURE : self::SUCCESS;
    }
}

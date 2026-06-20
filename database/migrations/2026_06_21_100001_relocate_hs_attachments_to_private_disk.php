<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Item 3 backfill — relocate already-uploaded Health & Safety / Privacy attachment FILES
 * from the public disk to the private disk so the prior public /storage/... URLs stop
 * resolving, and flip each row's `disk` column to 'private'. Forward-only data move,
 * guarded by existence so missing files (or files already on private) are skipped and the
 * migration is safe to re-run.
 *
 * New uploads already land on the private disk (controller change shipped in the same PR);
 * downloads stream the file from whichever disk the row records, so this backfill closes
 * the residual exposure for files captured before the change.
 *
 * Hazard evidence (site_hazards JSON columns) has no per-row `disk` column — its files are
 * moved in place (identical relative path) and SiteHazardController::showMedia resolves the
 * serving disk by existence.
 */
return new class extends Migration
{
    /** Attachment tables that carry a per-row `disk` + `path` column. */
    private const ATTACHMENT_TABLES = [
        'workplace_injury_attachments',
        'first_aid_attachments',
        'emergency_drill_attachments',
        'hs_risk_assessment_attachments',
        'restraint_event_attachments',
        'ppe_attachments',
        'ppe_allocation_attachments',
        'ppe_inspection_attachments',
        'client_incident_attachments',
        'fleet_incident_attachments',
        'safeguarding_attachments',
        'privacy_attachments',
    ];

    public function up(): void
    {
        foreach (self::ATTACHMENT_TABLES as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'disk')
                || ! Schema::hasColumn($table, 'path')) {
                continue;
            }

            DB::table($table)
                ->where(fn ($q) => $q->where('disk', 'public')->orWhereNull('disk'))
                ->select('id', 'path')
                ->chunkById(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        if ($this->relocate($row->path)) {
                            DB::table($table)->where('id', $row->id)->update(['disk' => 'private']);
                        }
                    }
                });
        }

        $this->relocateHazardEvidence();
    }

    public function down(): void
    {
        // Forward-only: moving the files back to the public disk would re-open the very
        // exposure this migration closes, so the down() is intentionally a no-op.
    }

    /**
     * Move one relative path from the public disk to the private disk. Idempotent and
     * guarded — returns true once the file is present on the private disk.
     */
    private function relocate(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        $private = Storage::disk('private');
        $public = Storage::disk('public');

        if ($private->exists($path)) {
            // Already relocated — sweep up any stale public copy left behind.
            if ($public->exists($path)) {
                $public->delete($path);
            }

            return true;
        }

        if (! $public->exists($path)) {
            return false; // nothing to move and not on private — leave the row untouched
        }

        $stream = $public->readStream($path);
        if ($stream === false || $stream === null) {
            return false;
        }

        $private->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $public->delete($path);

        return true;
    }

    private function relocateHazardEvidence(): void
    {
        if (! Schema::hasTable('site_hazards')) {
            return;
        }

        $columns = array_values(array_filter(
            ['photo_paths', 'document_paths', 'resolution_evidence'],
            fn (string $c) => Schema::hasColumn('site_hazards', $c),
        ));
        if (! $columns) {
            return;
        }

        DB::table('site_hazards')
            ->select(array_merge(['id'], $columns))
            ->chunkById(200, function ($rows) use ($columns) {
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        foreach ($this->pathsFrom($row->{$column} ?? null) as $path) {
                            $this->relocate($path);
                        }
                    }
                }
            });
    }

    /**
     * Pull relative paths out of a hazard JSON column — either a string[] of paths or an
     * array of {name, path, size} objects.
     *
     * @return array<int,string>
     */
    private function pathsFrom(mixed $json): array
    {
        if (! $json) {
            return [];
        }

        $items = is_array($json) ? $json : json_decode((string) $json, true);
        if (! is_array($items)) {
            return [];
        }

        $paths = [];
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $paths[] = $item;
            } elseif (is_array($item) && ! empty($item['path'])) {
                $paths[] = $item['path'];
            }
        }

        return $paths;
    }
};

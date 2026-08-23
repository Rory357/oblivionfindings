<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits independently of the filesystem inventory below.
        // Keep the migration resumable if a legacy path requires intervention.
        if (! Schema::hasColumn('client_consents', 'signed_document_disk')) {
            Schema::table('client_consents', function (Blueprint $table): void {
                $table->string('signed_document_disk', 32)->nullable()->after('signed_document_path');
                $table->string('signed_document_original_name')->nullable()->after('signed_document_disk');
                $table->string('signed_document_mime_type', 100)->nullable()->after('signed_document_original_name');
                $table->unsignedBigInteger('signed_document_size_bytes')->nullable()->after('signed_document_mime_type');
                $table->char('signed_document_sha256', 64)->nullable()->after('signed_document_size_bytes');
                $table->string('signed_document_malware_disposition', 32)->nullable()->after('signed_document_sha256');
                $table->string('signed_document_scanner', 100)->nullable()->after('signed_document_malware_disposition');
                $table->timestamp('signed_document_scanned_at')->nullable()->after('signed_document_scanner');
                $table->timestamp('signed_document_retained_until')->nullable()->after('signed_document_scanned_at');
                $table->timestamp('signed_document_disposed_at')->nullable()->after('signed_document_retained_until');
                $table->foreignId('signed_document_uploaded_by_user_id')
                    ->nullable()
                    ->after('signed_document_disposed_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('client_consents', 'signed_document_command_sha256')) {
            Schema::table('client_consents', function (Blueprint $table): void {
                $table->char('signed_document_command_sha256', 64)
                    ->nullable()
                    ->after('signed_document_sha256');
            });
        }
        if (! Schema::hasIndex('client_consents', 'client_consents_evidence_command_uq')) {
            Schema::table('client_consents', function (Blueprint $table): void {
                $table->unique(
                    'signed_document_command_sha256',
                    'client_consents_evidence_command_uq',
                );
            });
        }

        $this->quarantineLegacyPublicEvidence();
    }

    public function down(): void
    {
        // Private evidence is intentionally never copied back to the public
        // disk during rollback. Only the metadata columns are removed.
        if (Schema::hasIndex('client_consents', 'client_consents_evidence_command_uq')) {
            Schema::table('client_consents', function (Blueprint $table): void {
                $table->dropUnique('client_consents_evidence_command_uq');
            });
        }

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signed_document_uploaded_by_user_id');
            $table->dropColumn([
                'signed_document_disk',
                'signed_document_original_name',
                'signed_document_mime_type',
                'signed_document_size_bytes',
                'signed_document_sha256',
                'signed_document_command_sha256',
                'signed_document_malware_disposition',
                'signed_document_scanner',
                'signed_document_scanned_at',
                'signed_document_retained_until',
                'signed_document_disposed_at',
            ]);
        });
    }

    private function quarantineLegacyPublicEvidence(): void
    {
        DB::table('client_consents')
            ->select(['id', 'signed_document_path'])
            ->whereNotNull('signed_document_path')
            ->whereNull('signed_document_disk')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $legacyPath = str_replace('\\', '/', (string) $row->signed_document_path);
                    $segments = explode('/', $legacyPath);
                    if ($legacyPath === ''
                        || str_starts_with($legacyPath, '/')
                        || preg_match('/\A[a-z]:\//i', $legacyPath) === 1
                        || preg_match('/[\x00-\x1F\x7F]/', $legacyPath) === 1
                        || ! str_starts_with($legacyPath, 'consent-documents/')
                        || in_array('', $segments, true)
                        || in_array('.', $segments, true)
                        || in_array('..', $segments, true)) {
                        throw new RuntimeException("Consent {$row->id} has an unsafe legacy evidence path.");
                    }

                    $extension = strtolower((string) pathinfo($legacyPath, PATHINFO_EXTENSION));
                    if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
                        $extension = 'bin';
                    }

                    $token = hash('sha256', "legacy-consent-evidence-v1|{$row->id}|{$legacyPath}");
                    $privatePath = "consent-evidence/legacy/{$token}.{$extension}";
                    $source = Storage::disk('public')->path($legacyPath);
                    $destination = Storage::disk('private')->path($privatePath);
                    if (is_link($source) || is_link($destination)) {
                        throw new RuntimeException("Consent {$row->id} legacy evidence cannot use a symbolic link.");
                    }
                    $sourceExists = is_file($source);
                    $destinationExists = is_file($destination);

                    if ($sourceExists && ! $destinationExists) {
                        File::ensureDirectoryExists(dirname($destination));
                        if (! rename($source, $destination)) {
                            throw new RuntimeException("Consent {$row->id} evidence could not be moved off the public disk.");
                        }
                        $destinationExists = true;
                    } elseif ($sourceExists && $destinationExists) {
                        $sourceSize = filesize($source);
                        $destinationSize = filesize($destination);
                        $sourceSha256 = hash_file('sha256', $source);
                        $destinationSha256 = hash_file('sha256', $destination);
                        if (! is_int($sourceSize)
                            || ! is_int($destinationSize)
                            || $sourceSize !== $destinationSize
                            || ! is_string($sourceSha256)
                            || ! is_string($destinationSha256)
                            || ! hash_equals($sourceSha256, $destinationSha256)
                            || ! unlink($source)) {
                            throw new RuntimeException("Consent {$row->id} legacy evidence quarantine is inconsistent.");
                        }
                    }

                    if (! $destinationExists) {
                        DB::table('client_consents')->where('id', $row->id)->update([
                            'signed_document_disk' => 'missing',
                            'signed_document_malware_disposition' => 'legacy_missing',
                            'signed_document_scanner' => 'legacy_inventory_only',
                        ]);

                        continue;
                    }

                    $size = filesize($destination);
                    $sha256 = hash_file('sha256', $destination);
                    if (! is_int($size) || ! is_string($sha256) || strlen($sha256) !== 64) {
                        throw new RuntimeException("Consent {$row->id} private evidence inventory could not be read.");
                    }

                    DB::table('client_consents')->where('id', $row->id)->update([
                        'signed_document_path' => $privatePath,
                        'signed_document_disk' => 'private',
                        'signed_document_original_name' => 'signed-consent-evidence.'.$extension,
                        'signed_document_size_bytes' => $size,
                        'signed_document_sha256' => $sha256,
                        'signed_document_malware_disposition' => 'legacy_unverified',
                        'signed_document_scanner' => 'legacy_inventory_only',
                    ]);
                }
            });
    }
};

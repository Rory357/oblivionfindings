<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_configuration_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('profile_key', 160);
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('provider', 80);
            $table->string('device_domain', 80);
            $table->string('target_category', 80)->nullable();
            $table->longText('encrypted_payload');
            $table->char('payload_hash', 64);
            $table->json('verification_sections');
            $table->string('status', 24)->default('active');
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_profile_id')->nullable()->constrained('device_configuration_profiles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['profile_key', 'version'], 'device_configuration_profiles_key_version_unique');
            $table->index(
                ['provider', 'device_domain', 'target_category', 'status'],
                'device_configuration_profiles_compatibility_index',
            );
        });

        Schema::table('queclink_presets', function (Blueprint $table): void {
            $table->foreignId('device_configuration_profile_id')
                ->nullable()
                ->after('id')
                ->constrained('device_configuration_profiles')
                ->restrictOnDelete();
        });

        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->index('device_command_attempt_id', 'queclink_pending_commands_attempt_index');
        });
        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->dropUnique('queclink_pending_commands_attempt_unique');
            $table->unsignedSmallInteger('governed_sequence')->default(1)->after('device_command_attempt_id');
            $table->string('governed_role', 24)->default('action')->after('governed_sequence');
            $table->unique(
                ['device_command_attempt_id', 'governed_sequence'],
                'queclink_pending_commands_attempt_sequence_unique',
            );
        });

        $this->protectLegacyPresets();
        $this->classifyExistingGovernedCommands();
    }

    public function down(): void
    {
        $duplicates = DB::table('queclink_pending_commands')
            ->whereNotNull('device_command_attempt_id')
            ->select('device_command_attempt_id')
            ->groupBy('device_command_attempt_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Cannot roll back governed configuration sequencing after a multi-step command has been recorded.');
        }

        $this->restoreLegacyPresets();

        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->dropUnique('queclink_pending_commands_attempt_sequence_unique');
            $table->dropColumn(['governed_sequence', 'governed_role']);
            $table->unique('device_command_attempt_id', 'queclink_pending_commands_attempt_unique');
        });
        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->dropIndex('queclink_pending_commands_attempt_index');
        });

        Schema::table('queclink_presets', function (Blueprint $table): void {
            $table->dropForeign(['device_configuration_profile_id']);
            $table->dropColumn('device_configuration_profile_id');
        });

        Schema::dropIfExists('device_configuration_profiles');
    }

    private function protectLegacyPresets(): void
    {
        DB::table('queclink_presets')->orderBy('id')->get()->each(function (object $preset): void {
            $payload = json_decode((string) ($preset->payload ?: '{}'), true);
            $payload = is_array($payload) ? Arr::sortRecursive($payload) : [];
            $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $profileId = DB::table('device_configuration_profiles')->insertGetId([
                'uuid' => (string) Str::orderedUuid(),
                'profile_key' => 'queclink:'.(string) $preset->slug,
                'version' => 1,
                'name' => (string) $preset->name,
                'description' => $preset->description,
                'provider' => 'queclink',
                'device_domain' => 'tracking',
                'target_category' => $preset->target_category,
                'encrypted_payload' => Crypt::encryptString($canonical),
                'payload_hash' => hash('sha256', $canonical),
                'verification_sections' => json_encode($this->verificationSections($payload), JSON_THROW_ON_ERROR),
                'status' => 'active',
                'is_system' => (bool) $preset->is_system,
                'created_by_user_id' => $preset->created_by_user_id,
                'created_at' => $preset->created_at,
                'updated_at' => $preset->updated_at,
            ]);

            DB::table('queclink_presets')->where('id', $preset->id)->update([
                'device_configuration_profile_id' => $profileId,
                'payload' => json_encode([], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    private function restoreLegacyPresets(): void
    {
        DB::table('queclink_presets')
            ->whereNotNull('device_configuration_profile_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $preset): void {
                $profile = DB::table('device_configuration_profiles')->find($preset->device_configuration_profile_id);
                if ($profile === null) {
                    return;
                }
                DB::table('queclink_presets')->where('id', $preset->id)->update([
                    'payload' => Crypt::decryptString((string) $profile->encrypted_payload),
                ]);
            });
    }

    private function classifyExistingGovernedCommands(): void
    {
        DB::table('queclink_pending_commands')
            ->whereNotNull('device_command_request_id')
            ->orderBy('id')
            ->get(['id', 'device_command_request_id'])
            ->each(function (object $pending): void {
                $capability = DB::table('device_command_requests')
                    ->where('id', $pending->device_command_request_id)
                    ->value('capability');
                DB::table('queclink_pending_commands')->where('id', $pending->id)->update([
                    'governed_role' => $capability === 'configuration.refresh' ? 'verification' : 'action',
                ]);
            });
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function verificationSections(array $payload): array
    {
        $aliases = [
            'server' => 'SRI', 'sri' => 'SRI', 'tracking' => 'CFG', 'global' => 'CFG', 'cfg' => 'CFG',
            'pin' => 'PIN', 'dog' => 'DOG', 'time' => 'TMA', 'tma' => 'TMA', 'non_movement' => 'NMD',
            'nmd' => 'NMD', 'power' => 'PDS', 'pds' => 'PDS', 'wifi' => 'WFI', 'wfi' => 'WFI',
            'geo' => 'GEO', 'bluetooth' => 'BTS', 'bt' => 'BTS', 'bts' => 'BTS', 'beacons' => 'BID',
            'bid' => 'BID', 'allowlist' => 'WLT', 'wlt' => 'WLT', 'firmware_update' => 'UPC',
            'upc' => 'UPC', 'firmware_version' => 'FVR', 'fvr' => 'FVR',
        ];

        return collect(array_keys($payload))
            ->map(fn (string $section): ?string => $aliases[strtolower($section)] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
};

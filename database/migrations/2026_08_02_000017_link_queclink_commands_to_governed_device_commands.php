<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->longText('raw_command_encrypted')->nullable()->after('raw_command');
            $table->foreignId('device_command_request_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('device_command_requests')
                ->restrictOnDelete();
            $table->foreignId('device_command_attempt_id')
                ->nullable()
                ->after('device_command_request_id')
                ->constrained('device_command_attempts')
                ->restrictOnDelete();
            $table->foreignId('fulfilled_telemetry_event_id')
                ->nullable()
                ->after('device_command_attempt_id')
                ->constrained('fleet_telemetry_events')
                ->nullOnDelete();
            $table->foreignId('fulfilled_raw_frame_id')
                ->nullable()
                ->after('fulfilled_telemetry_event_id')
                ->constrained('queclink_raw_frames')
                ->nullOnDelete();
            $table->string('sent_session_id', 64)->nullable()->after('sent_at');
            $table->timestamp('fulfilled_at')->nullable()->after('acked_at');
            $table->timestamp('reconciliation_dispatched_at')->nullable()->after('fulfilled_at');

            $table->unique(
                'device_command_attempt_id',
                'queclink_pending_commands_attempt_unique',
            );
            $table->index(
                ['device_command_request_id', 'status'],
                'queclink_pending_commands_request_status_index',
            );
        });

        Schema::table('queclink_raw_frames', function (Blueprint $table): void {
            $table->longText('encrypted_raw_frame')->nullable()->after('raw_frame');
            $table->longText('encrypted_parsed_payload')->nullable()->after('parsed_payload');
        });

        $this->protectExistingSensitivePayloads();
    }

    public function down(): void
    {
        $this->restoreExistingSensitivePayloads();

        Schema::table('queclink_pending_commands', function (Blueprint $table): void {
            $table->dropForeign(['fulfilled_telemetry_event_id']);
            $table->dropForeign(['fulfilled_raw_frame_id']);
            $table->dropForeign(['device_command_attempt_id']);
            $table->dropForeign(['device_command_request_id']);
            $table->dropIndex('queclink_pending_commands_request_status_index');
            $table->dropUnique('queclink_pending_commands_attempt_unique');
            $table->dropColumn([
                'device_command_request_id',
                'device_command_attempt_id',
                'fulfilled_telemetry_event_id',
                'fulfilled_raw_frame_id',
                'sent_session_id',
                'fulfilled_at',
                'reconciliation_dispatched_at',
                'raw_command_encrypted',
            ]);
        });

        Schema::table('queclink_raw_frames', function (Blueprint $table): void {
            $table->dropColumn(['encrypted_raw_frame', 'encrypted_parsed_payload']);
        });
    }

    private function protectExistingSensitivePayloads(): void
    {
        DB::table('queclink_pending_commands')
            ->whereNull('raw_command_encrypted')
            ->whereNotNull('raw_command')
            ->orderBy('id')
            ->chunkById(200, function ($commands): void {
                foreach ($commands as $command) {
                    $raw = (string) $command->raw_command;
                    if ($raw === '') {
                        continue;
                    }
                    DB::table('queclink_pending_commands')
                        ->where('id', $command->id)
                        ->update([
                            'raw_command' => '[encrypted command payload]',
                            'raw_command_encrypted' => Crypt::encryptString($raw),
                        ]);
                }
            });

        DB::table('queclink_raw_frames')
            ->whereNull('encrypted_raw_frame')
            ->where(function ($query): void {
                $query->where(function ($outbound): void {
                    $outbound->where('direction', 'outbound')->where('frame_type', 'AT');
                })->orWhere('command_word', 'GTALM');
            })
            ->orderBy('id')
            ->chunkById(200, function ($frames): void {
                foreach ($frames as $frame) {
                    $raw = (string) $frame->raw_frame;
                    $payload = is_string($frame->parsed_payload)
                        ? json_decode($frame->parsed_payload, true)
                        : null;
                    $sensitivePayload = is_array($payload) && array_key_exists('config_text', $payload)
                        ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                        : null;
                    if (is_array($payload)) {
                        unset($payload['config_text']);
                        if ($sensitivePayload !== null) {
                            $payload['configuration_payload_protected'] = true;
                        }
                    }
                    DB::table('queclink_raw_frames')
                        ->where('id', $frame->id)
                        ->update([
                            'raw_frame' => '[encrypted sensitive frame]',
                            'encrypted_raw_frame' => Crypt::encryptString($raw),
                            'parsed_payload' => is_array($payload)
                                ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                                : $frame->parsed_payload,
                            'encrypted_parsed_payload' => $sensitivePayload === null
                                ? null
                                : Crypt::encryptString($sensitivePayload),
                        ]);
                }
            });
    }

    private function restoreExistingSensitivePayloads(): void
    {
        DB::table('queclink_pending_commands')
            ->whereNotNull('raw_command_encrypted')
            ->orderBy('id')
            ->chunkById(200, function ($commands): void {
                foreach ($commands as $command) {
                    DB::table('queclink_pending_commands')
                        ->where('id', $command->id)
                        ->update(['raw_command' => Crypt::decryptString((string) $command->raw_command_encrypted)]);
                }
            });

        DB::table('queclink_raw_frames')
            ->whereNotNull('encrypted_raw_frame')
            ->orderBy('id')
            ->chunkById(200, function ($frames): void {
                foreach ($frames as $frame) {
                    $payload = $frame->encrypted_parsed_payload === null
                        ? $frame->parsed_payload
                        : Crypt::decryptString((string) $frame->encrypted_parsed_payload);
                    DB::table('queclink_raw_frames')
                        ->where('id', $frame->id)
                        ->update([
                            'raw_frame' => Crypt::decryptString((string) $frame->encrypted_raw_frame),
                            'parsed_payload' => $payload,
                        ]);
                }
            });
    }
};

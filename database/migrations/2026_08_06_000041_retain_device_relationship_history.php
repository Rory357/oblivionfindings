<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_GUARD = 'active_relationship_guard';

    private const INSERT_GUARD = 'device_relationships_before_insert_guard';

    private const UPDATE_GUARD = 'device_relationships_before_update_guard';

    private const DELETE_GUARD = 'device_relationships_before_delete_guard';

    public function up(): void
    {
        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->dropForeign(['parent_device_id']);
            $table->dropForeign(['child_device_id']);
        });

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->dropUnique('dev_rel_pair_type_unique');

            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('notes');
            $table->timestamp('unlinked_at')->nullable()->after('created_by_user_id');
            $table->unsignedBigInteger('unlinked_by_user_id')->nullable()->after('unlinked_at');
            $table->text('unlink_reason')->nullable()->after('unlinked_by_user_id');
            $table->unsignedTinyInteger(self::ACTIVE_GUARD)
                ->nullable()
                ->virtualAs('case when `unlinked_at` is null then 1 else null end');

            $table->unique(
                ['parent_device_id', 'child_device_id', 'relationship_type', self::ACTIVE_GUARD],
                'dev_rel_active_pair_type_unique',
            );
        });

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->foreign('parent_device_id')->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('child_device_id')->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('unlinked_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });

        $this->installLifecycleGuards();
    }

    public function down(): void
    {
        if (DB::table('device_relationships')
            ->whereNotNull('created_by_user_id')
            ->orWhereNotNull('unlinked_at')
            ->orWhereNotNull('unlinked_by_user_id')
            ->orWhereNotNull('unlink_reason')
            ->exists()) {
            throw new RuntimeException('Cannot remove retained Device relationship lifecycle evidence after attributed activity exists.');
        }

        $this->dropLifecycleGuards();

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->dropForeign(['parent_device_id']);
            $table->dropForeign(['child_device_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['unlinked_by_user_id']);
        });

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->dropUnique('dev_rel_active_pair_type_unique');
        });

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->dropColumn([
                self::ACTIVE_GUARD,
                'unlink_reason',
                'unlinked_by_user_id',
                'unlinked_at',
                'created_by_user_id',
            ]);
            $table->unique(
                ['parent_device_id', 'child_device_id', 'relationship_type'],
                'dev_rel_pair_type_unique',
            );
        });

        Schema::table('device_relationships', function (Blueprint $table): void {
            $table->foreign('parent_device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->foreign('child_device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
    }

    private function installLifecycleGuards(): void
    {
        $this->dropLifecycleGuards();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER device_relationships_before_insert_guard
            BEFORE INSERT ON device_relationships
            FOR EACH ROW
            BEGIN
                IF NEW.created_by_user_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'New Device relationships require creation actor evidence.';
                END IF;

                IF NEW.parent_device_id = NEW.child_device_id THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A Device relationship must connect two different Devices.';
                END IF;

                IF NEW.unlinked_at IS NOT NULL
                    OR NEW.unlinked_by_user_id IS NOT NULL
                    OR NEW.unlink_reason IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'New Device relationships must begin as active evidence.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER device_relationships_before_update_guard
            BEFORE UPDATE ON device_relationships
            FOR EACH ROW
            BEGIN
                IF OLD.unlinked_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Unlinked Device relationship history is immutable.';
                END IF;

                IF NOT (OLD.id <=> NEW.id)
                    OR NOT (OLD.parent_device_id <=> NEW.parent_device_id)
                    OR NOT (OLD.child_device_id <=> NEW.child_device_id)
                    OR NOT (OLD.relationship_type <=> NEW.relationship_type)
                    OR NOT (OLD.port <=> NEW.port)
                    OR NOT (OLD.notes <=> NEW.notes)
                    OR NOT (OLD.created_by_user_id <=> NEW.created_by_user_id)
                    OR NOT (OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Active Device relationship evidence must be removed and recreated.';
                END IF;

                IF NEW.unlinked_at IS NULL
                    OR NEW.unlinked_by_user_id IS NULL
                    OR NEW.unlink_reason IS NULL
                    OR CHAR_LENGTH(TRIM(NEW.unlink_reason)) = 0 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Unlinked Device relationships require actor and reason evidence.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER device_relationships_before_delete_guard
            BEFORE DELETE ON device_relationships
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Device relationship history cannot be deleted.';
            END
            SQL);
    }

    private function dropLifecycleGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::INSERT_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_GUARD);
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::DELETE_GUARD);
    }
};

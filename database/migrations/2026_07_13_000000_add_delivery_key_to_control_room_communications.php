<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_room_communications')) {
            return;
        }

        $addDeliveryKey = ! Schema::hasColumn('control_room_communications', 'delivery_key');
        $addSupersededAt = ! Schema::hasColumn('control_room_communications', 'superseded_at');
        $addNotificationPayload = ! Schema::hasColumn('control_room_communications', 'notification_payload');

        if ($addDeliveryKey || $addSupersededAt || $addNotificationPayload) {
            Schema::table('control_room_communications', function (Blueprint $table) use ($addDeliveryKey, $addSupersededAt, $addNotificationPayload): void {
                if ($addDeliveryKey) {
                    $table->string('delivery_key', 64)->nullable()->unique();
                }
                if ($addSupersededAt) {
                    $table->timestamp('superseded_at')->nullable();
                }
                if ($addNotificationPayload) {
                    $table->json('notification_payload')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_room_communications')) {
            return;
        }

        $dropDeliveryKey = Schema::hasColumn('control_room_communications', 'delivery_key');
        $dropSupersededAt = Schema::hasColumn('control_room_communications', 'superseded_at');
        $dropNotificationPayload = Schema::hasColumn('control_room_communications', 'notification_payload');

        if ($dropDeliveryKey || $dropSupersededAt || $dropNotificationPayload) {
            Schema::table('control_room_communications', function (Blueprint $table) use ($dropDeliveryKey, $dropSupersededAt, $dropNotificationPayload): void {
                if ($dropDeliveryKey) {
                    $table->dropUnique('control_room_communications_delivery_key_unique');
                    $table->dropColumn('delivery_key');
                }
                if ($dropSupersededAt) {
                    $table->dropColumn('superseded_at');
                }
                if ($dropNotificationPayload) {
                    $table->dropColumn('notification_payload');
                }
            });
        }
    }
};

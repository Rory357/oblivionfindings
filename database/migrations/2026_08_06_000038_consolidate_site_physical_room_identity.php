<?php

use App\Support\LegacyStorageContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_house_rooms', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_room_id')->nullable()->after('site_id');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_room_id')->nullable()->after('room_id');
        });

        $this->backfillResidentialRooms();
        $this->backfillAssetRooms();

        Schema::table('site_rooms', function (Blueprint $table): void {
            $table->unique(['id', 'site_id'], 'site_rooms_id_site_uq');
        });

        Schema::table('site_house_rooms', function (Blueprint $table): void {
            $table->unique('site_room_id', 'site_house_rooms_site_room_uq');
            $table->foreign(['site_room_id', 'site_id'])
                ->references(['id', 'site_id'])
                ->on('site_rooms')
                ->restrictOnDelete();
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreign(['site_room_id', 'site_id'])
                ->references(['id', 'site_id'])
                ->on('site_rooms')
                ->restrictOnDelete();
            $table->index(['site_id', 'site_room_id'], 'assets_site_site_room_idx');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('assets_site_site_room_idx');
            $table->dropForeign(['site_room_id', 'site_id']);
            $table->dropColumn('site_room_id');
        });

        Schema::table('site_house_rooms', function (Blueprint $table): void {
            $table->dropForeign(['site_room_id', 'site_id']);
            $table->dropUnique('site_house_rooms_site_room_uq');
            $table->dropColumn('site_room_id');
        });

        Schema::table('site_rooms', function (Blueprint $table): void {
            $table->dropUnique('site_rooms_id_site_uq');
        });
    }

    private function backfillResidentialRooms(): void
    {
        $claimedCanonicalIds = [];

        DB::table('site_house_rooms')
            ->orderBy('id')
            ->chunkById(200, function ($houseRooms) use (&$claimedCanonicalIds): void {
                foreach ($houseRooms as $houseRoom) {
                    $siteId = (int) $houseRoom->site_id;
                    $houseRoomId = (int) $houseRoom->id;
                    $siteRooms = DB::table('site_rooms')
                        ->where('site_id', $siteId)
                        ->orderBy('id')
                        ->get();

                    $validLegacy = $siteRooms
                        ->filter(fn ($room): bool => $room->linked_room_type === 'house_room'
                            && (int) $room->linked_room_id === $houseRoomId
                            && ! isset($claimedCanonicalIds[(int) $room->id]));

                    $canonical = $validLegacy->first();

                    if ($canonical === null) {
                        $normalisedName = $this->normaliseName((string) $houseRoom->name);
                        $nameMatches = $siteRooms
                            ->filter(fn ($room): bool => ! isset($claimedCanonicalIds[(int) $room->id])
                                && $this->normaliseName((string) $room->name) === $normalisedName
                                && ($room->linked_room_type === null
                                    || ($room->linked_room_type === 'house_room'
                                        && (int) $room->linked_room_id === $houseRoomId)));

                        if ($nameMatches->count() === 1) {
                            $canonical = $nameMatches->first();
                        }
                    }

                    if ($canonical === null) {
                        $canonicalId = DB::table('site_rooms')->insertGetId([
                            LegacyStorageContext::column() => LegacyStorageContext::id(),
                            'site_id' => $siteId,
                            'name' => $this->availableCanonicalName(
                                $siteId,
                                (string) $houseRoom->name,
                                $houseRoomId,
                            ),
                            'sort_order' => (int) $houseRoom->sort_order,
                            'linked_room_type' => 'house_room',
                            'linked_room_id' => $houseRoomId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $canonical = (object) ['id' => $canonicalId];
                    }

                    $canonicalId = (int) $canonical->id;
                    $claimedCanonicalIds[$canonicalId] = true;

                    // Remove every stale or duplicate legacy pointer before
                    // publishing the one explicit canonical relationship.
                    DB::table('site_rooms')
                        ->where('linked_room_type', 'house_room')
                        ->where('linked_room_id', $houseRoomId)
                        ->where('id', '!=', $canonicalId)
                        ->update([
                            'linked_room_type' => null,
                            'linked_room_id' => null,
                            'updated_at' => now(),
                        ]);

                    DB::table('site_rooms')->where('id', $canonicalId)->update([
                        'sort_order' => (int) $houseRoom->sort_order,
                        'linked_room_type' => 'house_room',
                        'linked_room_id' => $houseRoomId,
                        'updated_at' => now(),
                    ]);
                    DB::table('site_house_rooms')->where('id', $houseRoomId)->update([
                        'site_room_id' => $canonicalId,
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    private function backfillAssetRooms(): void
    {
        DB::table('assets')
            ->whereNotNull('room_id')
            ->orderBy('id')
            ->chunkById(200, function ($assets): void {
                foreach ($assets as $asset) {
                    $houseRoom = DB::table('site_house_rooms')
                        ->where('id', $asset->room_id)
                        ->first(['site_id', 'site_room_id']);

                    if ($asset->site_id === null
                        || $houseRoom === null
                        || $houseRoom->site_room_id === null
                        || (int) $asset->site_id !== (int) $houseRoom->site_id) {
                        continue;
                    }

                    DB::table('assets')->where('id', $asset->id)->update([
                        'site_room_id' => (int) $houseRoom->site_room_id,
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    private function availableCanonicalName(int $siteId, string $name, int $houseRoomId): string
    {
        if (! DB::table('site_rooms')->where('site_id', $siteId)->where('name', $name)->exists()) {
            return $name;
        }

        $base = Str::limit($name, 220, '').' (Residential '.$houseRoomId.')';
        $candidate = $base;
        $suffix = 2;

        while (DB::table('site_rooms')->where('site_id', $siteId)->where('name', $candidate)->exists()) {
            $candidate = Str::limit($base, 247, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normaliseName(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }
};

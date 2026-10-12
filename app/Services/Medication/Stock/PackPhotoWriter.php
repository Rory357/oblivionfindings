<?php

namespace App\Services\Medication\Stock;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockPhoto;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Writes one staff pack photo (approved P06 Q6; Lane C11). Callers hold the
 * canonical medication lock (MedicationGovernanceScopeService::forMedication)
 * and have checked access. Photos are kept privately and never edited or
 * deleted; a newer photo replaces an older one only in what screens show.
 *
 * The file is written once per call: a transaction retry reuses the path so
 * an attempt never leaves an orphan. The caller deletes `$writtenPath` if its
 * transaction finally fails.
 */
final class PackPhotoWriter
{
    public const RULES = 'file|image|mimes:jpg,jpeg,png,webp|max:8192|dimensions:max_width=12000,max_height=12000';

    public function create(
        User $actor,
        Client $client,
        ClientMedication $medication,
        MedicationStockLot $pack,
        UploadedFile $file,
        string $requestUuid,
        ?string $description,
        bool $packChanged,
        ?string &$writtenPath,
        ?array &$events = null,
    ): MedicationStockPhoto {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A pack photo is written inside the locked stock transaction.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $writtenPath ??= $file->store('medication-stock/'.$pack->id, 'private');
        if (! is_string($writtenPath) || $writtenPath === '') {
            throw new RuntimeException('The photo could not be stored. Keep it on this form and try again.');
        }
        $photo = MedicationStockPhoto::create([
            'medication_stock_lot_id' => $pack->id, 'request_uuid' => $requestUuid,
            'path' => $writtenPath, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'sha256' => $hash,
            'description' => $description, 'pack_changed' => $packChanged,
            'taken_by' => $actor->id, 'taken_at' => now(),
        ]);
        AuditLogger::logOrFail('medications.stock.photo_added', $photo, [
            'actor_id' => $actor->id, 'lot_id' => $pack->id, 'client_medication_id' => $medication->id, 'pack_changed' => $packChanged,
        ]);
        $event = new MedicationEventData(
            siteId: (int) $client->site_id, kind: 'stock.photo_added', subjectType: 'stock_photo', subjectId: (string) $photo->id,
            actorId: $actor->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Medicine pack photo added',
            facts: ['lot_id' => $pack->id, 'request_uuid' => $requestUuid, 'pack_changed' => $packChanged],
            clientId: $client->id, controlled: (bool) $medication->controlled_drug,
        );
        // A caller batching its own events (the receive command) appends this
        // with them, in order; otherwise it is appended now.
        if ($events !== null) {
            $events[] = $event;
        } else {
            app(MedicationEventRecorder::class)->append($event);
        }

        return $photo;
    }
}

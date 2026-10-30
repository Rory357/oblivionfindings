<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillDocument;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Retained, private evidence. Every publication and read checks the owning bill. */
final class BillDocumentService
{
    public function __construct(private readonly MalwareScanner $scanner) {}

    public function upload(User $actor, FinBill $bill, UploadedFile $file, string $key): FinBillDocument
    {
        validator(['file' => $file, 'request_key' => $key], [
            'file' => ['required', 'file', 'max:20480', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'request_key' => ['required', 'string', 'max:100'],
        ])->validate();
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = file_get_contents($path, false, null, 0, 8);
        if (! match ($mime) {
            'application/pdf' => str_starts_with($head, '%PDF-'),
            'image/jpeg' => str_starts_with($head, "\xff\xd8\xff"),
            'image/png' => $head === "\x89PNG\r\n\x1a\n",
            default => false,
        }) {
            throw ValidationException::withMessages(['file' => 'Choose a valid PDF, JPEG or PNG file.']);
        }
        $hash = hash_file('sha256', $path);
        $document = DB::transaction(function () use ($actor, $bill, $file, $key, $mime, $hash): FinBillDocument {
            $locked = $this->editable($actor, $bill);
            $prior = $locked->documents()->where('request_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->sha256, $hash) && $prior->uploaded_by === $actor->id, 409, 'This upload key belongs to another file.');

                return $prior;
            }
            abort_if($locked->documents()->where('state', '!=', 'withdrawn')->count() >= 20, 422, 'A bill may retain up to 20 supporting files.');

            return $locked->documents()->create([
                'uploaded_by' => $actor->id, 'request_key' => $key,
                'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                'mime' => $mime, 'size' => $file->getSize(), 'sha256' => $hash,
                'path' => 'finance/bill-evidence/'.$locked->id.'/'.Str::uuid(), 'state' => 'reserved',
            ]);
        }, 3);
        if (in_array($document->state, ['reserved', 'storage_failed'], true)) {
            try {
                // Store only immutable bytes matching this reservation; concurrent replays are identical.
                $stored = Storage::disk('local')->putFileAs(dirname($document->path), $file, basename($document->path));
            } catch (\Throwable $error) {
                report($error);
                $stored = false;
            }
            DB::transaction(function () use ($actor, $bill, $document, $stored): void {
                $this->editable($actor, $bill);
                FinBillDocument::whereKey($document->id)->whereIn('state', ['reserved', 'storage_failed'])
                    ->update(['state' => $stored === $document->path ? 'stored' : 'storage_failed']);
            }, 3);
        }

        return $this->retry($actor, $bill, $document->fresh());
    }

    public function retry(User $actor, FinBill $bill, FinBillDocument $document): FinBillDocument
    {
        DB::transaction(function () use ($actor, $bill, $document): void {
            $this->editable($actor, $bill);
            abort_unless($document->bill_id === $bill->id, 404);
        }, 3);
        if (! in_array($document->state, ['stored', 'scan_unavailable'], true)) {
            return $document;
        }
        $path = Storage::disk('local')->path($document->path);
        $scan = $this->scanner->scanPath($path, (array) config('it.inbound_mail.malware_scanner', []));
        $state = match ($scan->disposition) {
            MalwareScanDisposition::Clean => 'available',
            MalwareScanDisposition::Infected => 'quarantined',
            default => 'scan_unavailable',
        };
        if ($state === 'available' && (! is_file($path) || ! hash_equals($document->sha256, hash_file('sha256', $path)))) {
            $state = 'quarantined';
        }

        return DB::transaction(function () use ($actor, $bill, $document, $scan, $state): FinBillDocument {
            $this->editable($actor, $bill);
            $locked = FinBillDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->state, ['stored', 'scan_unavailable'], true)) {
                $locked->update(['state' => $state, 'failure_code' => $scan->errorCode,
                    'scanned_at' => $state === 'available' ? now() : null]);
            }

            return $locked;
        }, 3);
    }

    public function withdraw(User $actor, FinBill $bill, FinBillDocument $document): void
    {
        DB::transaction(function () use ($actor, $bill, $document): void {
            $this->editable($actor, $bill);
            abort_unless($document->bill_id === $bill->id, 404);
            $locked = FinBillDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== 'withdrawn') {
                $before = $locked->state;
                $locked->update(['state' => 'withdrawn']);
                AuditLogger::logOrFail('finance.bill.evidence.withdrawn', $bill, [
                    'document_id' => $locked->id, 'actor_id' => $actor->id, 'previous_state' => $before,
                    'sha256' => $locked->sha256,
                ]);
            }
        }, 3);
    }

    public function open(User $actor, FinBill $bill, FinBillDocument $document, bool $inline): StreamedResponse
    {
        Gate::forUser($actor)->authorize('view', $bill);
        abort_unless($document->bill_id === $bill->id, 404);
        abort_unless($document->state === 'available' && $document->scanned_at, 409, 'This file has not passed its virus check.');
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->name, [
            'Content-Type' => $document->mime, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    private function editable(User $actor, FinBill $bill): FinBill
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked = FinBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($current)->authorize('update', $locked);
        abort_unless(in_array($locked->status, ['draft', 'awaiting_approval'], true), 409, 'Supporting documents are retained after approval or cancellation.');

        return $locked;
    }
}

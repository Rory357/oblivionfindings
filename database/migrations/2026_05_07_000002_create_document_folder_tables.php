<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_document_folders')) {
            Schema::create('client_document_folders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();

                $table->unique(['client_id', 'name']);
            });
        }

        if (! Schema::hasTable('site_document_folders')) {
            Schema::create('site_document_folders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();

                $table->unique(['site_id', 'name']);
            });
        }

        $this->backfillClientFolders();
        $this->backfillSiteFolders();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_document_folders');
        Schema::dropIfExists('client_document_folders');
    }

    private function backfillClientFolders(): void
    {
        if (! Schema::hasTable('client_documents') || ! Schema::hasColumn('client_documents', 'folder')) {
            return;
        }

        $now = now();
        $rows = DB::table('client_documents')
            ->select(['client_id', 'folder'])
            ->whereNotNull('folder')
            ->where('folder', '<>', '')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'name' => trim((string) $row->folder),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->filter(fn ($row) => $row['name'] !== '')
            ->unique(fn ($row) => $row['client_id'].'|'.$row['name'])
            ->values();

        if ($rows->isNotEmpty()) {
            DB::table('client_document_folders')->insertOrIgnore($rows->all());
        }
    }

    private function backfillSiteFolders(): void
    {
        if (! Schema::hasTable('site_documents') || ! Schema::hasColumn('site_documents', 'folder')) {
            return;
        }

        $now = now();
        $rows = DB::table('site_documents')
            ->select(['site_id', 'folder'])
            ->whereNotNull('folder')
            ->where('folder', '<>', '')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'site_id' => $row->site_id,
                'name' => trim((string) $row->folder),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->filter(fn ($row) => $row['name'] !== '')
            ->unique(fn ($row) => $row['site_id'].'|'.$row['name'])
            ->values();

        if ($rows->isNotEmpty()) {
            DB::table('site_document_folders')->insertOrIgnore($rows->all());
        }
    }
};

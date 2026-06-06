<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respite_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_bookings', 'code_of_rights_provided')) {
                $table->boolean('code_of_rights_provided')->nullable()->after('consent_authority_evidence');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_to_respite')) {
                $table->boolean('consent_to_respite')->nullable()->after('code_of_rights_provided');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_capacity_basis')) {
                $table->string('consent_capacity_basis')->nullable()->after('consent_to_respite');
            }
            if (! Schema::hasColumn('respite_bookings', 'advocate_offered')) {
                $table->boolean('advocate_offered')->nullable()->after('consent_capacity_basis');
            }
            if (! Schema::hasColumn('respite_bookings', 'rights_format_provided')) {
                $table->string('rights_format_provided')->nullable()->after('advocate_offered');
            }
            if (! Schema::hasColumn('respite_bookings', 'rights_recorded_by')) {
                $table->foreignId('rights_recorded_by')->nullable()->after('rights_format_provided')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('respite_bookings', 'rights_recorded_at')) {
                $table->timestamp('rights_recorded_at')->nullable()->after('rights_recorded_by');
            }
        });

        if (! Schema::hasTable('respite_complaints')) {
            Schema::create('respite_complaints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('source');
                $table->timestamp('received_at');
                $table->string('nature');
                $table->text('details')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->text('resolution')->nullable();
                $table->string('escalated_to_hdc')->nullable();
                $table->string('status')->default('open');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['stay_id', 'status']);
                $table->index(['client_id', 'received_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('respite_complaints');

        if (Schema::hasColumn('respite_bookings', 'rights_recorded_by')) {
            Schema::table('respite_bookings', function (Blueprint $table) {
                $table->dropForeign(['rights_recorded_by']);
            });
        }

        $this->dropColumns('respite_bookings', [
            'code_of_rights_provided',
            'consent_to_respite',
            'consent_capacity_basis',
            'advocate_offered',
            'rights_format_provided',
            'rights_recorded_by',
            'rights_recorded_at',
        ]);
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};

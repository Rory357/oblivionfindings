<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_intake_locks', function (Blueprint $table): void {
            // SHA-256 only: serialize replay/concurrency without retaining email addresses.
            $table->char('key_hash', 64)->primary();
            $table->timestamps();
        });

        $globalPeople = Permission::query()->updateOrCreate(
            ['key' => 'hr.employees.viewAllSites'],
            [
                'description' => 'View employee profiles across all sites when paired with employee view access',
                'group' => 'hr',
                'module' => 'HR',
            ],
        );
        Role::query()
            ->where('name', 'admin')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$globalPeople->id]));

        $clinicalLeadGrant = Permission::query()->updateOrCreate(
            ['key' => 'hr.employees.assignClinicalLead'],
            [
                'description' => 'Grant the Clinical Lead employee access role',
                'group' => 'hr',
                'module' => 'HR',
            ],
        );
        Role::query()
            ->where('name', 'admin')
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$clinicalLeadGrant->id]));
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_intake_locks');

        // Deliberately retain explicit People grant capabilities. Revocation
        // is an access-control decision, not rollback glue for the mutex table.
    }
};

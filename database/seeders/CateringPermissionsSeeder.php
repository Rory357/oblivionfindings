<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CateringPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'catering.recipes.view', 'description' => 'View global recipe library'],
            ['key' => 'catering.recipes.manage', 'description' => 'Create, edit and delete recipes'],
            ['key' => 'catering.products.view', 'description' => 'View global product catalogue'],
            ['key' => 'catering.products.manage', 'description' => 'Create, edit and delete products'],
            ['key' => 'catering.tags.view', 'description' => 'View dietary and allergen tags'],
            ['key' => 'catering.tags.manage', 'description' => 'Create and edit dietary / allergen tags'],
            ['key' => 'sites.meals.view', 'description' => 'View site Meal Planner tab'],
            ['key' => 'sites.meals.plan', 'description' => 'Add, edit, remove planned meals'],
            ['key' => 'sites.meals.inventory.adjust', 'description' => 'Adjust site kitchen inventory'],
            ['key' => 'sites.meals.shopping.manage', 'description' => 'Generate and manage shopping lists'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                ['description' => $perm['description']]
            );
        }

        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            foreach ($permissions as $perm) {
                $p = Permission::where('key', $perm['key'])->first();
                if ($p && ! $admin->permissions()->where('permissions.id', $p->id)->exists()) {
                    $admin->permissions()->attach($p->id);
                }
            }
        }

        $opsManager = Role::where('name', 'operations_manager')->first()
            ?? Role::where('name', 'ops_manager')->first();
        if ($opsManager) {
            foreach ($permissions as $perm) {
                $p = Permission::where('key', $perm['key'])->first();
                if ($p && ! $opsManager->permissions()->where('permissions.id', $p->id)->exists()) {
                    $opsManager->permissions()->attach($p->id);
                }
            }
        }

        $houseLead = Role::where('name', 'house_lead')->first()
            ?? Role::where('name', 'house_manager')->first();
        if ($houseLead) {
            $houseLeadKeys = [
                'catering.recipes.view',
                'catering.products.view',
                'catering.tags.view',
                'sites.meals.view',
                'sites.meals.plan',
                'sites.meals.inventory.adjust',
                'sites.meals.shopping.manage',
            ];
            foreach ($houseLeadKeys as $key) {
                $p = Permission::where('key', $key)->first();
                if ($p && ! $houseLead->permissions()->where('permissions.id', $p->id)->exists()) {
                    $houseLead->permissions()->attach($p->id);
                }
            }
        }

        $careWorker = Role::where('name', 'care_worker')->first()
            ?? Role::where('name', 'support_worker')->first();
        if ($careWorker) {
            $careWorkerKeys = [
                'catering.recipes.view',
                'catering.products.view',
                'catering.tags.view',
                'sites.meals.view',
                'sites.meals.plan',
                'sites.meals.inventory.adjust',
            ];
            foreach ($careWorkerKeys as $key) {
                $p = Permission::where('key', $key)->first();
                if ($p && ! $careWorker->permissions()->where('permissions.id', $p->id)->exists()) {
                    $careWorker->permissions()->attach($p->id);
                }
            }
        }

        $this->command?->info('Catering permissions seeded successfully!');
    }
}

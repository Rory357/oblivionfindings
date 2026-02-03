<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AssetCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Vehicle',
            'Mobility Equipment',
            'Medical Device',
            'Communication Aid',
            'Safety Equipment',
            'Transport Accessory',
            'IT Equipment',
        ];

        foreach ($categories as $name) {
            AssetCategory::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}

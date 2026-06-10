<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrDocumentFactory extends Factory
{
    protected $model = HrDocument::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'employee_profile_id' => HrEmployeeProfile::factory(),
            'title' => fake()->sentence(3),
            'category' => fake()->randomElement(['contract', 'policy', 'training', 'payroll', 'other']),
            'folder' => 'employee-records',
            'storage_disk' => 'private',
            'storage_path' => 'hr/documents/'.fake()->uuid().'.pdf',
            'original_name' => fake()->slug().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(12000, 250000),
            'is_restricted' => false,
            'generated_from_template' => false,
            'sent_to_employee' => false,
            'signed_by_employee' => false,
            'created_by' => User::factory(),
            'uploaded_by' => User::factory(),
        ];
    }
}

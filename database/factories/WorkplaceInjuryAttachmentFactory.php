<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkplaceInjuryAttachmentFactory extends Factory
{
    protected $model = WorkplaceInjuryAttachment::class;

    public function definition(): array
    {
        return [
            'workplace_injury_id' => WorkplaceInjury::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'public',
            'original_name' => 'medical-certificate.pdf',
            'path' => 'workplace_injury_attachments/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 500_000),
            'kind' => fake()->randomElement(['medical_cert', 'acc_form', 'rtw_clearance', 'photo', 'document']),
            'notes' => null,
            'alt_text' => null,
        ];
    }
}

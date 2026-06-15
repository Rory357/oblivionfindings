<?php

use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function baseExpenseItem(array $overrides = []): array
{
    return array_merge([
        'description' => 'Taxi to client site',
        'category' => 'travel',
        'amount' => 42.50,
        'expense_date' => '2026-03-01',
    ], $overrides);
}

test('an uploaded receipt is stored on the private disk and recorded on the item', function () {
    Storage::fake('private');

    $this->actingAs($this->hr)
        ->post('/hr/compensation/expenses', [
            'title' => 'March Client Visit',
            'items' => [
                baseExpenseItem([
                    'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
                ]),
            ],
        ])
        ->assertRedirect();

    $claim = HrExpenseClaim::query()->where('title', 'March Client Visit')->firstOrFail();
    $item = $claim->items()->firstOrFail();

    expect($item->receipt_path)->not->toBeNull();
    expect($item->receipt_path)->toStartWith('hr/expense-receipts/');
    Storage::disk('private')->assertExists($item->receipt_path);
});

test('a claim with no receipt leaves receipt_path null', function () {
    Storage::fake('private');

    $this->actingAs($this->hr)
        ->post('/hr/compensation/expenses', [
            'title' => 'No Receipt Claim',
            'items' => [baseExpenseItem()],
        ])
        ->assertRedirect();

    $item = HrExpenseClaim::query()
        ->where('title', 'No Receipt Claim')
        ->firstOrFail()
        ->items()
        ->firstOrFail();

    expect($item->receipt_path)->toBeNull();
});

test('a receipt with a disallowed mime type is rejected', function () {
    Storage::fake('private');

    $this->actingAs($this->hr)
        ->post('/hr/compensation/expenses', [
            'title' => 'Bad Mime Claim',
            'items' => [
                baseExpenseItem([
                    'receipt' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
                ]),
            ],
        ])
        ->assertSessionHasErrors('items.0.receipt');

    expect(HrExpenseClaim::query()->where('title', 'Bad Mime Claim')->exists())->toBeFalse();
});

test('a receipt larger than the size limit is rejected', function () {
    Storage::fake('private');

    $this->actingAs($this->hr)
        ->post('/hr/compensation/expenses', [
            'title' => 'Oversize Claim',
            'items' => [
                baseExpenseItem([
                    // 6 MB > the 5 MB (5120 KB) limit.
                    'receipt' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
                ]),
            ],
        ])
        ->assertSessionHasErrors('items.0.receipt');

    expect(HrExpenseClaim::query()->where('title', 'Oversize Claim')->exists())->toBeFalse();
});

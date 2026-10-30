<?php

require __DIR__.'/inspect-delivery.php';

use App\Models\Client;
use App\Models\ServiceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
DB::beginTransaction();
try {
    $siteId = Client::findOrFail(2)->site_id;
    $context = ServiceContext::create(['name' => 'Fictional stale-write check', 'type' => 'home_support', 'is_active' => true]);
    foreach (['status' => 'active', 'site_id' => $siteId, 'service_context_id' => $context->id, 'service_start_date' => '2026-10-01'] as $field => $value) {
        $person = Client::create(['first_name' => 'Fictional stale-write check', 'last_name' => $field]);
        $stale = $person->fresh();
        DB::table('clients')->where('id', $person->id)->update([$field => $value]);
        $before = $person->fresh()->only(['status', 'site_id', 'service_context_id', 'service_start_date']);
        try {
            $stale->forceFill(['status' => 'inactive'])->saveQuietly();
            throw new RuntimeException('Stale write accepted: '.$field);
        } catch (ValidationException $e) {
            $check(isset($e->errors()['status']) && $person->fresh()->only(array_keys($before)) == $before, 'Stale '.$field.' snapshot rejects protected quiet save without changing service state');
        }
    }
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'Current write']);
    $person->update(['site_id' => $siteId]);
    $check((int) $person->fresh()->site_id === (int) $siteId, 'Current unstarted profile can receive a site');
    $person->update(['service_context_id' => $context->id, 'first_name' => 'Fictional identity and scope']);
    $check($person->fresh()->first_name === 'Fictional identity and scope' && (int) $person->fresh()->service_context_id === $context->id, 'Combined identity and scope save follows the shared identity lock order');
    $stale = $person->fresh();
    DB::table('clients')->where('id', $person->id)->update(['status' => 'active']);
    $stale->update(['preferred_name' => 'Chosen name']);
    $check($person->fresh()->status === 'active' && $person->fresh()->preferred_name === 'Chosen name', 'Unrelated stale edit preserves concurrent service state');
    $unstarted = Client::create(['first_name' => 'Fictional', 'last_name' => 'Case variant']);
    try {
        $unstarted->update(['status' => ' ACTIVE ']);
        throw new RuntimeException('Variant activation accepted');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 422 && $unstarted->fresh()->status === 'onboarding', 'Active case and whitespace variants cannot bypass commencement');
    }
    $historical = Client::factory()->create(['site_id' => $siteId, 'status' => 'active', 'service_start_date' => '2025-03-04']);
    $historical->update(['status' => 'inactive']);
    $check($historical->fresh()->status === 'inactive' && $historical->fresh()->service_start_date->toDateString() === '2025-03-04', 'Equivalent date casting does not falsely reject a current historical profile');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}

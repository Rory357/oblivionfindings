<?php

require __DIR__.'/inspect-delivery.php';

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientOnboardingController;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientOnboardingOverride;
use App\Models\ClientPersonalAsset;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\User;
use App\Services\Clients\ClientProfileChecklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    auth()->login($actor);
    $existing = Client::findOrFail(2);
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'Checklist', 'site_id' => $existing->site_id]);
    $build = fn ($p) => app(ClientProfileChecklist::class)->build($p->fresh()->loadCount(ClientProfileChecklist::COUNTS));
    $item = fn ($p, $key) => collect($build($p)['items'])->firstWhere('key', $key);
    $check($build($person)['total'] === 8 && $build($person)['completed'] === 0, 'New empty profile uses eight incomplete record categories');
    $plan = CarePlan::create(['client_id' => $person->id, 'title' => 'Private care marker', 'plan_type' => 'support', 'status' => 'draft']);
    $check($item($person, 'history')['has_data'], 'Saved canonical care plan is recognised even before approval');
    $check($person->fresh()->status === 'onboarding' && $person->fresh()->service_start_date === null, 'Record presence cannot activate services');
    $check(! str_contains(json_encode($build($person)), 'Private care marker'), 'Checklist reveals no care-plan contents');
    $plan->delete();
    $check(! $item($person, 'history')['has_data'], 'Deleted care plans do not satisfy record presence');
    $kin = NextOfKin::create(['client_id' => $person->id, 'user_id' => $actor->id, 'relationship' => 'Fictional relative']);
    $check($item($person, 'next_of_kin')['has_data'], 'Canonical next-of-kin record counts without legacy portal membership');
    $kin->delete();
    $check(! $item($person, 'next_of_kin')['has_data'], 'Deleted next-of-kin record does not count');
    $asset = ClientPersonalAsset::create(['client_id' => $person->id, 'name' => 'Fictional belongings', 'category' => 'other']);
    $check($item($person, 'personal_assets')['has_data'], 'Personal belongings count consistently');
    $asset->delete();
    $check(! $item($person, 'personal_assets')['has_data'], 'Deleted personal belongings do not count');
    $document = ClientDocument::create(['client_id' => $person->id, 'title' => 'Private document marker', 'storage_disk' => 'local', 'storage_path' => 'fictional-not-a-real-file', 'original_name' => 'fictional.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
    $check($item($person, 'documents')['has_data'] && ! str_contains(json_encode($build($person)), 'Private document marker'), 'Only canonical document presence is projected, with no file metadata');
    $document->delete();
    $detail = new ReflectionMethod(ClientController::class, 'buildOnboardingChecklist');
    $summary = new ReflectionMethod(ClientController::class, 'buildOnboardingSummaryFromCounts');
    $controller = app(ClientController::class);
    $d = $detail->invoke($controller, $person->fresh());
    unset($d['items']);
    $check($d === $summary->invoke($controller, $person->fresh()->loadCount(ClientProfileChecklist::COUNTS)), 'List and detail have identical totals and completion');
    $request = Request::create('/', 'POST', ['checked' => true]);
    $request->setUserResolver(fn () => $actor->fresh());
    app(ClientOnboardingController::class)->toggle($request, $person, 'personal_assets');
    $check($item($person, 'personal_assets')['override'] && $item($person, 'personal_assets')['complete'], 'Authorized personal-belongings not-applicable action saves');
    foreach (array_keys(ClientProfileChecklist::LABELS) as $key) {
        ClientOnboardingOverride::updateOrCreate(['client_id' => $person->id, 'key' => $key], ['value' => true, 'updated_by' => $actor->id]);
    }
    $check($build($person)['percent'] === 100 && $person->fresh()->status === 'onboarding' && $person->fresh()->service_start_date === null, 'Even a fully overridden checklist never authorizes commencement');
    foreach (['clients.update', 'clients.onboarding.manage'] as $key) {
        if ($permission = Permission::where('key', $key)->first()) {
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
    }
    auth()->login($actor->fresh());
    try {
        app(ClientOnboardingController::class)->toggle($request, $person, 'personal_assets');
        throw new RuntimeException('Unauthorized checklist change accepted');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Personal-belongings override requires existing checklist permission');
    }
    $check($item($existing, 'history')['has_data'], 'Actual fictional handover recognises its canonical care plan');
    $check(! $item($existing, 'documents')['has_data'], 'Private referral evidence is not relabelled as profile documents');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}

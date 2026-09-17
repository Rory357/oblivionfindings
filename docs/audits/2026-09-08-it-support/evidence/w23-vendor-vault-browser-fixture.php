<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteVendor;
use App\Models\User;
use App\Services\Sites\SiteCredentialAccess;
use App\Services\Sites\SiteCredentialHistory;
use App\Services\Sites\VendorAgreementFiles;
use App\Services\Sites\VendorAgreements;
use App\Services\Sites\VendorCommercialAccess;
use Database\Seeders\VendorVaultPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** New disposable schema only; never invoked by application routes or seeders. */
function w23BrowserCreateVendorFixtures(array $context, array $workspace): array
{
    w06BrowserRequire(($context['vendor_fixtures'] ?? false) && ($context['workspace_fixtures'] ?? false)
        && DB::scalar('SELECT DATABASE()') === 'oblivion_it_draft_browser_'.$context['token']
        && SiteVendor::count() === 0 && SiteCredential::count() === 0,
        'Vendor fixtures require the empty owned vault and the exact-file Workspace scanner.');
    app(VendorVaultPermissionsSeeder::class)->run();
    return DB::transaction(function () use ($context, $workspace): array {
        $source = file_get_contents(base_path('tests/e2e/helpers.ts'));
        w06BrowserRequire(preg_match("/password = '([^']+)'/", $source, $match) === 1, 'Repository synthetic login convention is unavailable.');
        $password = $match[1];
        unset($source, $match);
        $house = Site::factory()->create(['name' => 'W23 synthetic house', 'type' => 'house',
            'address_line_1' => 'Synthetic local verification', 'city' => 'Auckland', 'region' => 'Auckland',
            'phone' => null, 'email' => null, 'notes' => 'Disposable fixture only.', 'is_active' => true, 'archived' => false]);
        $actors = [];
        $grants = [
            'finance' => ['sites.viewAny', 'sites.type.house.view', 'vendors.view', 'vendors.manage', 'credentials.view', 'credentials.reveal', 'credentials.copy', 'credentials.manage', 'credentials.audit', ItKbAccessService::AUTHOR],
            'staff' => ['sites.viewAny', 'sites.type.house.view', 'it.request'],
            'auditor' => ['sites.viewAny', 'sites.type.house.view', 'vendors.view', 'credentials.view'],
            'publisher' => ['sites.viewAny', 'sites.type.house.view', 'vendors.view', 'credentials.view', ItKbAccessService::REVIEW],
        ];
        foreach ($grants as $key => $keys) {
            $role = Role::create(['name' => 'w23-browser-'.$key, 'label' => 'Synthetic vendor '.$key, 'level' => 10, 'type' => 'custom']);
            $permissions = Permission::whereIn('key', $keys)->pluck('id');
            w06BrowserRequire($permissions->count() === count($keys), 'Vendor fixture permissions are missing.');
            $role->permissions()->attach($permissions);
            $actor = User::factory()->withoutTwoFactor()->create(['name' => 'W23 synthetic '.$key,
                'email' => 'w23-'.$key.'@demo.test', 'password' => $password, 'role' => in_array($key, ['staff', 'publisher'], true) ? 'support_worker' : $key,
                'approved_at' => now(), 'email_verified_at' => now(), 'remember_token' => null, 'landing_route_preference' => '/vendors']);
            $roles = [$role->id];
            if (in_array($key, ['finance', 'auditor'], true)) $roles[] = Role::where('name', $key)->firstOrFail()->id;
            $actor->roles()->sync($roles);
            HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $house->id,
                'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null]);
            $actors[$key] = $actor->fresh();
        }
        unset($password);
        $finance = \App\Domain\Finance\Models\FinVendor::factory()->create(['name' => 'W23 synthetic Finance supplier', 'vendor_type' => 'supplier', 'email' => null, 'phone' => null]);
        $vendor = SiteVendor::create(['site_id' => $house->id, 'company_name' => 'W23 synthetic support supplier',
            'service_type' => 'software', 'preferred_contact_method' => 'email', 'is_active' => true,
            'contact_name' => 'Synthetic support desk', 'email' => 'support@vendor.example.test', 'phone' => '0800 000 000',
            'owner_user_id' => $actors['finance']->id, 'supplied_services' => 'Synthetic support and licence coverage.',
            'finance_vendor_id' => $finance->id, 'visibility' => 'site',
            'related_records' => [['type' => 'service', 'id' => $workspace['service_id'], 'relation' => 'supports']]]);
        $credential = SiteCredential::create(['site_id' => $house->id, 'vendor_id' => $vendor->id,
            'label' => 'W24 synthetic shared access', 'credential_type' => 'password',
            'encrypted_value' => Crypt::encryptString(bin2hex(random_bytes(24))),
            'totp_secret_encrypted' => Crypt::encryptString((new \PragmaRX\Google2FA\Google2FA)->generateSecretKey()),
            'requires_reauth' => true, 'is_shareable' => false, 'house_staff_access' => true, 'visibility' => 'site', 'lock_version' => 1]);
        app(SiteCredentialHistory::class)->retain($credential, $actors['finance'], 'created');
        $agreement = app(VendorAgreements::class)->save($actors['finance'], $vendor, [
            'title' => 'W23 synthetic support agreement', 'kind' => 'support', 'reference' => 'SYNTHETIC-ONLY',
            'owner_user_id' => $actors['finance']->id, 'visibility' => 'site', 'notice_days' => 30,
            'starts_on' => today()->subYear()->toDateString(), 'renews_on' => today()->addDays(10)->toDateString(),
            'currency' => 'NZD', 'amount' => '1200', 'terms' => 'Synthetic restricted commercial terms.',
            'evidence' => 'Disposable verification agreement; no actual commitment.',
        ]);
        $path = $context['root'].'/knowledge-upload-inputs/w22-synthetic-1.pdf';
        app(VendorAgreementFiles::class)->upload($actors['finance'], $agreement, new UploadedFile($path, 'synthetic-support-agreement.pdf', null, null, true), 1, null);
        $file = $agreement->files()->firstOrFail();
        // Keep the independent Knowledge actors and their reference access unchanged.
        // A publisher must be able to resolve every related record being approved.
        $reviewer = $actors['publisher'];
        $lifecycle = app(ItKbLifecycleService::class);
        $guide = $lifecycle->create($actors['finance'], [
            'title' => 'W24 synthetic access runbook', 'category' => 'network',
            'body' => 'Use the protected shared access reference. Values never belong in this document.',
            'document_type' => 'runbook', 'structured_content' => ['procedure' => 'Open the masked reference and confirm your identity.', 'verification' => 'Check the simulated service only.'],
            'audience' => 'specific_sites', 'site_scope' => [$house->id], 'owner_user_id' => $actors['finance']->id,
            'related_records' => [['type' => 'credential', 'id' => $credential->id, 'relation' => 'supports'], ['type' => 'vendor', 'id' => $vendor->id, 'relation' => 'supports']],
            'review_due_at' => today()->addMonth()->toDateString(),
        ]);
        $lifecycle->submitForReview($guide, $actors['finance'], ['lock_version' => $guide->fresh()->lock_version]);
        $lifecycle->publish($guide, $reviewer, ['lock_version' => $guide->fresh()->lock_version]);
        $vendor->update(['related_records' => [...$vendor->related_records, ['type' => 'article', 'id' => $guide->id, 'relation' => 'documents']]]);
        w06BrowserRequire(app(VendorCommercialAccess::class)->capable($actors['finance'], 'manage')
            && ! app(VendorCommercialAccess::class)->capable($actors['auditor'])
            && ! app(VendorCommercialAccess::class)->capable($actors['staff'])
            && app(SiteCredentialAccess::class)->query($actors['staff'], 'reveal')->whereKey($credential->id)->exists(), 'Vendor fixture access boundaries differ.');
        return ['synthetic_only' => true, 'house_id' => $house->id, 'vendor_id' => $vendor->id, 'finance_vendor_id' => $finance->id,
            'agreement_id' => $agreement->id, 'file_id' => $file->id, 'credential_id' => $credential->id, 'runbook_id' => $guide->id,
            'actors' => array_map(fn (User $actor) => ['id' => $actor->id, 'login' => $actor->email], $actors),
            'secret_values_emitted' => false];
    });
}

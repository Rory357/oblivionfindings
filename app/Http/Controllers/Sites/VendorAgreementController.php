<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\SiteVendor;
use App\Models\User;
use App\Models\VendorAgreement;
use App\Models\VendorAgreementFile;
use App\Services\Sites\VendorAgreementFiles;
use App\Services\Sites\VendorAgreements;
use App\Services\Sites\VendorCommercialAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class VendorAgreementController extends Controller
{
    public function index(Request $request, VendorCommercialAccess $access)
    {
        abort_unless($access->capable($request->user()), 404);
        $ready = Schema::hasTable('vendor_agreements');
        $items = $ready ? $access->query($request->user())->with('vendor:id,company_name')->orderBy('renews_on')->get()
            ->map(fn ($a) => $this->payload($a, $request->user()) + ['vendor_name' => $a->vendor->company_name]) : collect();
        return inertia('sites/vendors/agreements', ['agreements' => $items, 'ready' => $ready]);
    }

    public function store(Request $request, SiteVendor $vendor, VendorAgreements $agreements)
    {
        $saved = $agreements->save($request->user(), $vendor, $request->all());
        return response()->json(['id' => $saved->id], 201);
    }

    public function update(Request $request, VendorAgreement $agreement, VendorAgreements $agreements)
    {
        $saved = $agreements->save($request->user(), $agreement->vendor, $request->all(), $agreement);
        return response()->json(['id' => $saved->id]);
    }

    public function transition(Request $request, VendorAgreement $agreement, VendorAgreements $agreements)
    {
        $agreements->transition($request->user(), $agreement, $request->all());
        return response()->json(['ok' => true]);
    }

    public function files(Request $request, VendorAgreement $agreement, VendorCommercialAccess $access)
    {
        $access->authorize($request->user(), $agreement);
        return response()->json(['files' => $agreement->files()->orderByDesc('id')->get()->map(fn ($f) => [
            'id' => $f->id, 'series_id' => $f->series_id, 'version' => $f->version, 'name' => $f->name,
            'size' => $f->size, 'state' => $f->state, 'created_at' => $f->created_at->toIso8601String(),
            'href' => $f->state === 'ready' ? "/vendor-agreements/{$agreement->id}/files/{$f->id}/open" : null,
        ]), 'events' => DB::table('vendor_agreement_events as events')->leftJoin('users', 'users.id', '=', 'events.user_id')->where('agreement_id', $agreement->id)->orderByDesc('events.id')->limit(100)->get(['events.id', 'action', 'evidence', 'events.created_at', 'users.name as actor_name'])])->header('Cache-Control', 'no-store, private');
    }

    public function upload(Request $request, VendorAgreement $agreement, VendorAgreementFiles $files, VendorCommercialAccess $access)
    {
        $access->authorize($request->user(), $agreement, 'manage');
        $data = $request->validate(['file' => 'required|file|max:20480', 'lock_version' => 'required|integer|min:1', 'replace_file_id' => 'nullable|integer']);
        $files->upload($request->user(), $agreement, $request->file('file'), $data['lock_version'], $data['replace_file_id'] ?? null);
        return response()->json(['ok' => true]);
    }

    public function open(Request $request, VendorAgreement $agreement, VendorAgreementFile $file, VendorCommercialAccess $access)
    {
        $access->authorize($request->user(), $agreement);
        abort_unless($file->agreement_id === $agreement->id && $file->state === 'ready', 404);
        abort_unless(Storage::disk('private')->exists($file->path), 404);
        abort_unless(hash_equals($file->sha256, hash_file('sha256', Storage::disk('private')->path($file->path))), 404);
        app(VendorAgreements::class)->event($agreement, $request->user(), 'file_opened');
        // Word and PDF originals open through the browser/installed viewer. No
        // remote conversion or publicly served preview can disclose a contract.
        return Storage::disk('private')->download($file->path, $file->name, [
            'Content-Type' => $file->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function payload(VendorAgreement $agreement, User $actor): array
    {
        $assetVisible = $agreement->asset_id && app(\App\Domain\SecurityDevices\Services\SecurityDevicesAccessService::class)->accessibleAssets($actor)->whereKey($agreement->asset_id)->exists();
        return $agreement->only(['id', 'vendor_id', 'site_id', 'owner_user_id', 'visibility', 'kind', 'title', 'reference', 'status', 'notice_days', 'amount', 'currency', 'terms', 'evidence', 'lock_version']) + [
            'asset_id' => $assetVisible ? $agreement->asset_id : null,
            'starts_on' => $agreement->starts_on?->toDateString(), 'renews_on' => $agreement->renews_on?->toDateString(),
            'followup' => DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->first(['id', 'due_on', 'status', 'reviewed_at']),
        ];
    }
}

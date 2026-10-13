<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HubResourceController extends Controller
{
    public function report(Request $request, HubPage $page, HubAccess $access)
    {
        $data = $request->validate(['issue' => 'required|in:Out of date,Incorrect information,Broken link,Other', 'note' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $page, $access, $data) {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $access->assertRead($request->user(), $page);
            abort_unless(in_array($page->kind, ['page', 'knowledge'], true), 422);
            $body = json_encode([...$data, 'revision_id' => $revision->id, 'resolved' => false, 'response' => '', 'version' => 1]);
            $match = ['hub_page_id' => $page->id, 'user_id' => $request->user()->id, 'kind' => 'resource_report', 'body' => $body];
            if (! DB::table('hub_interactions')->where($match)->exists()) {
                DB::table('hub_interactions')->insert([...$match, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(['saved' => true]);
    }

    public function resolve(Request $request, HubPage $page, int $report, HubAccess $access)
    {
        $data = $request->validate(['resolved' => 'required|boolean', 'response' => 'required|string|max:2000', 'version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $page, $report, $access, $data) {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $access->assertEdit($request->user(), $page);
            $item = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'resource_report')->where('id', $report)->first();
            abort_unless($item, 404);
            $body = json_decode($item->body, true);
            abort_unless($body['version'] === $data['version'], 409, 'This report has changed. Reload before updating it.');
            DB::table('hub_interactions')->where('id', $report)->update(['body' => json_encode([...$body, ...$data, 'version' => $body['version'] + 1]), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'company_hub.resource_report_reviewed', 'auditable_type' => HubPage::class, 'auditable_id' => $page->id, 'meta' => ['report_id' => $report, 'resolved' => $data['resolved']]]);
        });

        return response()->json(['saved' => true]);
    }
}

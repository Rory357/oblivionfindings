<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubResourceReports;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HubResourceController extends Controller
{
    public function index(Request $request, HubPage $page, HubResourceReports $reports)
    {
        $data = $request->validate(['before' => 'nullable|integer|min:1|max:2147483647', 'user_id' => 'prohibited']);

        return response()->json($reports->read($request->user(), $page, isset($data['before']) ? (int) $data['before'] : null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, HubPage $page, string $report, HubResourceReports $reports)
    {
        $request->validate(['user_id' => 'prohibited']);
        $id = filter_var($report, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        abort_unless($id !== false, 404);

        return response()->json($reports->detail($request->user(), $page, $id))->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, HubPage $page, HubAccess $access, HubResourceReports $reports)
    {
        $data = $request->validate([
            'issue' => ['required', Rule::in(HubResourceReports::ISSUES)], 'note' => 'required|string|max:2000',
            'actor_id' => 'sometimes|required|integer|min:1|max:2147483647', 'revision_id' => 'sometimes|required|integer|min:1|max:2147483647',
            'user_id' => 'prohibited',
        ]);
        $payload = DB::transaction(function () use ($request, $page, $access, $reports, $data) {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $revision = $access->assertRead($request->user(), $page);
            abort_unless(in_array($page->kind, ['page', 'knowledge'], true), 422);
            abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $request->user()->id, 409, 'Your signed-in account changed. Reload the resource before sending a correction.');
            abort_if(isset($data['revision_id']) && (int) $data['revision_id'] !== (int) $revision->id, 409, 'This resource has changed. Reload its current version before sending a correction.');
            $body = json_encode(['issue' => $data['issue'], 'note' => $data['note'], 'revision_id' => $revision->id, 'resolved' => false, 'response' => '', 'version' => 1]);
            $match = ['hub_page_id' => $page->id, 'user_id' => $request->user()->id, 'kind' => 'resource_report', 'body' => $body];
            $id = DB::table('hub_interactions')->where($match)->value('id')
                ?? DB::table('hub_interactions')->insertGetId([...$match, 'created_at' => now(), 'updated_at' => now()]);

            return $reports->detail($request->user(), $page, (int) $id);
        });

        return response()->json(['saved' => true, ...$payload])->header('Cache-Control', 'private, no-store');
    }

    public function resolve(Request $request, HubPage $page, string $report, HubAccess $access)
    {
        $reportId = filter_var($report, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        abort_unless($reportId !== false, 404);
        $changesReviewDate = $request->exists('review_on');
        $data = $request->validate([
            'resolved' => 'required|boolean', 'response' => 'required|string|max:2000', 'version' => 'required|integer|min:1|max:2147483647',
            'actor_id' => ($changesReviewDate ? 'required' : 'sometimes|required').'|integer|min:1|max:2147483647',
            'review_on' => 'sometimes|nullable|date_format:Y-m-d',
            'page_version' => [Rule::requiredIf($changesReviewDate), Rule::prohibitedIf(! $changesReviewDate), 'integer', 'min:1', 'max:2147483647'],
        ]);
        $payload = DB::transaction(function () use ($request, $page, $reportId, $access, $data, $changesReviewDate) {
            $actor = $request->user()?->fresh();
            abort_unless($actor, 404);
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $access->assertEdit($actor, $page);
            abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $actor->id, 409, 'Your signed-in account changed. Reload before reviewing this correction.');
            $item = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('kind', 'resource_report')->where('id', $reportId)->lockForUpdate()->first();
            abort_unless($item, 404);
            $body = json_decode($item->body, true);
            abort_unless(is_array($body) && is_int($body['version'] ?? null) && $body['version'] > 0, 404);
            abort_unless($body['version'] === (int) $data['version'], 409, 'This report has changed. Reload before updating it.');
            if ($changesReviewDate) {
                abort_if($page->archived_at, 409, 'Restore this archived resource before changing its next review date.');
                // Save the current full draft, never a client-provided content snapshot.
                // The outer transaction also rolls back the date if report/audit writing fails.
                $page = app(HubPublishing::class)->save($actor, $page, [...$page->draft, 'review_on' => $data['review_on']], (int) $data['page_version']);
            }
            $reportVersion = $body['version'] + 1;
            DB::table('hub_interactions')->where('id', $reportId)->update([
                'body' => json_encode([...$body, 'resolved' => (bool) $data['resolved'], 'response' => $data['response'], 'version' => $reportVersion]),
                'updated_at' => now(),
            ]);
            AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.resource_report_reviewed', 'auditable_type' => HubPage::class, 'auditable_id' => $page->id, 'meta' => ['report_id' => $reportId, 'resolved' => $data['resolved']]]);

            return ['saved' => true, 'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id, 'report_id' => $reportId,
                'report_version' => $reportVersion, 'page_version' => (int) $page->draft_version, 'review_on' => $page->draft['review_on'] ?? null];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}

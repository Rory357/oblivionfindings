<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The controlled export of an investigation case (approved 22 October 2026).
 *
 * Only an investigator's own active grant can be exported, and only the
 * conversations granted under it. Every page carries a watermark naming
 * the case, the investigator and the export time, and each export is
 * logged per conversation in `chat_investigation_events` with the file's
 * SHA-256 hash, so a copy that surfaces later can be traced. Attachments
 * are listed by name only; their contents never leave Chat this way.
 */
final class ChatInvestigationExport
{
    /** A case longer than this is exported one conversation at a time. */
    public const MESSAGE_LIMIT = 5000;

    public const WATERMARK = 'Confidential — investigation record';

    public function __construct(private readonly ChatInvestigations $investigations) {}

    /**
     * @param  'conversation'|'case'  $scope
     * @return array{content: string, filename: string, sha256: string, export_id: string}
     */
    public function export(User $actor, int $grantId, string $scope, ?string $ip = null): array
    {
        $grant = $this->investigations->activeGrant($actor, $grantId, $ip, 'export');
        $case = $scope === 'case' ? $this->investigations->caseGrants($actor, $grant) : collect([$grant]);
        $included = $case->filter(fn ($row) => $row->revoked_at === null && CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture())->values();
        $conversations = OpsConversation::whereIn('id', $included->pluck('conversation_id'))->with('participants.user:id,name')->get()->keyBy('id');

        $remaining = self::MESSAGE_LIMIT;
        $sections = $included->map(function ($row) use ($conversations, &$remaining) {
            $conversation = $conversations[$row->conversation_id] ?? abort(404, 'A conversation in this case no longer exists.');
            $this->investigations->assertInvestigable($conversation);
            $messages = $this->investigations->fullRecord($conversation, $remaining);
            abort_if($messages === null, 422, 'This export would include more than '.number_format(self::MESSAGE_LIMIT).' messages. Export the conversations one at a time.');
            $remaining -= count($messages);

            return ['grant_id' => (int) $row->id, 'conversation' => $this->investigations->describe($conversation), 'messages' => $messages];
        });

        $now = CarbonImmutable::now('UTC');
        $exportId = (string) Str::uuid();
        $zone = config('app.worker_timezone', 'Pacific/Auckland');
        $exportedAt = $now->setTimezone($zone)->format('j M Y, g:i a T');
        $content = $this->render([
            'caseReference' => $grant->case_reference,
            'investigator' => $actor->name,
            'exportedAt' => $exportedAt,
            'exportId' => $exportId,
            'expiresAt' => CarbonImmutable::parse($grant->expires_at, 'UTC')->setTimezone($zone)->format('j M Y, g:i a T'),
            'sections' => $sections->all(),
            'omitted' => $case->count() - $included->count(),
            'zone' => $zone,
            'watermark' => self::WATERMARK,
        ]);
        $hash = hash('sha256', $content);
        $filename = 'chat-investigation-'.(Str::slug($grant->case_reference) ?: 'case').'-'.$now->setTimezone($zone)->format('Ymd-His').'.pdf';

        DB::transaction(function () use ($actor, $sections, $grant, $exportId, $hash, $content, $filename, $scope, $ip): void {
            $conversationIds = $sections->pluck('conversation.id')->all();
            foreach ($sections as $section) {
                $this->investigations->log($actor, 'case_exported', $section['conversation']['id'], $grant->case_reference, grantId: $section['grant_id'], meta: [
                    'export_id' => $exportId, 'scope' => $scope, 'conversation_ids' => $conversationIds,
                    'messages' => count($section['messages']), 'sha256' => $hash, 'bytes' => strlen($content), 'filename' => $filename,
                ], ip: $ip);
            }
        });

        return ['content' => $content, 'filename' => $filename, 'sha256' => $hash, 'export_id' => $exportId];
    }

    /** The watermarked PDF. Fixed-position elements repeat on every page; page numbers come from the canvas. */
    private function render(array $data): string
    {
        $pdf = Pdf::loadView('chat.investigation-export', $data)->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(500, 818, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.35, 0.35, 0.4]);

        return $dompdf->output(['compress' => 1]);
    }
}

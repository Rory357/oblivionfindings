<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCommand;
use App\Domain\Referrals\Services\ReferralEvidenceFiles;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ReferralEvidenceController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralEvidenceFiles $files) {}

    private function referral(Request $request, string $reference): CareReferral
    {
        return $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
    }

    public function index(Request $request, string $reference): JsonResponse
    {
        $referral = $this->referral($request, $reference);
        $data = $request->validate(['audience' => 'required|in:assessment,funding', 'page' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);
        abort_unless($this->access->audience($request->user(), $data['audience']), 403);
        // Titles are encrypted; bounded chronological pagination avoids plaintext search indexes.
        $documents = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', $data['audience'])
            ->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($document) => $this->files->summary($document));

        return response()->json(['documents' => $documents])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $referral = $this->referral($request, $reference);
        $data = $request->validate(['version' => 'required|integer|min:1', 'file' => 'required|file']);
        $document = $this->files->upload($request->user(), $referral, (int) $data['version'], $request->all(), $request->file('file'));

        return response()->json(['saved' => true, 'document' => $this->files->summary($document)], 201)->header('Cache-Control', 'no-store, private');
    }

    public function rescan(Request $request, string $reference, int $evidence): JsonResponse
    {
        $referral = $this->referral($request, $reference);
        $version = (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
        $this->files->rescan($request->user(), $referral->id, $version, $evidence);

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, string $reference, int $evidence): Response
    {
        return DB::transaction(function () use ($request, $reference, $evidence): Response {
            $referral = CareReferral::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), array_column(ReferralPermissions::definitions(), 'key'));

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $referral, $evidence): Response {
                abort_unless($this->access->query($actor, $reads)->whereKey($referral->id)->exists(), 404);
                $document = $this->files->record($actor, $referral, $evidence);
                abort_unless($document->state === 'available', 409, 'This document is unavailable until it passes a malware scan.');
                $bytes = $this->files->bytes($document);
                abort_if($bytes === null, 409, 'This document could not be verified. Ask an authorized colleague to upload a replacement.');
                app(ReferralCommand::class)->record($referral, $actor, 'evidence.downloaded', $document->audience, ['evidence_id' => $document->id, 'title' => $document->metadata['title']]);
                $extension = match ($document->metadata['mime']) {
                    'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg'
                };

                return response($bytes)->header('Content-Type', $document->metadata['mime'])->header('Content-Disposition', 'attachment; filename="'.$document->reference.'.'.$extension.'"')
                    ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "sandbox; default-src 'none'");
            });
        });
    }
}

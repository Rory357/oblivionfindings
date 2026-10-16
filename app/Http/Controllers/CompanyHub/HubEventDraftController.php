<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Models\HubEventDraftFile;
use App\Domain\CompanyHub\Services\HubEventPublishing;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HubEventDraftController extends Controller
{
    use ServesPrivateAttachments;

    public function index(Request $request, HubEventPublishing $publishing)
    {
        return response()->json($publishing->workspace($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, HubEventPublishing $publishing, ?HubEventDraft $draft = null)
    {
        try {
            $draft = $publishing->save($request, $draft);
        } catch (HttpExceptionInterface $error) {
            if (! $request->expectsJson() && in_array($error->getStatusCode(), [409, 422], true)) {
                throw ValidationException::withMessages(['version' => $error->getMessage() ?: 'This draft changed. Reopen Event drafts before continuing.']);
            }
            throw $error;
        }

        return back()->with('hubEventDraftId', $draft->id)->with('success', match ($draft->state) {
            'published' => 'Event published.', 'review' => 'Event submitted for review.', 'scheduled' => 'Event publication scheduled.', default => 'Private event draft saved.',
        });
    }

    public function transition(Request $request, HubEventDraft $draft, HubEventPublishing $publishing)
    {
        return response()->json(['draft' => $publishing->serialize($publishing->transition($request, $draft))])->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, HubEventDraftFile $file, HubEventPublishing $publishing)
    {
        $file = $file->fresh();
        abort_unless($file, 404);
        $publishing->assertEdit($request->user(), $file->draft);
        abort_unless($file->retained, 404);
        abort_if($request->boolean('preview') && ! HubMediaLibrary::previewable($file->mime), 404);

        $response = $this->streamPrivateAttachment('private', $file->path, $file->original_name, $file->mime, $request->boolean('preview') ? 'inline' : 'attachment');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function eventAttachment(Request $request, HubEventDraft $draft, HrCalendarEventAttachment $attachment, HubEventPublishing $publishing)
    {
        $draft = $draft->fresh();
        $attachment = $attachment->fresh();
        abort_unless($draft && $attachment, 404);
        $publishing->assertEdit($request->user(), $draft);
        $files = collect($publishing->serialize($draft)['initial']['attachments']);
        abort_unless($files->contains('id', $attachment->id), 404);
        abort_if($request->boolean('preview') && ! HubMediaLibrary::previewable($attachment->mime), 404);
        $response = $this->streamPrivateAttachment($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime, $request->boolean('preview') ? 'inline' : 'attachment');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

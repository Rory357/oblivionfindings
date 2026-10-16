<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Models\HrRecognitionImage;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionMentions;
use App\Domain\Hr\Services\HrRecognitionReplies;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;

class HubRecognitionSocialController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(private readonly HubAccess $access, private readonly HubRecognition $recognition) {}

    private function read(Request $request, HrKudos $kudos): array
    {
        $this->access->assertEnter($request->user());
        $current = $kudos->fresh();
        abort_unless($current, 404);
        $record = $this->recognition->describe($request->user(), $current);
        abort_unless($record, 404);

        return $record;
    }

    private function respond(Request $request, HrKudos $kudos)
    {
        return response()->json($this->read($request, $kudos))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, HrKudos $kudos)
    {
        return $this->respond($request, $kudos);
    }

    public function mentionOptions(Request $request, HrRecognitionMentions $mentions, ?HrKudos $kudos = null)
    {
        $this->access->assertEnter($request->user());
        abort_unless($request->user()->canDo('hr.recognition.give'), 403);
        if ($kudos) {
            $this->read($request, $kudos);
        }

        return response()->json(['actor_id' => (int) $request->user()->id, 'people' => $mentions->options($request->user(), $kudos),
            'available' => $mentions->ready()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, HrKudos $kudos, HrRecognitionReplies $replies)
    {
        $this->read($request, $kudos);
        $data = $request->validate(HrRecognitionReplies::createRules());
        $replies->create($request->user(), $kudos, $data);

        return $this->respond($request, $kudos);
    }

    public function update(Request $request, HrKudos $kudos, int $reply, HrRecognitionReplies $replies)
    {
        $this->read($request, $kudos);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'body' => 'required|string|min:1|max:2000', ...HrRecognitionMentions::rules(),
            'parent_id' => 'prohibited', 'images' => 'prohibited', 'user_id' => 'prohibited', 'author_id' => 'prohibited', 'kudos_id' => 'prohibited']);
        $replies->edit($request->user(), $kudos, $reply, $data);

        return $this->respond($request, $kudos);
    }

    public function destroy(Request $request, HrKudos $kudos, int $reply, HrRecognitionReplies $replies)
    {
        $this->read($request, $kudos);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'body' => 'prohibited', 'parent_id' => 'prohibited', 'user_id' => 'prohibited', 'author_id' => 'prohibited', 'kudos_id' => 'prohibited']);
        $replies->remove($request->user(), $kudos, $reply, $data);

        return $this->respond($request, $kudos);
    }

    public function moderate(Request $request, HrKudos $kudos, int $reply, HrRecognitionReplies $replies)
    {
        $this->read($request, $kudos);
        $data = $request->validate(['actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'hidden' => 'required|boolean', 'reason' => 'required|string|max:500',
            'body' => 'prohibited', 'parent_id' => 'prohibited', 'user_id' => 'prohibited', 'author_id' => 'prohibited', 'kudos_id' => 'prohibited']);
        $replies->moderate($request->user(), $kudos, $reply, $data);

        return $this->respond($request, $kudos);
    }

    public function image(Request $request, HrRecognitionImage $image, FeedService $feed, HrRecognitionReplies $replies)
    {
        $viewer = $request->user()->fresh();
        abort_unless($viewer, 403);
        $this->access->assertEnter($viewer);
        $kudos = HrKudos::find($image->kudos_id);
        abort_unless($kudos && $viewer->canDo('hr.recognition.view') && $feed->canViewKudos($kudos, $viewer), 404);
        abort_unless((new UserSiteAccessService)->applyStaffScope(User::query()->whereKey($viewer->id), $viewer)->exists(), 404);
        // An opaque image ID cannot bypass the Hub reader's current identity
        // and audience projection for a public card.
        $projection = $kudos->is_public ? $this->recognition->describe($viewer, $kudos) : null;
        abort_if($kudos->is_public && $projection === null, 404);
        if ($image->reply_id) {
            $reply = HrKudosReply::where('kudos_id', $kudos->id)->find($image->reply_id);
            abort_unless($reply && $reply->body !== null && ! $reply->author_removed_at
                && (! $reply->hidden_at || $replies->canModerate($kudos, $viewer)), 404);
            abort_if($kudos->is_public && ! in_array((int) $reply->id, array_column($projection['replies'], 'id'), true), 404);
        }
        abort_unless(in_array($image->mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && str_starts_with($image->path, 'hr/recognition/'.$kudos->id.'/'), 404);
        $response = $this->streamPrivateAttachment('private', $image->path, 'shoutout-picture.'.match ($image->mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        }, $image->mime, 'inline');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

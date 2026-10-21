<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatAudience
{
    public function __construct(private readonly ClientFamilyCommunicationAccess $family) {}

    public function members(OpsConversation $conversation): Collection
    {
        $conversation->loadMissing(['participants.user', 'client']);

        return $conversation->participants->filter(fn ($participant) => $participant->user && $participant->role !== OpsConversationParticipant::COVER_READER
            && ($conversation->conversation_type !== 'family' || ($conversation->client && $this->family->canAppearAsParticipant($participant->user, $conversation->client))));
    }

    public function review(OpsConversation $conversation, User $viewer): array
    {
        if ($conversation->conversation_type !== 'family') {
            return ['audience_review_required' => false, 'audience_review_token' => null];
        }
        // Membership row IDs invalidate an old review after removal and rejoining.
        // Care-team cover is disclosed as a role; its changing duty roster is not a private directory.
        $members = $this->members($conversation)->sortBy('user_id')->map(fn ($member) => [
            $member->id, $member->user_id, $member->user->name, $member->role,
        ])->values()->all();
        $token = hash('sha256', json_encode([1, $conversation->id, $conversation->client_id,
            $members, (bool) $conversation->care_team_cover, $conversation->assigned_to,
        ], JSON_THROW_ON_ERROR));
        $reviewed = DB::table('chat_audience_reviews')->where('conversation_id', $conversation->id)
            ->where('user_id', $viewer->id)->where('audience_token', $token)->exists();

        return ['audience_review_required' => ! $reviewed, 'audience_review_token' => $token];
    }

    public function requireReviewed(OpsConversation $conversation, User $viewer, ?string $token): void
    {
        if ($conversation->conversation_type !== 'family') {
            return;
        }
        $review = $this->review($conversation, $viewer);
        if ($review['audience_review_required'] || ! hash_equals($review['audience_review_token'], $token ?? '')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Review who can see this family conversation before sending. Your message has not been sent.',
                'code' => 'chat_audience_review_required',
            ], 409));
        }
    }
}

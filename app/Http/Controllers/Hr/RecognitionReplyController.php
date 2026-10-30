<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\HrRecognitionReplies;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RecognitionReplyController extends Controller
{
    public function destroy(Request $request, HrKudos $kudos, int $reply, HrRecognitionReplies $replies)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'user_id' => 'prohibited', 'author_id' => 'prohibited', 'kudos_id' => 'prohibited',
            'parent_id' => 'prohibited', 'body' => 'prohibited',
        ]);
        $replies->remove($request->user(), $kudos, $reply, $data);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['saved' => true, 'kudos_id' => (int) $kudos->id, 'reply_id' => $reply])->header('Cache-Control', 'private, no-store');
        }

        return back()->with('success', 'Your reply was removed.');
    }
}

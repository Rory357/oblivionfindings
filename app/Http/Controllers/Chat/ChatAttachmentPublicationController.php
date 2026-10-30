<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatAttachmentPublication;
use Illuminate\Http\Request;

final class ChatAttachmentPublicationController extends Controller
{
    public function options(Request $request, int $attachment, ChatAttachmentPublication $publication)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json($publication->options($request->user(), $attachment, trim($data['q'] ?? '')))->header('Cache-Control', 'private, no-store');
    }

    private function rules(): array
    {
        return ['client_id' => ['required', 'integer', 'min:1'], 'destination' => ['required', 'in:documents,photos'], 'family_visible' => ['required', 'boolean']];
    }

    public function review(Request $request, int $attachment, ChatAttachmentPublication $publication)
    {
        return response()->json($publication->review($request->user(), $attachment, $request->validate($this->rules())))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $attachment, ChatAttachmentPublication $publication)
    {
        $data = $request->validate($this->rules() + [
            'mutation_id' => ['required', 'uuid'], 'audience_token' => ['required', 'string', 'size:64'], 'audience_confirmed' => ['required', 'accepted'],
        ]);
        $result = $publication->publish($request->user(), $attachment, $data);

        return response()->json($result, $result['already_created'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatMessageAlerts;
use Illuminate\Http\Request;

final class ChatMessageAlertController extends Controller
{
    public function __invoke(Request $request, ChatMessageAlerts $alerts)
    {
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991']]);

        return response()->json($alerts->since($request->user(), isset($data['after']) ? (int) $data['after'] : null))
            ->header('Cache-Control', 'private, no-store');
    }
}

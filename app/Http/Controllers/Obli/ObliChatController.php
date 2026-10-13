<?php

namespace App\Http\Controllers\Obli;

use App\Domain\Obli\ObliChatGateway;
use Illuminate\Http\Request;

final class ObliChatController
{
    public function options(Request $request, ObliChatGateway $chat)
    {
        return response()->json($chat->options($request->bearerToken()), options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function store(Request $request, ObliChatGateway $chat)
    {
        return response()->json($chat->send($request->bearerToken(), $request->json()->all()), options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function show(Request $request, string $uuid, ObliChatGateway $chat)
    {
        return response()->json($chat->outcome($request->bearerToken(), $uuid), options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

<?php

namespace App\Http\Controllers\Obli;

use App\Domain\Obli\ObliKnowledge;
use App\Domain\Obli\ObliSignIn;
use App\Models\User;
use Illuminate\Http\Request;

final class ObliKnowledgeController
{
    public function index(Request $request, ObliSignIn $signIn, ObliKnowledge $knowledge)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'before' => ['nullable', 'integer', 'min:1']]);

        return $signIn->withSession($request->bearerToken(), fn (User $actor) => response()->json(
            $knowledge->search($actor, trim($data['q'] ?? ''), isset($data['before']) ? (int) $data['before'] : null),
            options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function show(Request $request, int $article, ObliSignIn $signIn, ObliKnowledge $knowledge)
    {
        return $signIn->withSession($request->bearerToken(), fn (User $actor) => response()->json(
            $knowledge->detail($actor, $article), options: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}

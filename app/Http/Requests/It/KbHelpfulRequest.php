<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Was this helpful?" vote on a published KB article (§I). Reachable by
 * anyone who can browse the knowledge base — a requester (it.request) or an
 * agent (it.view). One-vote-per-user is a localStorage guard on the client
 * (fine for v1); the server just tallies.
 */
class KbHelpfulRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && ($user->canDo('it.request') || $user->canDo('it.view')));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'helpful' => ['required', 'boolean'],
        ];
    }
}

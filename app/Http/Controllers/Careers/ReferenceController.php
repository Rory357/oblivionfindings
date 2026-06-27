<?php

namespace App\Http\Controllers\Careers;

use App\Domain\Hr\Models\HrReferenceCheck;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public, token-guarded reference questionnaire (D3 / handover item 17). The
 * referee receives the link by email and submits structured responses without
 * authenticating.
 */
class ReferenceController extends Controller
{
    /** The structured questions a referee answers. */
    public const QUESTIONS = [
        ['key' => 'capacity', 'label' => 'In what capacity did you work with the candidate?', 'type' => 'text'],
        ['key' => 'reemploy', 'label' => 'Would you re-employ them in a care role?', 'type' => 'choice', 'options' => ['Yes', 'No', 'Unsure']],
        ['key' => 'concerns', 'label' => 'Do you have any concerns about them working with vulnerable people?', 'type' => 'text'],
        ['key' => 'reliability', 'label' => 'Reliability (1–5)', 'type' => 'rating'],
        ['key' => 'judgement', 'label' => 'Judgement (1–5)', 'type' => 'rating'],
        ['key' => 'communication', 'label' => 'Communication (1–5)', 'type' => 'rating'],
        ['key' => 'comments', 'label' => 'Any other comments', 'type' => 'text'],
    ];

    public function show(string $token)
    {
        $reference = $this->resolve($token);
        abort_if($reference === null, 404);

        $candidate = $reference->application?->candidate;

        return Inertia::render('careers/reference-questionnaire', [
            'token' => $token,
            'refereeName' => $reference->referee_name,
            'candidateName' => $candidate ? $candidate->full_name : 'the candidate',
            'questions' => self::QUESTIONS,
            'completed' => $reference->status === 'completed',
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $reference = $this->resolve($token);
        abort_if($reference === null, 404);

        if ($reference->status === 'completed') {
            return redirect()->route('careers.reference.show', ['token' => $token])
                ->with('error', 'This reference has already been submitted.');
        }

        $allowedKeys = array_column(self::QUESTIONS, 'key');

        $validated = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*' => ['nullable'],
        ]);

        $responses = collect($validated['responses'])
            ->only($allowedKeys)
            ->map(fn ($value) => is_scalar($value) ? (string) $value : $value)
            ->all();

        $reference->update([
            'responses' => $responses,
            'status' => 'completed',
            'received_at' => now(),
        ]);

        return redirect()->route('careers.reference.show', ['token' => $token])
            ->with('success', 'Thank you — your reference has been submitted.');
    }

    private function resolve(string $token): ?HrReferenceCheck
    {
        if ($token === '') {
            return null;
        }

        return HrReferenceCheck::query()
            ->with('application.candidate:id,first_name,last_name')
            ->where('response_token', $token)
            ->first();
    }
}

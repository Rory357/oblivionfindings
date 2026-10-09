<?php

return [
    // Supported drivers: 'local', 'openai'
    'driver' => env('LLM_DRIVER', 'local'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5'),
    ],

    /*
    | Medication data in AI search and AI summaries (decision D5, 9 Oct 2026).
    | Off by default: the AI search snapshot (ClientRagIndexer) and timeline
    | summaries (GenerateSummaryJob, SummaryController) carry no medicines,
    | allergies or medication timeline events. When switched on, a reader
    | still gets only people they may open under the eMAR person rule
    | (ClientPolicy::viewMedications), and controlled medicines are never
    | included. There is no AI settings page yet, so this stays config.
    */
    'include_medication_data' => filter_var(env('LLM_INCLUDE_MEDICATION_DATA', false), FILTER_VALIDATE_BOOLEAN),
];

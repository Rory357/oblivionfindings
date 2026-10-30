<?php

namespace App\Domain\Referrals\Services;

use Illuminate\Validation\ValidationException;

/** Self-reported navigation context, never proof of funding or eligibility. */
final class ReferralIntakeFunding
{
    public const SITUATIONS = ['not_explored', 'need_help', 'underway', 'arranged', 'private', 'unsure'];

    public static function options(array $configuration): array
    {
        return array_values(array_map(fn ($pathway) => array_intersect_key($pathway, array_flip(['id', 'label', 'service_ids', 'personal_income'])),
            array_filter($configuration['pathways'], fn ($pathway) => $pathway['enabled'])));
    }

    public static function snapshot(array $configuration, array $form): ?array
    {
        $id = $form['funding_pathway_id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }
        $pathway = collect(self::options($configuration))->firstWhere('id', $id);
        if ($form['authority_status'] === 'unsure' || $form['purpose'] === 'business' || ! $pathway
            || ($form['requested_service'] !== 'unsure' && $pathway['service_ids'] !== [] && ! in_array($form['requested_service'], $pathway['service_ids'], true))) {
            throw ValidationException::withMessages(['funding_pathway_id' => 'Choose an available pathway for this support enquiry, or leave it as not sure yet.']);
        }

        return [...$pathway, 'status' => 'reported_unverified'];
    }
}

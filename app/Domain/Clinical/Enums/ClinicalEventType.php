<?php

namespace App\Domain\Clinical\Enums;

enum ClinicalEventType: string
{
    case Fall = 'fall';
    case Seizure = 'seizure';
    case Choking = 'choking';
    case Deterioration = 'deterioration';
    case AllergicReaction = 'allergic_reaction';
    case SkinIntegrity = 'skin_integrity';
    case InfectionSign = 'infection_sign';
    case BehaviouralCrisis = 'behavioural_crisis';
    case MentalHealthEpisode = 'mental_health_episode';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fall => 'Fall',
            self::Seizure => 'Seizure',
            self::Choking => 'Choking Incident',
            self::Deterioration => 'Health Deterioration',
            self::AllergicReaction => 'Allergic Reaction',
            self::SkinIntegrity => 'Skin Integrity Issue',
            self::InfectionSign => 'Sign of Infection',
            self::BehaviouralCrisis => 'Behavioural Crisis',
            self::MentalHealthEpisode => 'Mental Health Episode',
            self::Other => 'Other Clinical Event',
        };
    }

    /**
     * Whether this event type should auto-link to an HsEvent.
     */
    public function shouldLinkToHs(): bool
    {
        return match ($this) {
            self::Fall, self::Seizure, self::Choking => true,
            default => false,
        };
    }

    /**
     * The HsEvent category to use when auto-linking.
     */
    public function hsEventCategory(): ?string
    {
        return match ($this) {
            self::Fall => 'injury',
            self::Seizure => 'incident',
            self::Choking => 'incident',
            default => null,
        };
    }
}

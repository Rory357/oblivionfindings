<?php

namespace App\Domain\Clinical\Enums;

/**
 * Hypothesised function of a behaviour in Positive Behaviour Support (PBS)
 * ABC charting — the "why" behind the behaviour. Feeds the function-of-behaviour
 * breakdown analytics on the Behaviour / ABC tab.
 */
enum BehaviourFunction: string
{
    case EscapeAvoidance = 'escape_avoidance';
    case AttentionSocial = 'attention_social';
    case TangibleAccess = 'tangible_access';
    case SensoryAutomatic = 'sensory_automatic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EscapeAvoidance => 'Escape / avoidance',
            self::AttentionSocial => 'Attention / social',
            self::TangibleAccess => 'Tangible / access',
            self::SensoryAutomatic => 'Sensory / automatic',
            self::Other => 'Other / unclear',
        };
    }

    /**
     * Short description shown under the option in the wizard tile picker.
     */
    public function description(): string
    {
        return match ($this) {
            self::EscapeAvoidance => 'To get away from a demand, task or situation',
            self::AttentionSocial => 'To gain attention or social interaction',
            self::TangibleAccess => 'To obtain an item, activity or outcome',
            self::SensoryAutomatic => 'Self-stimulation or sensory regulation',
            self::Other => 'Function not yet clear',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $f) => [
            'value' => $f->value,
            'label' => $f->label(),
            'description' => $f->description(),
        ], self::cases());
    }
}

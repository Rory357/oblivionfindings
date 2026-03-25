<?php

namespace App\Enums;

/**
 * NZ supported-living service delivery context.
 *
 * Stable codes are important for auditability (e.g. tying medication
 * administrations and shift activity back to the service setting).
 */
enum ServiceType: string
{
    // Residential Services
    case Residential = 'residential';
    case GroupHome = 'group_home';
    case FlatmateSupport = 'flatmate_support';
    case HostFamily = 'host_family';

    // Community Services
    case HomeSupport = 'home_support';
    case CommunityParticipation = 'community_participation';
    case CommunityAccess = 'community_access';

    // Day Services
    case DayProgramme = 'day_programme';
    case VocationalSupport = 'vocational';
    case SupportedEmployment = 'supported_employment';

    // Respite
    case PlannedRespite = 'planned_respite';
    case EmergencyRespite = 'emergency_respite';
    case CommunityRespite = 'community_respite';

    // Specialist Services
    case BehaviourSupport = 'behaviour_support';
    case HighComplexNeeds = 'high_complex';
    case TransitionSupport = 'transition';
    case CulturalSupport = 'cultural_support';

    // Children & Youth
    case ChildDisabilitySupport = 'child_disability';
    case YouthTransition = 'youth_transition';

    // Flexible / Other
    case IndividualisedFunding = 'individualised_funding';
    case FlexibleSupport = 'flexible';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Community Residential',
            self::GroupHome => 'Group Home',
            self::FlatmateSupport => 'Flatmate Support',
            self::HostFamily => 'Host Family / Shared Living',
            self::HomeSupport => 'Home & Community Support',
            self::CommunityParticipation => 'Community Participation',
            self::CommunityAccess => 'Community Access',
            self::DayProgramme => 'Day Programme',
            self::VocationalSupport => 'Vocational Support',
            self::SupportedEmployment => 'Supported Employment',
            self::PlannedRespite => 'Planned Respite',
            self::EmergencyRespite => 'Emergency Respite',
            self::CommunityRespite => 'Community Respite',
            self::BehaviourSupport => 'Behaviour Support',
            self::HighComplexNeeds => 'High & Complex Needs',
            self::TransitionSupport => 'Transition Support',
            self::CulturalSupport => 'Cultural & Whanau Support',
            self::ChildDisabilitySupport => 'Child Disability Support',
            self::YouthTransition => 'Youth Transition',
            self::IndividualisedFunding => 'Individualised Funding (IF)',
            self::FlexibleSupport => 'Flexible Disability Support',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Residential => 'Service delivered in a community residential setting (e.g. supported living residence).',
            self::GroupHome => 'Shared living in a staffed group home with 24/7 support.',
            self::FlatmateSupport => 'Support worker lives alongside the person as a flatmate.',
            self::HostFamily => 'Person lives with a host family or shared living arrangement.',
            self::HomeSupport => 'Service delivered in the person\'s own home (in-home support).',
            self::CommunityParticipation => 'Supporting people to participate in community activities and social connections.',
            self::CommunityAccess => 'Facilitating access to community facilities, events, and services.',
            self::DayProgramme => 'Structured daytime activities and skills development programme.',
            self::VocationalSupport => 'Support for vocational training, work readiness, and skill building.',
            self::SupportedEmployment => 'On-the-job support for people in paid employment.',
            self::PlannedRespite => 'Pre-arranged short-term respite for planned carer breaks.',
            self::EmergencyRespite => 'Urgent respite support when regular arrangements break down.',
            self::CommunityRespite => 'Respite provided in community settings rather than residential.',
            self::BehaviourSupport => 'Specialist positive behaviour support plans and interventions.',
            self::HighComplexNeeds => 'Intensive support for people with high and complex needs.',
            self::TransitionSupport => 'Support during life transitions (e.g. moving, school to work).',
            self::CulturalSupport => 'Culturally responsive support incorporating Te Ao Maori and whanau.',
            self::ChildDisabilitySupport => 'Disability support services for children and their whanau.',
            self::YouthTransition => 'Support for young people transitioning from child to adult services.',
            self::IndividualisedFunding => 'Self-directed support funded through Individualised Funding.',
            self::FlexibleSupport => 'Flexible disability support packages tailored to individual needs.',
            self::Other => 'Other service delivery context.',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Residential, self::GroupHome, self::FlatmateSupport, self::HostFamily => 'Residential',
            self::HomeSupport, self::CommunityParticipation, self::CommunityAccess => 'Community',
            self::DayProgramme, self::VocationalSupport, self::SupportedEmployment => 'Day Services',
            self::PlannedRespite, self::EmergencyRespite, self::CommunityRespite => 'Respite',
            self::BehaviourSupport, self::HighComplexNeeds, self::TransitionSupport, self::CulturalSupport => 'Specialist',
            self::ChildDisabilitySupport, self::YouthTransition => 'Children & Youth',
            self::IndividualisedFunding, self::FlexibleSupport, self::Other => 'Flexible / Other',
        };
    }

    public function colour(): string
    {
        return match ($this->category()) {
            'Residential' => 'violet',
            'Community' => 'blue',
            'Day Services' => 'emerald',
            'Respite' => 'amber',
            'Specialist' => 'rose',
            'Children & Youth' => 'teal',
            'Flexible / Other' => 'slate',
            default => 'gray',
        };
    }
}

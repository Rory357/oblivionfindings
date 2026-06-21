/* First Aid — the single FE source of truth for the enum vocabularies. Mirrors the
 * canonical sets validated by StoreFirstAidRecordRequest (BE) so the wizard, the detail
 * Edit pane, the register table and the command-centre launcher can never drift.
 * Semantic tokens only. */
import { type Tone, titleCase } from '@/pages/health-safety/components/register-row-kit';

export type FirstAidOption = { value: string; label: string };

export const PERSON_TYPES: FirstAidOption[] = [
    { value: 'staff', label: 'Staff' },
    { value: 'client', label: 'Client' },
    { value: 'visitor', label: 'Visitor' },
    { value: 'contractor', label: 'Contractor' },
];

export const INJURY_TYPES: FirstAidOption[] = [
    { value: 'cut', label: 'Cut / laceration' },
    { value: 'burn', label: 'Burn / scald' },
    { value: 'bruise', label: 'Bruise / contusion' },
    { value: 'sprain', label: 'Sprain / strain' },
    { value: 'fracture', label: 'Fracture' },
    { value: 'fall', label: 'Fall' },
    { value: 'head_injury', label: 'Head injury' },
    { value: 'eye_injury', label: 'Eye injury' },
    { value: 'allergic_reaction', label: 'Allergic reaction' },
    { value: 'breathing_difficulty', label: 'Breathing difficulty' },
    { value: 'chest_pain', label: 'Chest pain' },
    { value: 'seizure', label: 'Seizure' },
    { value: 'fainting', label: 'Fainting / collapse' },
    { value: 'nausea', label: 'Nausea / vomiting' },
    { value: 'sting', label: 'Insect sting / bite' },
    { value: 'choking', label: 'Choking' },
    { value: 'other', label: 'Other' },
];

/** Canonical seven — duplicate spellings collapsed by the gold-standard migration.
 *  `ambulance_called` is a boolean flag, never an outcome. */
export const OUTCOMES: FirstAidOption[] = [
    { value: 'returned_to_activity', label: 'Returned to activity' },
    { value: 'sent_home', label: 'Sent home' },
    { value: 'medical_centre', label: 'Referred to medical centre' },
    { value: 'sent_to_hospital', label: 'Sent to hospital' },
    { value: 'ongoing_monitoring', label: 'Ongoing monitoring' },
    { value: 'refused_treatment', label: 'Refused treatment' },
    { value: 'other', label: 'Other' },
];

const labelFrom = (opts: FirstAidOption[], value: string): string =>
    opts.find((o) => o.value === value)?.label ?? titleCase(value);

export const personTypeLabel = (v: string): string => labelFrom(PERSON_TYPES, v);
export const injuryLabel = (v: string): string => labelFrom(INJURY_TYPES, v);
export const outcomeLabel = (v: string): string => labelFrom(OUTCOMES, v);

/** Outcome severity tone — drives the register's Outcome chip + the detail header. */
export function outcomeTone(value: string): Tone {
    switch (value) {
        case 'sent_to_hospital':
            return 'critical';
        case 'medical_centre':
        case 'ongoing_monitoring':
        case 'refused_treatment':
            return 'warning';
        case 'returned_to_activity':
            return 'success';
        default:
            return 'neutral';
    }
}

const CRITICAL_INJURIES = new Set([
    'fracture',
    'head_injury',
    'chest_pain',
    'breathing_difficulty',
    'seizure',
    'allergic_reaction',
    'choking',
]);
const WARNING_INJURIES = new Set(['burn', 'eye_injury', 'fall', 'fainting']);

/** Leading injury dot tone — serious presentations read hotter. */
export function injuryTone(value: string): Tone {
    if (CRITICAL_INJURIES.has(value)) return 'critical';
    if (WARNING_INJURIES.has(value)) return 'warning';
    return 'neutral';
}

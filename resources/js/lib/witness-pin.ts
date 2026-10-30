/**
 * Personal witness PIN (eMAR P00 v5 / PIN-1). A second person confirms a
 * controlled-drug witness, a restricted-competency co-sign or a verbal-order
 * read-back with their own 6-digit PIN — never their login password.
 */
export type WitnessPinStatus =
    | 'set'
    | 'not_set'
    | 'locked'
    | 'reset'
    | 'expired';

export interface WitnessPickerOption {
    id: number | string;
    name: string;
    /** Missing on older payloads: treated as usable so nothing regresses. */
    witness_pin?: WitnessPinStatus;
}

export const WITNESS_PIN_LENGTH = 6;

/** Only colleagues with a usable PIN can be chosen as the second person. */
export function witnessIsSelectable(option: WitnessPickerOption): boolean {
    return option.witness_pin === undefined || option.witness_pin === 'set';
}

const UNUSABLE_REASON: Record<Exclude<WitnessPinStatus, 'set'>, string> = {
    not_set: 'no witness PIN set',
    locked: 'witness PIN locked',
    reset: 'witness PIN reset — must set a new one',
    expired: 'witness PIN needs renewing',
};

/** Picker label: "Mere Kahu — no witness PIN set" for people who can't be chosen. */
export function witnessOptionLabel(option: WitnessPickerOption): string {
    if (witnessIsSelectable(option) || option.witness_pin === undefined) {
        return option.name;
    }

    return `${option.name} — ${UNUSABLE_REASON[option.witness_pin as Exclude<WitnessPinStatus, 'set'>]}`;
}

export const WITNESS_PIN_STATUS_LABEL: Record<WitnessPinStatus, string> = {
    set: 'PIN set',
    not_set: 'No PIN set',
    locked: 'Locked',
    reset: 'Reset — must set a new one',
    expired: 'Needs renewing',
};

export const OWN_WITNESS_PIN_SETTINGS_URL = '/settings/witness-pin';

/** Prompt on the viewer's own medication boards until their PIN is usable. */
export const OWN_WITNESS_PIN_PROMPT: Record<
    Exclude<WitnessPinStatus, 'set'>,
    string
> = {
    not_set: 'Set your witness PIN so colleagues can choose you as a witness',
    reset: 'Your witness PIN was reset — choose a new one',
    expired: 'Your witness PIN needs renewing',
    locked: 'Your witness PIN is locked — reset it in Settings',
};

/** Keep only digits, at most 6. */
export function sanitiseWitnessPin(value: string): string {
    return value.replace(/\D/g, '').slice(0, WITNESS_PIN_LENGTH);
}

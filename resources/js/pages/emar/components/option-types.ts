import type { WitnessPinStatus } from '@/lib/witness-pin';

/** A medicine the Overview's quick actions can pick (MedicationOverviewService). */
export type MedicationOption = {
    id: number;
    client_id: number;
    name: string;
    unit: string | null;
    controlled: boolean;
};

export type WitnessOption = {
    id: number;
    name: string;
    /** PIN-1: unusable witness PINs are listed but can't be chosen. */
    witness_pin?: WitnessPinStatus;
};

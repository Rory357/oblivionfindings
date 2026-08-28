import {
    createMedicationMutationReplayState,
    prepareMedicationMutationReplayState,
    type MedicationMutationReplayState,
} from '@/lib/emar-offline';

const EPHEMERAL_REPLAY_KEYS = new Set([
    'client_request_uuid',
    'witness_credential',
]);

function replayMaterialWithoutCredentials(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(replayMaterialWithoutCredentials);
    }

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .filter(([key]) => !EPHEMERAL_REPLAY_KEYS.has(key))
                .map(([key, child]) => [
                    key,
                    replayMaterialWithoutCredentials(child),
                ]),
        );
    }

    return value;
}

export function createTransportMedicationReplayState(
    createUuid?: () => string,
): MedicationMutationReplayState {
    return createMedicationMutationReplayState(createUuid);
}

export function onlineTransportMedicationEnvelope(
    medications: readonly unknown[],
): { queued_offline?: false } {
    return medications.length > 0 ? { queued_offline: false } : {};
}

export function prepareTransportMedicationReplayState(
    current: MedicationMutationReplayState,
    payload: Record<string, unknown>,
    createUuid?: () => string,
): MedicationMutationReplayState {
    return prepareMedicationMutationReplayState(
        current,
        replayMaterialWithoutCredentials(payload) as Record<string, unknown>,
        createUuid,
    );
}

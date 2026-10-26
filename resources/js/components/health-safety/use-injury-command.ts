import { useSourceCommand } from '@/components/health-safety/restraints/use-source-command';
import type { FormDataType } from '@inertiajs/core';
import { confirmsInjuryReceipt, injuryCommandAllowed, INJURY_RECEIPT_COMMAND } from './injury-command-state';
import type { InjuryCommand, InjuryRecord } from './injury-workspace-types';

const object = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
export function injuryCommandContext(value: unknown, command: InjuryCommand | 'create',
    selected?: { plan?: number | null; attachment?: number | null }) {
    if (!object(value)) return null;
    if (command === 'create') {
        if (!object(value.capture) || !object(value.capture.can) || typeof value.capture.can.create !== 'boolean' ||
            typeof value.capture.can.record_reported_details !== 'boolean') return null;
        return { id: 0, version: 0, allowed: value.capture.can.create && value.capture.can.record_reported_details };
    }
    if (!object(value.detail)) return null;
    const detail = value.detail;
    const capabilities = detail.can;
    const commands = detail.commands;
    if (!Number.isSafeInteger(detail.id) || Number(detail.id) < 1 ||
        !Number.isSafeInteger(detail.source_version) || Number(detail.source_version) < 0 ||
        Number(detail.source_version) > 4294967294 || !object(commands) ||
        commands.expected_version !== detail.source_version || !Array.isArray(commands.transitions) ||
        !commands.transitions.every((item) => typeof item === 'string') ||
        !object(capabilities) || !['update', 'transition', 'assign', 'review', 'recovery', 'upload_evidence', 'remove_evidence']
            .every((key) => typeof capabilities[key] === 'boolean') || typeof detail.status !== 'string' ||
        !['readable', 'restricted'].includes(String(detail.medical_access)) ||
        !['valid', 'repair_required', 'unknown'].includes(String(detail.source_integrity)) ||
        !['valid', 'repair_required', 'unknown'].includes(String(detail.recovery_integrity)) ||
        !(detail.rtw_plans === null || (Array.isArray(detail.rtw_plans) && detail.rtw_plans.every((item) => object(item) && Number.isSafeInteger(item.id) && Number(item.id) > 0))) ||
        !(detail.attachments === null || (Array.isArray(detail.attachments) && detail.attachments.every((item) => object(item) && Number.isSafeInteger(item.id) && Number(item.id) > 0)))) return null;
    return {
        id: Number(detail.id), version: Number(detail.source_version),
        allowed: injuryCommandAllowed(detail as unknown as InjuryRecord, command, selected),
    };
}

/** Reuse the shared in-memory draft, current-source review and uncertain receipt recovery. */
export function useInjuryCommand<T extends FormDataType<T>>({ initial, detail, command,
    actorId, selected, captureAllowed = false }: {
    initial: T; detail: InjuryRecord | null; command: InjuryCommand | 'create'; actorId: number;
    selected?: { plan?: number | null; attachment?: number | null }; captureAllowed?: boolean;
}) {
    const childId = command === 'edit_rtw' ? selected?.plan : command === 'remove_evidence' ? selected?.attachment : undefined;
    return useSourceCommand({
        initial, kind: 'injury', command: INJURY_RECEIPT_COMMAND[command],
        context: detail ? { id: detail.id, version: detail.source_version,
            allowed: command !== 'create' && injuryCommandAllowed(detail, command, selected) } : null,
        captureAllowed,
        checkHref: detail ? `/health-safety/injuries/${detail.id}` : '/health-safety/injuries/create',
        readContext: (value) => injuryCommandContext(value, command, selected),
        receiptKey: 'injury_confirmation', childKey: childId == null ? undefined : 'source_id',
        childId: childId ?? undefined,
        confirmReceipt: (value, expected) => confirmsInjuryReceipt(value, {
            injuryId: expected.id, version: expected.version, key: expected.key,
            actorId, command, childId: expected.childId,
        }),
    });
}

import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    approverCommand,
    approverIntentJson,
    matchedApproverSave,
    parseApproverRead,
    prepareApproverAttempt,
    type ApproverAttempt,
} from './restriction-approver-contract';

const uuid = '7d886442-95d4-4bb6-a8b5-364489f0d22d';
const read = () => ({
    version: 1, state: 'configured', actor_id: 4,
    read_generation: 'a'.repeat(32), scope_site_ids: [2],
    checked_at: '2026-10-12T05:00:00Z', revision: 'b'.repeat(64),
    authorized_user_ids: [7, 9],
    selected_users: [
        { id: 7, name: 'Aroha', eligible: true },
        { id: 9, name: 'Hemi', eligible: false },
    ],
    candidate_options: [{ id: 7, name: 'Aroha' }], can_configure: true,
});
afterEach(() => vi.unstubAllGlobals());

describe('private approver settings contract', () => {
    it('retains lawful retired members for explicit removal, without making them selectable', () => {
        const parsed = parseApproverRead(read(), 4);
        expect(parsed?.authorized_user_ids).toEqual([7, 9]);
        expect(parsed?.selected_users[1].eligible).toBe(false);
        expect(parsed?.candidate_options.map((person) => person.id)).toEqual([7]);
    });

    it('refuses a partial global list rather than silently showing only the visible members', () => {
        expect(parseApproverRead({ ...read(), selected_users: [read().selected_users[0]] }, 4)).toBeNull();
    });

    it('conceals actor-mismatched, future-version and contradictory private reads', () => {
        expect(parseApproverRead(read(), 5)).toBeNull();
        expect(parseApproverRead({ ...read(), version: 2 }, 4)).toBeNull();
        expect(parseApproverRead({ ...read(), candidate_options: [
            ...read().candidate_options, { id: 9, name: 'Hemi' },
        ] }, 4)).toBeNull();
    });

    it('does not turn unavailable data into an empty editable default', () => {
        const unavailable = {
            ...read(), state: 'unavailable', revision: null,
            scope_site_ids: [], authorized_user_ids: [], selected_users: [],
            candidate_options: [], can_configure: false,
        };
        expect(parseApproverRead(unavailable, 4)?.state).toBe('unavailable');
        expect(parseApproverRead({ ...unavailable, can_configure: true }, 4)).toBeNull();
        expect(parseApproverRead({ ...unavailable, authorized_user_ids: [7] }, 4)).toBeNull();
    });

    it('rejects string and fractional identity values without coercion', () => {
        expect(parseApproverRead({ ...read(), authorized_user_ids: ['7', 9] }, 4)).toBeNull();
        expect(approverCommand(uuid, null, [7.5], 'Update the list')).toBeNull();
    });

    it('rejects coerced state arrays and newline-suffixed protocol identifiers', () => {
        expect(parseApproverRead({ ...read(), state: ['configured'] }, 4)).toBeNull();
        expect(parseApproverRead({ ...read(), state: ['unavailable'] }, 4)).toBeNull();
        expect(parseApproverRead({ ...read(), read_generation: 'a'.repeat(32) + '\n' }, 4)).toBeNull();
        expect(parseApproverRead({ ...read(), revision: 'b'.repeat(64) + '\n' }, 4)).toBeNull();
        expect(approverCommand(uuid + '\n', null, [], 'Update the list')).toBeNull();
        expect(approverCommand(uuid, 'b'.repeat(64) + '\n', [], 'Update the list')).toBeNull();
    });

    it('matches backend name encoding without changing names or applying a new eligibility rule', () => {
        const data = read();
        data.selected_users[0].name = '\u00a0';
        data.candidate_options[0].name = '\u00a0';
        expect(parseApproverRead(data, 4)?.selected_users[0].name).toBe('\u00a0');
        data.selected_users[0].name = '\ud800';
        data.candidate_options[0].name = '\ud800';
        expect(parseApproverRead(data, 4)).toBeNull();
    });

    it('normalises numeric selections and PHP ASCII trim while preserving meaningful Unicode', () => {
        const command = approverCommand(uuid, null, [9, 7, 9], ' \tCafé / cover\u2028\n');
        expect(command?.authorized_user_ids).toEqual([7, 9]);
        expect(command?.reason).toBe('Café / cover\u2028');
        expect(approverIntentJson(command!)).toBe(
            `{"authorized_user_ids":[7,9],"command_id":"${uuid}","expected_revision":null,"reason":"Café / cover\\u2028"}`,
        );
        expect(approverCommand(uuid, null, [], '\u200B\uFEFF')).toBeNull();
        expect(approverCommand(uuid, null, [], '\uD800')).toBeNull();
    });

    it('captures an immutable command before asynchronous hashing', async () => {
        let resolve!: (value: ArrayBuffer) => void;
        const digest = vi.fn((_algorithm: string, _data: BufferSource) =>
            new Promise<ArrayBuffer>((done) => { resolve = done; }),
        );
        vi.stubGlobal('crypto', { subtle: { digest } });
        const original = { command_id: uuid, expected_revision: null,
            authorized_user_ids: [7], reason: 'Original reason' };
        const prepared = prepareApproverAttempt(4, original);
        original.authorized_user_ids.push(9);
        original.reason = 'Changed reason';
        resolve(new Uint8Array(32).buffer);
        const attempt = await prepared;
        expect(attempt.command.authorized_user_ids).toEqual([7]);
        expect(attempt.command.reason).toBe('Original reason');
        expect(Object.isFrozen(attempt.command.authorized_user_ids)).toBe(true);
        expect(digest.mock.calls[0][0]).toBe('SHA-256');
    });

    it('confirms only the original command, actor, source, intent and advancing revision', () => {
        const command = approverCommand(uuid, 'b'.repeat(64), [7], 'Update the list')!;
        const attempt: ApproverAttempt = { actorId: 4, command, intentHash: 'c'.repeat(64) };
        const result = {
            version: 1, actor_id: 4, command_id: uuid,
            action: 'hr.workRestrictions.approvers.updated',
            source_key: 'hr.work_restriction_approvers',
            prior_revision: 'b'.repeat(64), revision: 'd'.repeat(64),
            intent_hash: 'c'.repeat(64),
        };
        expect(matchedApproverSave({ status: 'saved', result }, attempt)).toBe(true);
        for (const delta of [
            { actor_id: 5 }, { command_id: 'another-command' },
            { source_key: 'another-setting' }, { intent_hash: 'f'.repeat(64) },
            { prior_revision: null }, { revision: 'b'.repeat(64) },
            { revision: 'd'.repeat(64) + '\n' },
        ]) expect(matchedApproverSave({ status: 'saved', result: { ...result, ...delta } }, attempt)).toBe(false);
        expect(matchedApproverSave({ status: 'not_confirmed', result: null }, attempt)).toBe(false);
    });
});

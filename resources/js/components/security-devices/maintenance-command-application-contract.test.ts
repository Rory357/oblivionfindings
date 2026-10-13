import { createHash, webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import {
    maintenanceCommandHash,
    maintenanceCommandReply,
    type MaintenancePending,
} from './maintenance-command';
const path = process.env.MAINTENANCE_RECEIPT_CONTRACT_FIXTURE;
const check = path ? it : it.skip;
describe('original maintenance command application response', () => {
    check(
        'matches the real PHP fingerprint and accepts unchanged receipt bytes without adapting their fields',
        async () => {
            vi.stubGlobal('crypto', webcrypto);
            const original = JSON.parse(readFileSync(path!, 'utf8'));
            expect(original.synthetic).toBe(true);
            expect(original.hardware_acceptance).toBe(false);
            expect(
                createHash('sha256')
                    .update(original.response_body)
                    .digest('hex'),
            ).toBe(original.response_sha256);
            const reply = JSON.parse(original.response_body);
            const requestHash = await maintenanceCommandHash(
                original.operation,
                original.target_window_id,
                original.body,
            );
            expect(requestHash).toBe(reply.receipt.request_hash);
            const pending: MaintenancePending = {
                schema_version: 1,
                actor_id: original.body.expected_actor_id,
                request_uuid: original.body.request_uuid,
                operation: original.operation,
                target_window_id: original.target_window_id,
                expected_version:
                    original.operation === 'create'
                        ? null
                        : original.body.version,
                request_hash: requestHash,
                scope: reply.receipt.scope,
            };
            expect(maintenanceCommandReply(reply, pending)).toEqual(reply);
            expect(() =>
                maintenanceCommandReply(reply, {
                    ...pending,
                    actor_id: pending.actor_id + 1,
                }),
            ).toThrow();
            expect(() =>
                maintenanceCommandReply(reply, {
                    ...pending,
                    scope: {
                        ...pending.scope,
                        site_id: pending.scope.site_id + 1,
                    },
                }),
            ).toThrow();
            expect(() =>
                maintenanceCommandReply(
                    {
                        ...reply,
                        receipt: {
                            ...reply.receipt,
                            committed_at: '2026-02-30T09:00:00+00:00',
                        },
                    },
                    pending,
                ),
            ).toThrow();
        },
    );
});

const paddedPath = process.env.MAINTENANCE_PADDED_CONTRACT_FIXTURE;
const paddedCheck = paddedPath ? it : it.skip;
describe('real HTTP middleware canonical maintenance commands', () => {
    paddedCheck(
        'matches unchanged padded create/edit/cancel producer bytes with the canonical browser fingerprint',
        async () => {
            vi.stubGlobal('crypto', webcrypto);
            const original = JSON.parse(readFileSync(paddedPath!, 'utf8'));
            expect(original.synthetic).toBe(true);
            expect(original.hardware_acceptance).toBe(false);
            expect(original.cases).toHaveLength(3);
            for (const item of original.cases) {
                expect(
                    createHash('sha256')
                        .update(item.response_body)
                        .digest('hex'),
                ).toBe(item.response_sha256);
                const reply = JSON.parse(item.response_body);
                const requestHash = await maintenanceCommandHash(
                    item.operation,
                    item.target_window_id,
                    item.body,
                );
                expect(requestHash).toBe(reply.receipt.request_hash);
                const pending: MaintenancePending = {
                    schema_version: 1,
                    actor_id: reply.receipt.actor_id,
                    request_uuid: reply.receipt.request_uuid,
                    operation: item.operation,
                    target_window_id: item.target_window_id,
                    expected_version:
                        item.operation === 'create'
                            ? null
                            : Number(item.body.version),
                    request_hash: requestHash,
                    scope: reply.receipt.scope,
                };
                expect(maintenanceCommandReply(reply, pending)).toEqual(reply);
                expect(() =>
                    maintenanceCommandReply(reply, {
                        ...pending,
                        request_hash: 'f'.repeat(64),
                    }),
                ).toThrow();
            }
        },
    );
});

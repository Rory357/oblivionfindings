import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { createHash, webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterEach, expect, it, vi } from 'vitest';
import { maintenanceMarkerKey } from './maintenance-command';
import { maintenanceCommandFixture } from './maintenance-command-test-fixtures';
import { useMaintenanceCommand } from './use-maintenance-command';

vi.mock('axios');
afterEach(() => {
    cleanup();
    sessionStorage.clear();
    vi.unstubAllGlobals();
    vi.resetAllMocks();
});
const originalPath = process.env.MAINTENANCE_OVERLAP_EXPORT_PATH;
it.runIf(Boolean(originalPath))(
    'accepts original application overlap denial, preserves editable intent and gives a corrected save a new identity',
    async () => {
        const original = JSON.parse(readFileSync(originalPath!, 'utf8'));
        expect(
            createHash('sha256').update(original.response_body).digest('hex'),
        ).toBe(original.response_sha256);
        expect(original.status).toBe(422);
        const rejection = JSON.parse(original.response_body);
        vi.stubGlobal('crypto', webcrypto);
        sessionStorage.clear();
        vi.mocked(axios.isAxiosError).mockReturnValue(true);
        vi.mocked(axios.post).mockRejectedValueOnce({
            response: { status: original.status, data: rejection },
        });
        const scope = JSON.parse(original.existing_reply_body).receipt.scope;
        const {
            request_uuid: _uuid,
            expected_actor_id: actorId,
            ...body
        } = original.body;
        const { result } = renderHook(() =>
            useMaintenanceCommand({
                actorId,
                active: true,
                context: 'synthetic-overlap',
            }),
        );
        await act(async () => {
            await result.current.submit('create', null, body, scope);
        });
        const first = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        expect(result.current.phase).toBe('idle');
        expect(result.current.pending).toBeNull();
        expect(result.current.errors.starts_at).toBe(
            rejection.errors.starts_at[0],
        );
        expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
        expect(first.reason).toBe(body.reason);
        expect(first.starts_at).toBe(body.starts_at);
        vi.mocked(axios.post).mockImplementationOnce(async (_url, sent) => ({
            data: await maintenanceCommandFixture(
                sent as Record<string, unknown>,
                'create',
                null,
                scope,
            ),
        }));
        await act(async () => {
            await result.current.submit(
                'create',
                null,
                {
                    ...body,
                    starts_at: '2026-10-20T10:00:00+13:00',
                    ends_at: '2026-10-20T11:00:00+13:00',
                },
                scope,
            );
        });
        const corrected = vi.mocked(axios.post).mock.calls[1][1] as Record<
            string,
            unknown
        >;
        expect(corrected.request_uuid).not.toBe(first.request_uuid);
        expect(corrected.reason).toBe(first.reason);
        expect(result.current.phase).toBe('idle');
        expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
        expect(axios.post).toHaveBeenCalledTimes(2);
    },
);

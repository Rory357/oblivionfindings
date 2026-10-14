import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { maintenanceClockPreview } from './maintenance-clock-preview';
const path = process.env.MAINTENANCE_CLOCK_CONTRACT_FIXTURE;
const check = path ? it : it.skip;
describe('original application maintenance preview', () => {
    check(
        'consumes the unchanged original producer bytes and rejects altered authority, counts and timing',
        () => {
            const exportValue = JSON.parse(readFileSync(path!, 'utf8'));
            expect(exportValue.synthetic).toBe(true);
            expect(exportValue.hardware_acceptance).toBe(false);
            expect(
                createHash('sha256')
                    .update(exportValue.response_body)
                    .digest('hex'),
            ).toBe(exportValue.response_sha256);
            const reply = JSON.parse(exportValue.response_body);
            expect(Object.keys(reply)).toEqual(['preview']);
            const body = exportValue.body,
                actor = body.expected_actor_id;
            const p = maintenanceClockPreview(reply.preview, actor, body);
            expect(p.scheduled_occurrences).toBe(8);
            expect(p.occurrences[0].starts_at).toBe(
                '2026-09-25T21:00:00+00:00',
            );
            expect(p.occurrences[1].starts_at).toBe(
                '2026-09-26T20:00:00+00:00',
            );
            expect(() => maintenanceClockPreview(p, actor + 1, body)).toThrow();
            expect(() =>
                maintenanceClockPreview(p, actor, {
                    ...body,
                    site_id: body.site_id + 1,
                }),
            ).toThrow();
            expect(() =>
                maintenanceClockPreview(
                    { ...p, skipped_occurrences: 1, scheduled_occurrences: 7 },
                    actor,
                    body,
                ),
            ).toThrow();
            const changed = structuredClone(p);
            changed.occurrences[0].ends_at = '2026-09-25T23:00:00+00:00';
            expect(() =>
                maintenanceClockPreview(changed, actor, body),
            ).toThrow();
            expect(() =>
                maintenanceClockPreview(
                    { ...p, transport_url: 'https://not-approved.invalid' },
                    actor,
                    body,
                ),
            ).toThrow();
        },
    );
});

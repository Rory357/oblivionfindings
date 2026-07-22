import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const read = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('Site Profile asset, fleet, hardware, and plan depth', () => {
    it('keeps the complete asset ownership and servicing register', () => {
        const source = read('resources/js/pages/sites/tabs/assets.tsx');

        for (const term of [
            'owner',
            'risk_level',
            'inspection_due_at',
            'maintenance_due_at',
            'can_create',
        ]) {
            expect(source).toContain(term);
        }
    });

    it('restores Fleet charts, vehicles, telemetry consent, activity, and compliance', () => {
        const source = read('resources/js/pages/sites/tabs/fleet.tsx');

        for (const term of [
            'HorizontalBarChart',
            'consent_blocked',
            'wof_expires_at',
            'registration_expires_at',
            'today_bookings',
            'active_outings',
            'compliance',
        ]) {
            expect(source).toContain(term);
        }
    });

    it('embeds one canonical Hardware surface with assignments and device pins', () => {
        const tab = read('resources/js/pages/sites/tabs/hardware.tsx');
        const surface = read('resources/js/pages/sites/hardware/index.tsx');

        expect(tab).toContain('SiteHardwareSurface');
        for (const term of [
            'assignment_type',
            'deviceRoomDraft',
            'PlanThumbnail',
            'savePlanPin',
            'removePlanPin',
        ]) {
            expect(surface).toContain(term);
        }
    });

    it('embeds one canonical full plan and room surface', () => {
        const tab = read('resources/js/pages/sites/tabs/plan.tsx');
        const surface = read('resources/js/pages/sites/plan/index.tsx');

        expect(tab).toContain('SitePlanSurface');
        for (const term of [
            'SiteTypePlanBuilderDialog',
            'inventory_href',
            'draft',
            'published',
            'pin_counts',
            'has_emergency_layer',
        ]) {
            expect(surface).toContain(term);
        }
    });
});

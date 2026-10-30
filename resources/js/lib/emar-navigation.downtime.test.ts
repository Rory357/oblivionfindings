import { describe, expect, it } from 'vitest';
import {
    EMAR_HUBS,
    emarBreadcrumbs,
    emarHubForUrl,
    emarSearchEntries,
    visibleEmarViews,
    type EmarNavigationPermissions,
} from './emar-navigation';

/* EA-045 (Lane C1): Downtime & paper records is reachable from Safety &
 * oversight (approved P10), search and Home-rooted breadcrumbs. */
type MedKey = keyof NonNullable<EmarNavigationPermissions['medications']>;
const permissions = (...keys: MedKey[]): EmarNavigationPermissions => ({
    medications: Object.fromEntries(keys.map((key) => [key, true])),
});
const teamLead = permissions('view', 'administerRecord', 'ordersManage');
const worker = permissions('view', 'administerRecord');
const safety = (can: EmarNavigationPermissions) =>
    visibleEmarViews(EMAR_HUBS.find((hub) => hub.key === 'safety')!, can).map(
        (view) => view.label,
    );

describe('Downtime & paper records navigation (EA-045)', () => {
    it('is a Safety & oversight view for a lead', () => {
        expect(safety(teamLead)).toContain('Downtime & paper records');
        expect(emarSearchEntries(teamLead)).toContainEqual(
            expect.objectContaining({
                label: 'Downtime & paper records',
                href: '/emar/downtime',
                group: 'Safety & oversight',
            }),
        );
    });
    it('identifies the list and a downtime as Safety & oversight › Downtime', () => {
        for (const url of [
            '/emar/downtime',
            '/emar/downtime/12?paper_entry=3',
        ]) {
            const match = emarHubForUrl(url);
            expect(match?.hub.key).toBe('safety');
            expect(match?.view.key).toBe('downtime');
        }
        expect(
            emarBreadcrumbs('/emar/downtime/12', teamLead).map((c) => c.title),
        ).toEqual([
            'Home',
            'Medication',
            'Safety & oversight',
            'Downtime & paper records',
        ]);
    });
    it('needs medication access: emergency access alone does not show it', () => {
        expect(safety(permissions('breakGlass'))).not.toContain(
            'Downtime & paper records',
        );
        // A frontline worker reaches their paper records from Meds today; the
        // Safety & oversight hub itself stays hidden for them.
        expect(safety(worker)).toEqual([]);
    });
});

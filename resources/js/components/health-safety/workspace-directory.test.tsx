import {
    SAFETY_HUBS,
    SAFETY_REGISTER_HUBS,
    type SafetyNavigationPermissions,
} from '@/lib/health-safety/workspace-navigation';
import { describe, expect, it } from 'vitest';
import { filterSafetyDirectory } from './workspace-directory';

describe('safety directory discovery', () => {
    // The lists that used to sit on the "Safety registers" page, now spread over three hubs (drawing D4).
    const visibleRegisters = (can: SafetyNavigationPermissions) =>
        SAFETY_HUBS.filter((hub) => SAFETY_REGISTER_HUBS.includes(hub.key))
            .flatMap((hub) => hub.views)
            .filter((view) => view.visible(can));
    it('finds former register names across groups', () => {
        const visible = visibleRegisters({
            hazards: { view: true },
        });
        expect(
            filterSafetyDirectory(visible, 'chemical register').map(
                (view) => view.key,
            ),
        ).toEqual(['chemicals']);
        expect(
            filterSafetyDirectory(visible, 'worker participation').map(
                (view) => view.key,
            ),
        ).toEqual(['representatives', 'meetings', 'consultations']);
    });
    it('does not reveal restricted register names through search', () => {
        const visible = visibleRegisters({
            restraints: { view: true },
        });
        expect(visible.map((view) => view.key)).toEqual(['restraints']);
        expect(filterSafetyDirectory(visible, 'first aid')).toEqual([]);
        expect(filterSafetyDirectory(visible, 'behaviour')).toHaveLength(1);
    });
});

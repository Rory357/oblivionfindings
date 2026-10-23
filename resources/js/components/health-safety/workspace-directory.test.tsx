import {
    SAFETY_HUBS,
    visibleSafetyViews,
} from '@/lib/health-safety/workspace-navigation';
import { describe, expect, it } from 'vitest';
import { filterSafetyDirectory } from './workspace-directory';

describe('safety directory discovery', () => {
    const registers = SAFETY_HUBS.find((hub) => hub.key === 'registers')!;
    it('finds former register names across groups', () => {
        const visible = visibleSafetyViews(registers, {
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
        const visible = visibleSafetyViews(registers, {
            restraints: { view: true },
        });
        expect(visible.map((view) => view.key)).toEqual(['restraints']);
        expect(filterSafetyDirectory(visible, 'first aid')).toEqual([]);
        expect(filterSafetyDirectory(visible, 'behaviour')).toHaveLength(1);
    });
});

import { describe, expect, it } from 'vitest';

import { buildStream } from './stream-grouping';
import { groupWork } from './work-priority';

const task = (
    id: number,
    scheduled_for: string | null,
    client_id: number | null = 1,
) => ({
    id,
    label: `Task ${id}`,
    scheduled_for,
    client_id,
    is_completed: false,
    completed_at: null,
});

describe('My Day work priority', () => {
    it('keeps accepted help visible without counting it as completed or recommending it next', () => {
        const items = buildStream({
            tasks: [
                {
                    ...task(8, '2026-09-12T08:00:00+12:00'),
                    follow_through: 'accepted_help',
                },
                task(9, '2026-09-12T09:00:00+12:00'),
            ],
            meds: [],
        });
        const groups = groupWork(
            items,
            Date.parse('2026-09-12T10:00:00+12:00'),
        );
        expect(groups.due.map((item) => item.data.id)).toEqual([9]);
        expect(groups.followedUp.map((item) => item.data.id)).toEqual([8]);
        expect(groups.completed).toHaveLength(0);
    });
    it('keeps post-midnight work after the previous evening and separates anytime work', () => {
        const items = buildStream({
            tasks: [
                task(1, '2026-09-13T02:00:00+12:00'),
                task(2, null),
                task(3, '2026-09-12T22:00:00+12:00'),
            ],
            meds: [],
        });
        const groups = groupWork(
            items,
            Date.parse('2026-09-12T23:00:00+12:00'),
        );
        expect(groups.due.map((item) => item.data.id)).toEqual([3]);
        expect(groups.later.map((item) => item.data.id)).toEqual([1]);
        expect(groups.anytime.map((item) => item.data.id)).toEqual([2]);
    });

    it('does not assign whole-site work to the shift client or include it in that person filter', () => {
        const wholeSite = {
            ...task(1, null, null),
            task_scope: 'site' as const,
        };
        expect(
            buildStream({
                tasks: [wholeSite],
                meds: [],
                fallbackClientId: 5,
            })[0].clientId,
        ).toBeNull();
        expect(
            buildStream({
                tasks: [wholeSite],
                meds: [],
                fallbackClientId: 5,
                residentFilter: 5,
            }),
        ).toEqual([]);
        expect(
            buildStream({
                tasks: [wholeSite],
                meds: [],
                residentFilter: 5,
                includeSiteTasks: true,
            })[0].clientId,
        ).toBeNull();
    });

    it('counts refused and withheld doses as recorded outcomes without labelling them given', () => {
        const items = buildStream({
            tasks: [],
            meds: ['refused', 'withheld', 'due'].map((status, i) => ({
                id: String(i),
                medication_id: i,
                client_id: 1,
                client_name: 'Person',
                medication_name: 'Medication',
                dose: '1',
                is_controlled: false,
                can_record: true,
                can_give: true,
                scheduled_for: '2026-09-12T09:00:00+12:00',
                status: status as 'refused' | 'withheld' | 'due',
                emar_url: null,
            })),
        });
        const groups = groupWork(
            items,
            Date.parse('2026-09-12T10:00:00+12:00'),
        );
        expect(
            groups.completed.map(
                (item) => item.kind === 'med' && item.data.status,
            ),
        ).toEqual(['refused', 'withheld']);
        expect(groups.due).toHaveLength(1);
    });
});

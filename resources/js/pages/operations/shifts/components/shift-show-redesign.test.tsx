import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    ShiftSignalRail,
    ShiftTabStrip,
    buildShiftShowSignals,
    buildShiftShowTabs,
    canMarkShiftTasks,
} from './shift-show-redesign';

describe('shift show redesign navigation', () => {
    it('builds icon tabs with redesign badges and preserves operational tab order', () => {
        const tabs = buildShiftShowTabs({
            tasksDone: 5,
            tasksTotal: 8,
            notesCount: 2,
            handoverCount: 1,
            auditCount: 4,
            incidentCount: 1,
            medicationOutstandingCount: 3,
            showCoverage: true,
            showAssignment: true,
            showMedications: true,
            showObservations: true,
            showForms: true,
            showTransport: true,
            showReplacement: true,
        });

        expect(tabs.map((tab) => tab.key)).toEqual([
            'tasks',
            'notes',
            'medications',
            'coverage',
            'assignment',
            'incidents',
            'observations',
            'forms',
            'transport',
            'replacement',
            'audit',
        ]);
        expect(tabs[0]).toMatchObject({
            key: 'tasks',
            label: 'Tasks',
            badge: '5/8',
            tone: 'primary',
        });
        expect(tabs[2]).toMatchObject({
            key: 'medications',
            badge: 3,
            tone: 'warning',
        });
    });

    it('renders tabs with badge counts and calls back with the selected tab', () => {
        const onChange = vi.fn();
        const tabs = buildShiftShowTabs({
            tasksDone: 1,
            tasksTotal: 2,
            notesCount: 0,
            handoverCount: 0,
            auditCount: 0,
            incidentCount: 0,
            medicationOutstandingCount: 2,
            showCoverage: false,
            showAssignment: false,
            showMedications: true,
            showObservations: false,
            showForms: false,
            showTransport: false,
            showReplacement: false,
        });

        render(
            <ShiftTabStrip tabs={tabs} activeTab="tasks" onChange={onChange} />,
        );

        fireEvent.click(screen.getByRole('tab', { name: /Medications 2/i }));

        expect(onChange).toHaveBeenCalledWith('medications');
    });
});

describe('shift show redesign signals', () => {
    it('builds actionable signals from coverage, meds, tasks, and handover state', () => {
        const signals = buildShiftShowSignals({
            coverage: {
                has_actionable_gap: true,
                gap_kind: 'role_open',
                window_label: 'Mon morning',
                required_staff: 3,
                assigned_staff: 2,
                open_shifts: 1,
                role_shortages: [
                    {
                        key: 'med_competent',
                        label: 'Medication competent',
                        required: 2,
                        missing: 1,
                    },
                ],
            },
            medicationOutstandingCount: 3,
            incompleteTaskCount: 2,
            handoverSummary: {
                id: 10,
                status: null,
                incoming_staff_name: 'Aisha Bello',
                observations_summary: [],
            },
        });

        expect(signals.map((signal) => signal.tabKey)).toEqual([
            'coverage',
            'medications',
            'tasks',
            'notes',
        ]);
        expect(signals[0].title).toBe('Coverage gap');
        expect(signals[3].title).toBe('Handover needs acknowledgement');
    });

    it('lets the signal rail jump to the related tab', () => {
        const onSelectTab = vi.fn();
        const signals = buildShiftShowSignals({
            coverage: null,
            medicationOutstandingCount: 1,
            incompleteTaskCount: 0,
            handoverSummary: null,
        });

        render(
            <ShiftSignalRail
                signals={signals}
                safety={null}
                onSelectTab={onSelectTab}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Medication due/i }),
        );

        expect(onSelectTab).toHaveBeenCalledWith('medications');
    });
});

describe('shift show permission helpers', () => {
    it('honours the page-level mark_tasks permission before falling back to shared auth flags', () => {
        expect(
            canMarkShiftTasks(
                { mark_tasks: true },
                {
                    can: {
                        shifts: {
                            update: false,
                            tasksUpdateSelf: false,
                            manageAny: false,
                        },
                    },
                },
            ),
        ).toBe(true);

        expect(
            canMarkShiftTasks(
                { mark_tasks: false },
                {
                    can: {
                        shifts: {
                            update: false,
                            tasksUpdateSelf: true,
                            manageAny: false,
                        },
                    },
                },
            ),
        ).toBe(true);
    });
});

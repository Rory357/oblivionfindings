import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    DrillConnections,
    DrillFindings,
    DrillHistory,
    DrillParticipants,
} from './drill-panels';
import type { DrillWorkspaceDetail } from './types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: React.ComponentProps<'a'>) => <a {...props} />,
}));
afterEach(cleanup);
const detail = {
    counts: {
        assigned: 2,
        attended: 1,
        not_attended: 1,
        findings_open: 1,
        findings_overdue: 0,
    },
    exports: {},
    can: {},
    participants: [],
    attachments: [],
    findings: [],
    history: [],
    connections: {
        safety_reviews: [],
        corrective_actions: [],
        control_room: [],
        previous_drill: null,
        next_drills: [],
    },
} as unknown as DrillWorkspaceDetail;
it('retains an open finding and its real remedy state without offering blocked resolution', () => {
    const move = vi.fn();
    render(
        <DrillFindings
            selected={12}
            move={move}
            detail={{
                ...detail,
                findings: [
                    {
                        id: 12,
                        finding_type: 'improvement',
                        description: 'Assembly exit needs review',
                        severity: 'medium',
                        status: 'open',
                        assigned_to: 4,
                        assignee_name: 'Site coordinator',
                        due_date: '2026-10-08',
                        is_overdue: false,
                        corrective_action: null,
                        resolved_at: null,
                        resolution_notes: null,
                        resolution_evidence_ids: [],
                        resolve_blocked_reason:
                            'The required remedy needs independent verification.',
                        corrective_action_options_href: null,
                        can: {
                            edit: true,
                            resolve: false,
                            create_action: false,
                            link_action: false,
                        },
                        corrective_action_record: {
                            id: 7,
                            reference_number: 'CA-7',
                            title: 'Review exit',
                            status: 'in_progress',
                            assigned_to: { id: 4, name: 'Site coordinator' },
                            due_date: '2026-10-08',
                            effectiveness_confirmed: null,
                            verified_at: null,
                            closed_at: null,
                            href: '/health-safety/corrective-actions/7?return_to=%2Fhealth-safety%2Fdrills%2F8%3Fsection%3Dfindings%26finding%3D12',
                        },
                    },
                ],
            }}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Record resolution' }),
    ).toBeNull();
    expect(
        screen.getByText('The required remedy needs independent verification.'),
    ).toBeTruthy();
    expect(screen.getByText('Effectiveness: Not recorded')).toBeTruthy();
    expect(
        screen.getByRole('link', { name: /CA-7/ }).getAttribute('href'),
    ).toContain('finding%3D12');
    fireEvent.click(screen.getByRole('button', { name: 'Update finding' }));
    expect(move).toHaveBeenCalledWith('findings', 'edit-finding', 12);
});
it('does not label a person absent or competent just because attendance was not checked', () => {
    render(
        <DrillParticipants
            move={vi.fn()}
            detail={{
                ...detail,
                participants: [
                    {
                        id: 2,
                        user_id: 4,
                        name: 'Pat Worker',
                        role: 'participant',
                        attended: false,
                        notes: null,
                        accommodations: 'Accessible assembly route',
                        can: { edit: false },
                    },
                ],
            }}
        />,
    );
    expect(screen.getByText('Not marked as attended')).toBeTruthy();
    expect(screen.getByText('Accessible assembly route')).toBeTruthy();
    expect(screen.queryByText('Absent', { exact: true })).toBeNull();
    expect(screen.queryByRole('button', { name: /Update Pat/ })).toBeNull();
});
it('shows captured history dates and facts rather than substituting current values', () => {
    render(
        <DrillHistory
            detail={{
                ...detail,
                title: 'Current drill',
                history: [
                    {
                        id: 3,
                        action: 'finding_updated',
                        title: 'Finding updated',
                        actor: { id: 4, name: 'Pat Worker' },
                        occurred_at: '2025-04-06T02:00:00+12:00',
                        note: null,
                        legacy: false,
                        snapshot: {
                            title: 'Original drill',
                            finding: {
                                due_date: '2025-04-06',
                                resolved_at: '2025-04-07',
                                description: 'Original description',
                            },
                            private_path: '/private/do-not-render',
                        },
                    },
                ],
            }}
        />,
    );
    // Each entry opens its recorded details (pg-hsn-66f "Details").
    fireEvent.click(screen.getByRole('button', { name: 'Details' }));
    const recorded = within(
        screen.getByRole('region', { name: 'Recorded details' }),
    );
    expect(recorded.getByText('Original drill')).toBeTruthy();
    expect(recorded.getByText('Original description')).toBeTruthy();
    expect(recorded.getByText('6 Apr 2025')).toBeTruthy();
    expect(recorded.getByText('7 Apr 2025')).toBeTruthy();
    expect(screen.queryByText('Current drill')).toBeNull();
    expect(screen.queryByText('/private/do-not-render')).toBeNull();
});
it('keeps failed signal processing separate from a recorded safety review and a responder notification', () => {
    render(
        <DrillConnections
            move={vi.fn()}
            detail={{
                ...detail,
                source_delivery: {
                    event_status: 'delivered',
                    signal_status: 'failed',
                    event_delivered_at: '2026-10-08T12:00:00+13:00',
                    signal_delivered_at: null,
                    attempts: 1,
                    failure_code: 'signal_delivery_failed',
                    next_attempt_at: '2026-10-08T12:01:00+13:00',
                },
            }}
        />,
    );
    expect(screen.getByText('Safety review recorded')).toBeTruthy();
    expect(screen.getByText('Response signal processing failed')).toBeTruthy();
    expect(screen.queryByText(/Control Room notified/i)).toBeNull();
    expect(
        screen.queryByRole('button', { name: 'Schedule next drill' }),
    ).toBeNull();
});

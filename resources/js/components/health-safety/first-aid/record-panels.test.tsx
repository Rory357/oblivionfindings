import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import {
    FirstAidEvidence,
    FirstAidFollowups,
    FirstAidHistory,
    FirstAidIncident,
} from './record-panels';
import type {
    FirstAidAttachment,
    FirstAidFollowup,
    FirstAidRecord,
} from './types';

afterEach(cleanup);
const file: FirstAidAttachment = {
    id: 2,
    original_name: 'Evidence.pdf',
    kind: 'document',
    notes: null,
    alt_text: null,
    mime: 'application/pdf',
    size: 100,
    is_image: false,
    uploaded_by: null,
    created_at: null,
    availability: 'missing',
    download_href: '/health-safety/first-aid/1/attachments/2',
    can: { remove: false },
};
const followup: FirstAidFollowup = {
    id: 3,
    notes: 'Review recorded follow-up',
    assigned_to: null,
    due_at: null,
    completed_at: '2026-10-08T01:00:00Z',
    completed_by: null,
    completion_notes: null,
    completion_outcome: null,
    completion_evidence: [],
    created_by: null,
    created_at: null,
    can: { complete: false, edit: false },
};
const record = (data: Partial<FirstAidRecord>) =>
    ({ ...data }) as FirstAidRecord;

it('retains legacy completion without inventing an actor, contact or clinical outcome', () => {
    render(
        <FirstAidFollowups
            detail={record({
                first_aider: { id: 4, name: 'Current first aider' },
                followups: [followup],
            })}
            onEdit={() => {}}
            onComplete={() => {}}
        />,
    );
    expect(screen.getByText('Recorded as complete')).toBeInTheDocument();
    expect(screen.getByText('Operator not recorded')).toBeInTheDocument();
    expect(
        screen.getByText('No completion outcome was captured in this record.'),
    ).toBeInTheDocument();
    expect(screen.queryByText('Current first aider')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
it('distinguishes a recorded attempt from successful contact and retains missing-file truth', () => {
    render(
        <FirstAidFollowups
            detail={record({
                followups: [
                    {
                        ...followup,
                        completion_notes:
                            'No answer during the recorded attempt',
                        completion_outcome: {
                            kind: 'contact',
                            result: 'attempted',
                            occurred_at: '2026-10-08T00:30:00Z',
                        },
                        completion_evidence: [file],
                    },
                ],
            })}
            onEdit={() => {}}
            onComplete={() => {}}
        />,
    );
    expect(screen.getByText('Attempt recorded')).toBeInTheDocument();
    expect(screen.queryByText('Contact recorded')).not.toBeInTheDocument();
    expect(
        screen.getByText(/Evidence.pdf · File unavailable/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});
it('never offers a download for a missing file or removal for retained evidence', () => {
    render(
        <FirstAidEvidence
            detail={record({ attachments: [file] })}
            onRemove={() => {}}
        />,
    );
    expect(screen.getByText('File unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Remove file' }),
    ).not.toBeInTheDocument();
});
it('renders historical facts without substituting the current treatment or follow-up', () => {
    render(
        <FirstAidHistory
            detail={record({
                treatment_given: 'Current treatment',
                history: [
                    {
                        id: '1',
                        action: 'first_aid.created',
                        title: 'Treatment recorded',
                        actor: null,
                        occurred_at: null,
                        note: null,
                        legacy: false,
                        snapshot: {
                            treatment_given: 'Earlier treatment',
                            ambulance_called: false,
                            transport_secret: 'Never show this',
                            followup: {
                                notes: 'Earlier follow-up',
                                completion_notes: null,
                                completion_outcome: {
                                    kind: 'contact',
                                    result: 'attempted',
                                    occurred_at: '2026-10-08T00:30:00Z',
                                    note: 'Earlier unanswered call',
                                    transport_secret: 'Never show nested data',
                                },
                                completion_evidence_ids: [2, -3, 'secret'],
                            },
                        },
                    },
                    {
                        id: '2',
                        action: 'legacy',
                        title: 'Legacy update',
                        actor: null,
                        occurred_at: null,
                        note: null,
                        legacy: true,
                        snapshot: {},
                    },
                ],
            })}
        />,
    );
    // The history reads newest first (drawing pg-hsn-85f).
    const [legacy, earlier] = screen.getAllByRole('listitem');
    expect(earlier).toHaveTextContent('Earlier treatment');
    expect(within(earlier).getByText('No')).toBeInTheDocument();
    expect(legacy).toHaveTextContent('Historical details are incomplete');
    expect(screen.queryByText('Current treatment')).not.toBeInTheDocument();
    expect(screen.queryByText('Never show this')).not.toBeInTheDocument();
    expect(
        screen.queryByText('Never show nested data'),
    ).not.toBeInTheDocument();
    expect(within(earlier).getByText('Attempt recorded')).toBeInTheDocument();
    expect(earlier).toHaveTextContent('Earlier unanswered call');
    expect(within(earlier).getByText('2')).toBeInTheDocument();
    expect(screen.queryByText('Contact recorded')).not.toBeInTheDocument();
});
it('keeps the report flag separate from an independently unavailable incident', () => {
    render(
        <FirstAidIncident
            detail={record({
                incident_reported: true,
                related_incident: null,
                incident_link_state: 'unavailable',
                source_delivery: null,
                connections: {
                    site: null,
                    person: null,
                    incident: null,
                    equipment: [],
                    safety_reviews: [],
                    corrective_actions: [],
                    control_room: [],
                },
            })}
        />,
    );
    expect(screen.getByText('Yes')).toBeInTheDocument();
    expect(
        screen.getAllByText('No linked record is available to view here.'),
    ).toHaveLength(2);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByText('No incident')).not.toBeInTheDocument();
});

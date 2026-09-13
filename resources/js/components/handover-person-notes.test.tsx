import { useHandoverEditor } from '@/hooks/use-handover-editor';
import {
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HandoverPersonNotes from './handover-person-notes';
import HandoverWriteForm, {
    emptyHandoverWriteValue,
    type HandoverWriteValue,
} from './handover-write-form';

const people = [
    { id: 1, name: 'Mere' },
    { id: 2, name: 'James' },
    { id: 3, name: 'Casey' },
];
const notes = {
    shared_notes: '',
    people: people.map((person) => ({
        client_id: person.id,
        notes: '',
        no_updates: false,
        follow_up_needed: false,
    })),
};

function Form() {
    const [value, setValue] = useState<HandoverWriteValue>({
        ...emptyHandoverWriteValue,
        worker_notes: notes,
    });
    return (
        <HandoverWriteForm people={people} value={value} onChange={setValue} />
    );
}

afterEach(() => vi.unstubAllGlobals());

describe('person handover notes', () => {
    it('keeps each person and whole-site text in separate labelled inputs', () => {
        render(<Form />);
        fireEvent.change(
            screen.getByLabelText(
                'What should the next worker know about James?',
            ),
            { target: { value: 'James enjoyed the garden.' } },
        );
        fireEvent.change(screen.getByLabelText(/Whole site/), {
            target: { value: 'Towels restocked.' },
        });
        expect(
            screen.getByLabelText(
                'What should the next worker know about Mere?',
            ),
        ).toHaveValue('');
        expect(
            screen.getByLabelText(
                'What should the next worker know about Casey?',
            ),
        ).toHaveValue('');
        expect(
            screen.getByLabelText(
                'What should the next worker know about James?',
            ),
        ).toHaveValue('James enjoyed the garden.');
        expect(screen.getAllByRole('textbox')).toHaveLength(4);
        expect(
            screen.queryByText('Were all scheduled meds given?'),
        ).not.toBeInTheDocument();
    });

    it('makes no-updates an explicit choice instead of assuming it from an empty note', () => {
        render(<Form />);
        const noUpdates = screen.getAllByLabelText(
            'Supported — no updates to pass on',
        );
        expect(noUpdates[0]).not.toBeChecked();
        fireEvent.click(noUpdates[0]);
        expect(noUpdates[0]).toBeChecked();
        fireEvent.change(
            screen.getByLabelText(
                'What should the next worker know about Mere?',
            ),
            { target: { value: 'Visitor expected.' } },
        );
        expect(noUpdates[0]).not.toBeChecked();
        expect(noUpdates[0]).toBeDisabled();
    });

    it('shows names beside notes and follow-up status in the incoming handover', () => {
        render(
            <HandoverPersonNotes
                notes={{
                    shared_notes: 'Towels restocked.',
                    people: [
                        {
                            client_id: 1,
                            name: 'Mere',
                            notes: 'Visitor expected.',
                            no_updates: false,
                            follow_up_needed: true,
                        },
                        {
                            client_id: 2,
                            name: 'James',
                            notes: '',
                            no_updates: true,
                            follow_up_needed: false,
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Mere' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Needs follow-up')).toBeInTheDocument();
        expect(screen.getByText('No updates to pass on.')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Whole site' }),
        ).toBeInTheDocument();
    });

    it('reloads a saved draft and retains the version needed to prevent stale writes', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue({
                    ok: true,
                    json: async () => ({
                        people,
                        status: 'draft',
                        handover_id: 5,
                        expected_version: 3,
                        worker_notes: {
                            shared_notes: 'Saved shared note.',
                            people: [
                                {
                                    ...notes.people[1],
                                    notes: 'Saved James note.',
                                },
                            ],
                        },
                    }),
                }),
        );
        const { result } = renderHook(() => useHandoverEditor(10, true));
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.value.expected_version).toBe(3);
        expect(result.current.value.worker_notes?.people[1].notes).toBe(
            'Saved James note.',
        );
        expect(result.current.value.worker_notes?.people[0].notes).toBe('');
    });

    it('surfaces load failure rather than presenting an empty saved draft', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
        const { result } = renderHook(() => useHandoverEditor(10, true));
        await waitFor(() => expect(result.current.loading).toBe(false));
        expect(result.current.error).toContain('Retry');
        expect(result.current.editor).toBeNull();
    });
});

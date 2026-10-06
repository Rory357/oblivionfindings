import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordEventDialog from './record-event-dialog';

const { post, fetchMock } = vi.hoisted(() => ({
    post: vi.fn(),
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const { useRef, useState } = await import('react');
    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setValues] = useState(initial);
            const [errors, setErrors] = useState<Record<string, string>>({});
            const transform = useRef<(draft: T) => T>((draft) => draft);
            return {
                data,
                errors,
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setValues((draft) => ({ ...draft, [key]: value })),
                clearErrors: (...keys: string[]) =>
                    setErrors((old) =>
                        Object.fromEntries(
                            Object.entries(old).filter(
                                ([key]) => !keys.includes(key),
                            ),
                        ),
                    ),
                transform: (fn: (draft: T) => T) => {
                    transform.current = fn;
                },
                post: (
                    url: string,
                    options: {
                        onError: (errors: Record<string, string>) => void;
                    },
                ) =>
                    post(url, transform.current(data), {
                        ...options,
                        onError: (next: Record<string, string>) => {
                            setErrors(next);
                            options.onError(next);
                        },
                    }),
            };
        },
    };
});
vi.mock('@/pages/health-clinical/components/record-wizard-shared', () => ({
    ClinicalCardRail: () => null,
    ClientPicker: ({
        value,
        onChange,
    }: {
        value: { id: number } | null;
        onChange: (person: { id: number; name: string }) => void;
    }) => (
        <select
            aria-label="Client"
            value={value?.id ?? ''}
            onChange={(e) =>
                onChange({
                    id: Number(e.target.value),
                    name: 'Person ' + e.target.value,
                })
            }
        >
            <option value="">Choose</option>
            <option value="10">Person 10</option>
            <option value="11">Person 11</option>
        </select>
    ),
}));
// Exercise the real timezone adapter and wizard; the calendar/dropzone have separate interaction coverage.
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
        label,
        value,
        onChange,
        error,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
        error?: string;
    }) => (
        <div>
            <label>
                {label}
                <input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            </label>
            {error && <p role="alert">{error}</p>}
        </div>
    ),
}));
vi.mock('@/components/ui/file-dropzone', () => ({
    FileDropzone: ({ onFiles }: { onFiles: (files: File[]) => void }) => (
        <input
            aria-label="Evidence files"
            type="file"
            onChange={(e) => onFiles(Array.from(e.target.files ?? []))}
        />
    ),
    StagedFileCard: ({ file }: { file: File }) => <span>{file.name}</span>,
}));
const client = {
    id: 10,
    name: 'Person 10',
    preferred_name: null,
    nhi: null,
    site: null,
};
const props = { open: true, onClose: vi.fn(), client };
const admission = { id: 72, occurred_at: '2026-01-01T23:00:00Z' };
const response = (rows = [admission]) =>
    new Response(JSON.stringify({ admissions: rows }), {
        headers: { 'content-type': 'application/json' },
    });
function change(label: string, value: string) {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}
function next() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
function selectType(label: string) {
    fireEvent.click(screen.getByRole('button', { name: label }));
    next();
}
function details(label = 'Actually admitted', time = '2026-01-03T10:15') {
    change('Description', 'Hospital transfer and documentation.');
    change(label, time);
}
function reviewAndSubmit() {
    next();
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Log event' }));
}
async function pickAdmission() {
    fireEvent.click(
        await screen.findByRole('combobox', { name: 'Admission being ended' }),
    );
    fireEvent.click(await screen.findByRole('option', { name: /Admitted/ }));
}
beforeEach(() => {
    post.mockReset();
    fetchMock.mockReset();
    fetchMock.mockImplementation(() => Promise.resolve(response()));
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => vi.unstubAllGlobals());

describe('Clinical module and client-profile hospital entry points', () => {
    it('records a past closed stay from the module form, retaining witnesses and evidence', () => {
        render(<RecordEventDialog open onClose={vi.fn()} />);
        change('Client', '10');
        selectType('Hospital admission');
        details();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        );
        change('Actually discharged', '2026-01-04T11:00');
        fireEvent.change(screen.getByPlaceholderText('Add a witness name…'), {
            target: { value: 'Witness Smith' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));
        next();
        const file = new File(['evidence'], 'discharge.pdf', {
            type: 'application/pdf',
        });
        fireEvent.change(screen.getByLabelText('Evidence files'), {
            target: { files: [file] },
        });
        next();
        expect(
            screen.getByText('Actually admitted · Pacific/Auckland'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Actually discharged · Pacific/Auckland'),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/UTC\+13:00/)).toHaveLength(2);
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Log event' }));
        expect(post.mock.calls[0]).toEqual([
            '/health-clinical/events',
            expect.objectContaining({
                client_id: '10',
                event_type: 'hospital_admission',
                occurred_at: '2026-01-03T10:15:00+13:00',
                hospital_discharged_at: '2026-01-04T11:00:00+13:00',
                witnesses: ['Witness Smith'],
                attachments: [file],
            }),
            expect.objectContaining({
                forceFormData: true,
                preserveState: true,
            }),
        ]);
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_admission_id',
        );
        act(() =>
            post.mock.calls[0][2].onError({
                hospital_discharged_at:
                    'This stay overlaps another hospital admission.',
            }),
        );
        expect(
            screen.getByText(
                'The event has not been saved. Check these details:',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText(
                'This stay overlaps another hospital admission.',
            ).length,
        ).toBeGreaterThan(0);
        expect(screen.getByLabelText('Actually discharged')).toHaveValue(
            '2026-01-04T11:00',
        );
        expect(screen.getByLabelText('Description')).toHaveValue(
            'Hospital transfer and documentation.',
        );
        expect(screen.getByText('Witness Smith')).toBeInTheDocument();
    });
    it('requires the selected current admission from a locked client profile', async () => {
        render(<RecordEventDialog {...props} />);
        selectType('Hospital discharge');
        details('Actually discharged');
        await screen.findByRole('combobox', { name: 'Admission being ended' });
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        await pickAdmission();
        reviewAndSubmit();
        expect(post.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                client_id: '10',
                hospital_admission_id: 72,
                occurred_at: '2026-01-03T10:15:00+13:00',
            }),
        );
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_discharged_at',
        );
    });
    it('keeps the draft through lookup failure and retries only the current person', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response('<html>Login</html>', {
                headers: { 'content-type': 'text/html' },
            }),
        );
        render(<RecordEventDialog {...props} />);
        selectType('Hospital discharge');
        details('Actually discharged');
        fireEvent.click(
            await screen.findByRole('button', { name: 'Try again' }),
        );
        await pickAdmission();
        expect(screen.getByLabelText('Description')).toHaveValue(
            'Hospital transfer and documentation.',
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/clients/10/clinical/hospital-admissions',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(post).not.toHaveBeenCalled();
    });
    it('clears the selected admission when the module person changes', async () => {
        render(<RecordEventDialog open onClose={vi.fn()} />);
        change('Client', '10');
        selectType('Hospital discharge');
        details('Actually discharged');
        await pickAdmission();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        change('Client', '11');
        next();
        await screen.findByRole('combobox', { name: 'Admission being ended' });
        expect(
            screen.getByRole('combobox', { name: 'Admission being ended' }),
        ).toHaveTextContent('Choose the admission');
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/clients/11/clinical/hospital-admissions',
            expect.any(Object),
        );
    });
    it('ignores a stale lookup response after the profile changes', async () => {
        let resolveOld!: (value: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    resolveOld = resolve;
                }),
        );
        const view = render(<RecordEventDialog {...props} />);
        selectType('Hospital discharge');
        details('Actually discharged');
        const oldSignal = fetchMock.mock.calls[0][1].signal;
        view.rerender(
            <RecordEventDialog
                {...props}
                client={{ ...client, id: 11, name: 'Person 11' }}
            />,
        );
        expect(oldSignal.aborted).toBe(true);
        selectType('Hospital discharge');
        expect(screen.getByLabelText('Description')).toHaveValue('');
        await screen.findByRole('combobox', { name: 'Admission being ended' });
        await act(async () =>
            resolveOld(response([{ ...admission, id: 999 }])),
        );
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Admission being ended' }),
        );
        expect(
            screen.queryByRole('option', { name: /record 999/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: /record 72/ }),
        ).toBeInTheDocument();
    });
    it.each([
        '',
        '2099-01-01T12:00',
        '2026-01-02T10:00',
        '2026-09-27T02:30',
        '2026-04-05T02:30',
    ])(
        'blocks a missing, future, reversed or unresolved NZ discharge time: %s',
        (time) => {
            render(<RecordEventDialog {...props} />);
            selectType('Hospital admission');
            details();
            fireEvent.click(
                screen.getByRole('checkbox', {
                    name: 'This hospital stay has already ended',
                }),
            );
            change('Actually discharged', time);
            expect(
                screen.getByRole('button', { name: 'Continue' }),
            ).toBeDisabled();
            expect(post).not.toHaveBeenCalled();
        },
    );
    it('requires an explicit repeated-hour choice and submits the chosen occurrence', async () => {
        render(<RecordEventDialog {...props} />);
        selectType('Hospital admission');
        details('Actually admitted', '2026-04-05T01:30');
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        );
        change('Actually discharged', '2026-04-05T02:30');
        fireEvent.click(
            screen.getByRole('combobox', {
                name: 'Which occurrence of this time?',
            }),
        );
        fireEvent.click(
            await screen.findByRole('option', {
                name: 'Second occurrence · UTC+12:00',
            }),
        );
        reviewAndSubmit();
        expect(post.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                occurred_at: '2026-04-05T01:30:00+13:00',
                hospital_discharged_at: '2026-04-05T02:30:00+12:00',
            }),
        );
    });
    it('removes hospital fields on an ordinary event and retains its required immediate action', () => {
        render(<RecordEventDialog {...props} />);
        selectType('Hospital admission');
        details();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        );
        change('Actually discharged', '2026-01-04T11:00');
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        selectType('Fall');
        next();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.change(
            screen.getByPlaceholderText(
                'Required: document exactly what was done straight away.',
            ),
            { target: { value: 'Called for clinical help.' } },
        );
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Log event' }));
        expect(post.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                event_type: 'fall',
                immediate_action_taken: 'Called for clinical help.',
            }),
        );
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_discharged_at',
        );
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_admission_id',
        );
    });
    it('shows no-open-admission guidance and prevents a guessed discharge', async () => {
        fetchMock.mockResolvedValue(response([]));
        render(<RecordEventDialog {...props} />);
        selectType('Hospital discharge');
        details('Actually discharged');
        await screen.findByText(/No open hospital admission is recorded/);
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });
    it('prevents an actual discharge earlier than its selected admission', async () => {
        render(<RecordEventDialog {...props} />);
        selectType('Hospital discharge');
        details('Actually discharged', '2026-01-01T10:00');
        await pickAdmission();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(
            screen.getByText(
                'Discharge must be at or after the actual admission.',
            ),
        ).toBeInTheDocument();
    });
});

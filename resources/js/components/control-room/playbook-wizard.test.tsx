import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState, type ComponentProps } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlaybookWizard, type EditablePlaybook } from './playbook-wizard';

type VisitOptions = { onError?: (errors: Record<string, string>) => void };

const sent = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    transform: null as null | ((data: Record<string, unknown>) => Record<string, unknown>),
    /** Field errors the stand-in form shows, as Inertia would after a refused save. */
    errors: {} as Record<string, string>,
    /** When set, a save is refused with these field errors. */
    refuse: null as null | Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        // eslint-disable-next-line react-hooks/rules-of-hooks -- a stand-in for Inertia's useForm hook.
        const [data, setState] = useState<T>(initial);
        const send = (method: 'put' | 'post', url: string, opts?: VisitOptions) => {
            sent[method](url, sent.transform?.(data) ?? data);
            if (sent.refuse) opts?.onError?.(sent.refuse);
        };
        return {
            data,
            setData: (key: keyof T, value: unknown) =>
                setState((current) => ({ ...current, [key]: value })),
            errors: sent.errors,
            processing: false,
            transform: (fn: (data: Record<string, unknown>) => Record<string, unknown>) => {
                sent.transform = fn;
            },
            put: (url: string, opts?: VisitOptions) => send('put', url, opts),
            post: (url: string, opts?: VisitOptions) => send('post', url, opts),
            reset: () => undefined,
            clearErrors: () => undefined,
        };
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    sent.transform = null;
    sent.errors = {};
    sent.refuse = null;
});

const existing: EditablePlaybook = {
    id: 7,
    name: 'Reported outside the safe zone',
    description: '',
    category: 'safety',
    auto_attach: true,
    requires_approval: false,
    sla_acknowledge_minutes: '5',
    sla_response_minutes: '',
    sla_resolution_minutes: '',
    required_evidence: ['witness_statement'],
    trigger_alert_types: ['geofence_breach'],
    trigger_severities: ['high'],
    escalation_after_minutes: 20,
    steps: [
        {
            id: 11,
            title: 'Acknowledge alert',
            type: 'task',
            instructions: '',
            is_required: true,
            is_blocking: false,
            time_limit_minutes: '',
        },
    ],
};

/** Saved with "Start on its own" on but nothing chosen, so it never starts on its own. */
const flagged: EditablePlaybook = {
    ...existing,
    id: 8,
    name: 'Missed check-in',
    trigger_alert_types: [],
    trigger_severities: [],
};

const wizard = (
    playbook: EditablePlaybook | null = null,
    props: Partial<ComponentProps<typeof PlaybookWizard>> = {},
) =>
    render(
        <PlaybookWizard
            open
            onClose={() => undefined}
            categories={{ safety: 'Safety' }}
            stepTypes={{ task: 'Task' }}
            alertTypes={['fall_detected', 'geofence_breach', 'Lone Worker Emergency']}
            playbook={playbook}
            {...props}
        />,
    );

const next = () => fireEvent.click(screen.getByRole('button', { name: 'Next' }));
const chip = (name: string) => screen.getByRole('button', { name });

function startAddingFireAlarm() {
    fireEvent.change(screen.getByPlaceholderText('e.g. Missing person response'), {
        target: { value: 'Fire alarm' },
    });
    next();
    fireEvent.change(screen.getByPlaceholderText('e.g. Call the site lead'), {
        target: { value: 'Call the site lead' },
    });
    next();
}

describe('Procedure wizard', () => {
    it('speaks of procedures in sentence case', () => {
        wizard();

        expect(screen.getAllByText('Add response procedure').length).toBeGreaterThan(0);
        expect(screen.getByText("What's this procedure for?")).toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(/playbook|\bSLA\b/i);
    });

    it('editing keeps the alerts that start it and the escalation setting it has no field for', () => {
        wizard(existing);

        expect(screen.getAllByText('Edit response procedure').length).toBeGreaterThan(0);
        next();
        next();
        expect(screen.getByText('Witness statement')).toBeInTheDocument();
        expect(chip('Geofence breach')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('High')).toHaveAttribute('aria-pressed', 'true');
        next();
        expect(screen.getByText('acknowledge 5 min')).toBeInTheDocument();
        expect(screen.getByText('Alerts that start it')).toBeInTheDocument();
        expect(screen.getByText('Geofence breach')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(sent.put).toHaveBeenCalledWith(
            '/control-room/playbooks/7',
            expect.objectContaining({
                name: 'Reported outside the safe zone',
                auto_attach: true,
                trigger_alert_types: ['geofence_breach'],
                trigger_severities: ['high'],
                escalation_after_minutes: 20,
                sla_acknowledge_minutes: 5,
            }),
        );
    });

    it('adding a procedure started by hand sends empty lists and no escalation setting', () => {
        wizard();

        startAddingFireAlarm();
        expect(screen.queryByText('Alerts that start it')).not.toBeInTheDocument();
        next();
        expect(screen.queryByText('Alerts that start it')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(sent.post.mock.calls[0][0]).toBe('/control-room/playbooks');
        const body = sent.post.mock.calls[0][1] as Record<string, unknown>;
        expect(body).toMatchObject({
            auto_attach: false,
            trigger_alert_types: [],
            trigger_severities: [],
        });
        expect(body).not.toHaveProperty('escalation_after_minutes');
    });

    it('starting on its own needs at least one alert and one severity before going on', () => {
        wizard();

        startAddingFireAlarm();
        fireEvent.click(screen.getByRole('switch', { name: /Start on its own/ }));

        expect(screen.getByText('Alerts that start it')).toBeInTheDocument();
        expect(screen.getByText('Severities that start it')).toBeInTheDocument();
        expect(screen.getByText(/Choose at least one of each to go on/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();

        // Alert types read as plain words, severities low to critical.
        expect(chip('Lone worker emergency')).toBeInTheDocument();
        expect(
            screen
                .getByRole('group', { name: /^Severities that start it/ })
                .textContent,
        ).toBe('LowMediumHighCritical');

        fireEvent.click(chip('Fall detected'));
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
        fireEvent.click(chip('High'));
        expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
        expect(screen.queryByText(/Choose at least one of each to go on/)).not.toBeInTheDocument();

        next();
        expect(screen.getByText('Fall detected')).toBeInTheDocument();
        expect(screen.getByText('High')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(sent.post).toHaveBeenCalledWith(
            '/control-room/playbooks',
            expect.objectContaining({
                auto_attach: true,
                trigger_alert_types: ['fall_detected'],
                trigger_severities: ['high'],
            }),
        );
    });

    it('keeps a saved alert type that is no longer offered', () => {
        wizard({ ...existing, trigger_alert_types: ['Old door sensor'] });

        next();
        next();
        expect(chip('Old door sensor')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('Fall detected')).toHaveAttribute('aria-pressed', 'false');
    });

    it('opens a procedure that never starts on its own on "Starting and targets", blocked until alerts are chosen', () => {
        wizard(flagged, { openAt: 'automation' });

        expect(screen.getByText('When it starts and time targets')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: /Start on its own/ })).toBeChecked();
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();

        fireEvent.click(chip('Geofence breach'));
        fireEvent.click(chip('Critical'));
        expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
    });

    it('switching "Start on its own" off lets a procedure with nothing chosen be saved', () => {
        wizard(flagged, { openAt: 'automation' });

        fireEvent.click(screen.getByRole('switch', { name: /Start on its own/ }));
        expect(screen.queryByText('Alerts that start it')).not.toBeInTheDocument();
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(sent.put).toHaveBeenCalledWith(
            '/control-room/playbooks/8',
            expect.objectContaining({ auto_attach: false, trigger_alert_types: [], trigger_severities: [] }),
        );
    });

    it('a refused save goes back to the step with the problem and shows the reason', () => {
        const reason = 'Choose at least one severity that starts this procedure on its own.';
        sent.errors = { trigger_severities: reason };
        sent.refuse = { trigger_severities: reason };
        wizard(existing);

        next();
        next();
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(screen.getByText('When it starts and time targets')).toBeInTheDocument();
        expect(screen.getByText(reason)).toBeInTheDocument();
        expect(screen.getByRole('group', { name: /^Severities that start it/ })).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });
});

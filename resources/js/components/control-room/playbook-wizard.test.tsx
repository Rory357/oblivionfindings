import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PlaybookWizard, type EditablePlaybook } from './playbook-wizard';

const sent = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    transform: null as null | ((data: Record<string, unknown>) => Record<string, unknown>),
}));

vi.mock('@inertiajs/react', () => ({
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        // eslint-disable-next-line react-hooks/rules-of-hooks -- a stand-in for Inertia's useForm hook.
        const [data, setState] = useState<T>(initial);
        return {
            data,
            setData: (key: keyof T, value: unknown) =>
                setState((current) => ({ ...current, [key]: value })),
            errors: {},
            processing: false,
            transform: (fn: (data: Record<string, unknown>) => Record<string, unknown>) => {
                sent.transform = fn;
            },
            put: (url: string) => sent.put(url, sent.transform?.(data) ?? data),
            post: (url: string) => sent.post(url, sent.transform?.(data) ?? data),
            reset: () => undefined,
            clearErrors: () => undefined,
        };
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    sent.transform = null;
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

const wizard = (playbook: EditablePlaybook | null = null) =>
    render(
        <PlaybookWizard
            open
            onClose={() => undefined}
            categories={{ safety: 'Safety' }}
            stepTypes={{ task: 'Task' }}
            playbook={playbook}
        />,
    );

describe('Procedure wizard', () => {
    it('speaks of procedures in sentence case', () => {
        wizard();

        expect(screen.getAllByText('Add response procedure').length).toBeGreaterThan(0);
        expect(screen.getByText("What's this procedure for?")).toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(/playbook|\bSLA\b/i);
    });

    it('editing sends back the trigger and escalation settings it has no fields for', () => {
        wizard(existing);

        expect(screen.getAllByText('Edit response procedure').length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('Witness statement')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByText('acknowledge 5 min')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        expect(sent.put).toHaveBeenCalledWith(
            '/control-room/playbooks/7',
            expect.objectContaining({
                name: 'Reported outside the safe zone',
                trigger_alert_types: ['geofence_breach'],
                trigger_severities: ['high'],
                escalation_after_minutes: 20,
                sla_acknowledge_minutes: 5,
            }),
        );
    });

    it('adding a procedure sends no trigger settings of its own', () => {
        wizard();

        fireEvent.change(screen.getByPlaceholderText('e.g. Missing person response'), {
            target: { value: 'Fire alarm' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.change(screen.getByPlaceholderText('e.g. Call the site lead'), {
            target: { value: 'Call the site lead' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save procedure' }));

        const body = sent.post.mock.calls[0][1] as Record<string, unknown>;
        expect(sent.post.mock.calls[0][0]).toBe('/control-room/playbooks');
        expect(body).not.toHaveProperty('trigger_alert_types');
        expect(body).not.toHaveProperty('escalation_after_minutes');
    });
});

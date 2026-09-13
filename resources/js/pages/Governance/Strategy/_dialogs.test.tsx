import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    serverErrors: null as Record<string, string> | null,
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { replace: vi.fn() },
        usePage: () => ({ url: '/governance/strategy', props: {} }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transformRef = React.useRef<(d: T) => object>((d) => d);
            const submit =
                (spy: typeof inertia.post) =>
                (
                    url: string,
                    options: { onError?: (e: Record<string, string>) => void },
                ) => {
                    spy(url, transformRef.current(data), options);
                    if (inertia.serverErrors) {
                        setErrors(inertia.serverErrors);
                        options.onError?.(inertia.serverErrors);
                    }
                };
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (fn: (d: T) => object) => {
                    transformRef.current = fn;
                },
                post: submit(inertia.post),
                put: submit(inertia.put),
            };
        },
    };
});

import { StrategicPlanWizardDialog } from './_dialogs';

const options = {
    horizons: { '3_year': '3-year plan', '5_year': '5-year plan' },
    pillars: { safety: 'Safety', quality: 'Quality', people: 'People' },
};

const clickButton = (name: RegExp) =>
    fireEvent.click(screen.getByRole('button', { name }));

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.serverErrors = null;
});

describe('StrategicPlanWizardDialog', () => {
    it('validates the plan and nested goals, then creates with values and goals', () => {
        const { container } = render(
            <StrategicPlanWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
            />,
        );

        clickButton(/continue/i);
        expect(screen.getByText('Give the plan a title.')).toBeTruthy();
        expect(screen.getByText('Choose the start date.')).toBeTruthy();

        fireEvent.change(screen.getByPlaceholderText(/Strategic Plan 2026/), {
            target: { value: 'Strategic Plan 2027–2029' },
        });
        clickButton(/5-year plan/i);
        const dates =
            container.ownerDocument.querySelectorAll('input[type="date"]');
        fireEvent.change(dates[0], { target: { value: '2027-01-01' } });
        fireEvent.change(dates[1], { target: { value: '2026-12-31' } });
        clickButton(/continue/i);
        expect(
            screen.getByText('The plan must end after it starts.'),
        ).toBeTruthy();
        fireEvent.change(dates[1], { target: { value: '2031-12-31' } });
        clickButton(/continue/i);

        // Direction
        fireEvent.change(screen.getByPlaceholderText(/good life/), {
            target: { value: 'Every person lives a good life at home.' },
        });
        clickButton(/continue/i);

        // Values
        clickButton(/add value/i);
        fireEvent.change(screen.getByPlaceholderText('e.g. Manaakitanga'), {
            target: { value: 'Manaakitanga' },
        });
        clickButton(/continue/i);

        // Goals: an incomplete goal blocks Continue.
        clickButton(/add goal/i);
        fireEvent.change(
            screen.getByPlaceholderText('e.g. Zero avoidable harm'),
            {
                target: { value: 'Zero avoidable harm' },
            },
        );
        clickButton(/continue/i);
        expect(
            screen.getByText('Describe what the goal achieves.'),
        ).toBeTruthy();
        fireEvent.change(
            screen.getByPlaceholderText(
                'What the goal achieves and why it matters.',
            ),
            { target: { value: 'Reduce restrictive practice.' } },
        );
        clickButton(/add key result/i);
        fireEvent.change(screen.getByLabelText('Goal 1 key result 1'), {
            target: { value: 'Restraint use halved' },
        });
        clickButton(/continue/i);
        clickButton(/create plan/i);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/strategy');
        expect(payload).toEqual({
            title: 'Strategic Plan 2027–2029',
            planning_horizon: '5_year',
            period_start: '2027-01-01',
            period_end: '2031-12-31',
            vision_statement: 'Every person lives a good life at home.',
            mission_statement: null,
            values: [{ value: 'Manaakitanga', description: null }],
            goals: [
                {
                    title: 'Zero avoidable harm',
                    description: 'Reduce restrictive practice.',
                    pillar: 'quality',
                    timeframe: null,
                    key_results: [{ result: 'Restraint use halved' }],
                },
            ],
        });
    });

    it('opens on Goals, keeps a legacy horizon and jumps to a server goal error', () => {
        inertia.serverErrors = { 'goals.0.title': 'The goal title is taken.' };

        render(
            <StrategicPlanWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                initialStep="goals"
                plan={{
                    id: 7,
                    title: 'Annual plan',
                    planning_horizon: '1_year',
                    period_start: '2026-01-01T00:00:00.000000Z',
                    period_end: '2026-12-31T00:00:00.000000Z',
                    vision_statement: 'Vision',
                    mission_statement: 'Mission',
                    values: [
                        'Respect',
                        { value: 'Integrity', description: 'Do right' },
                    ],
                    status: 'draft',
                    version_number: 3,
                    goals: [
                        {
                            id: 1,
                            title: 'Existing goal',
                            pillar: 'safety',
                            timeframe: null,
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Strategic goals')).toBeTruthy();
        expect(screen.getByText('Existing goal')).toBeTruthy();

        clickButton(/^add goal$/i);
        fireEvent.change(
            screen.getByPlaceholderText('e.g. Zero avoidable harm'),
            {
                target: { value: 'Open a respite home' },
            },
        );
        fireEvent.change(
            screen.getByPlaceholderText(
                'What the goal achieves and why it matters.',
            ),
            { target: { value: 'Short breaks for whānau.' } },
        );
        clickButton(/review/i);
        clickButton(/save plan/i);

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/strategy/7');
        expect(payload).not.toHaveProperty('planning_horizon');
        expect(payload).toMatchObject({
            period_start: '2026-01-01',
            period_end: '2026-12-31',
            values: [
                { value: 'Respect', description: null },
                { value: 'Integrity', description: 'Do right' },
            ],
            goals: [{ title: 'Open a respite home', pillar: 'quality' }],
        });

        expect(screen.getByText('Strategic goals')).toBeTruthy();
        expect(screen.getByText('The goal title is taken.')).toBeTruthy();
    });
});

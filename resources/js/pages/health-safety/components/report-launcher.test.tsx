import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ can: {} as Record<string, unknown> }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    usePage: () => ({ props: { auth: { can: page.can } } }),
}));

import { ReportLauncher, launcherWorkflows } from './report-launcher';

const labels = (can: Record<string, unknown>) =>
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- partial permission fixtures
    launcherWorkflows(can as any).map((workflow) => workflow.label);

describe('Report something', () => {
    it('shows a view-only reader nothing they would be refused', () => {
        expect(labels({ hazards: { view: true } })).toEqual([]);
    });

    it('keeps the incident and safeguarding choices for anyone allowed to report them', () => {
        expect(labels({ incidents: { create: true }, safeguarding: { create: true } })).toEqual([
            'A person was hurt, or nearly hurt',
            'You’re worried a person is being harmed',
        ]);
    });

    it('says what happened in plain words and follows each form’s permission', () => {
        expect(
            labels({
                hazards: { view: true, create: true },
                incidents: { create: true },
                restraints: { create: true },
            }),
        ).toEqual([
            'A person was hurt, or nearly hurt',
            'A staff member was hurt at work',
            'You gave first aid',
            'A person’s movement or freedom was limited',
            'Something could cause harm',
            'A chemical got on or into someone',
        ]);
        expect(labels({ hazards: { view: true, manage: true } })).toContain(
            'You’re about to work alone',
        );
    });

    it('offers create-only reporters the injury and restraint forms, nothing more', () => {
        expect(
            labels({
                incidents: { create: true },
                safeguarding: { create: true },
                injuries: { report: true },
                restraints: { report: true },
            }),
        ).toEqual([
            'A person was hurt, or nearly hurt',
            'A staff member was hurt at work',
            'A person’s movement or freedom was limited',
            'You’re worried a person is being harmed',
        ]);
    });

    it('names the main button after the form it opens and says what the chosen form will ask', () => {
        page.can = { incidents: { create: true }, safeguarding: { create: true } };
        const onWorkflow = vi.fn();
        render(<ReportLauncher open onClose={() => {}} onWorkflow={onWorkflow} />);
        expect(screen.getByText('Who, where, what happened and what you did straight away.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: /worried a person is being harmed/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Raise a safeguarding concern' }));
        expect(onWorkflow).toHaveBeenCalledWith('safeguarding');
    });
});

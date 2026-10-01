import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { Circle, Pill } from 'lucide-react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { WizardShell } from './shell';

// The rail layout follows the `lg` viewport (1024 CSS px) via useIsDesktopLg.
const viewport = vi.hoisted(() => ({ wide: true }));
vi.mock('@/hooks/use-mobile', () => ({
    useIsDesktopLg: () => viewport.wide,
    useIsMobile: () => false,
}));

const steps = [
    { key: 'who', label: 'Person', blurb: 'Who it is for', icon: Circle },
    { key: 'med', label: 'Medicine', blurb: 'What is given', icon: Pill },
    { key: 'review', label: 'Review', blurb: 'Confirm and save', icon: Circle },
];

function renderShell(onStepClick = vi.fn()) {
    render(
        <WizardShell
            open
            onClose={vi.fn()}
            title="Record a dose"
            description="Layout check"
            railIcon={Pill}
            railTitle="Record a dose"
            railSub="Medication"
            steps={steps}
            stepIndex={1}
            onStepClick={onStepClick}
            pct={40}
            railExtra={<p>Running total: 3 doses</p>}
        >
            <p>Step body</p>
        </WizardShell>,
    );
    const region = (name: string) =>
        document.querySelector<HTMLElement>(`[data-wizard-region="${name}"]`)!;
    return { onStepClick, region };
}

afterEach(async () => {
    cleanup();
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});

describe('WizardShell rail layout', () => {
    it('keeps the 248px rail column, with its extras, from 1024 CSS px', () => {
        viewport.wide = true;
        const { region } = renderShell();
        const rail = region('rail');

        expect(rail).toHaveAttribute('data-layout', 'rail');
        expect(rail).toHaveClass('w-[248px]', 'flex-col', 'border-r');
        expect(rail).toContainElement(
            screen.getByText('Running total: 3 doses'),
        );
        expect(region('rail-extra')).toBeNull();
        expect(screen.getByText('Who it is for')).not.toHaveClass('sr-only');
        expect(screen.getByText('Completeness')).not.toHaveClass('sr-only');
    });

    it('collapses into a top stepper below 1024 CSS px, keeping step names accessible', () => {
        viewport.wide = false;
        const { region, onStepClick } = renderShell();
        const rail = region('rail');

        expect(rail).toHaveAttribute('data-layout', 'strip');
        expect(rail).not.toHaveClass('w-[248px]');
        expect(rail).toHaveClass('overflow-x-auto', 'border-b');

        // Extras move into the scrolling body, after the step content, once.
        expect(rail).not.toContainElement(
            screen.getByText('Running total: 3 doses'),
        );
        expect(region('rail-extra')).toContainElement(
            screen.getByText('Running total: 3 doses'),
        );
        expect(region('body')).toContainElement(region('rail-extra'));

        // Every step keeps its full accessible name; only the current one
        // shows it, and it is marked as the current step.
        const current = screen.getByRole('button', {
            name: /Medicine\s*What is given/,
        });
        const other = screen.getByRole('button', {
            name: /Person\s*Who it is for/,
        });
        expect(current).toHaveAttribute('aria-current', 'step');
        expect(other).not.toHaveAttribute('aria-current');
        expect(within(current).getByText('Medicine')).not.toHaveClass(
            'sr-only',
        );
        expect(screen.getByText('Person').parentElement).toHaveClass('sr-only');
        expect(screen.getByText('What is given')).toHaveClass('sr-only');
        expect(other).toHaveClass('frontline-tap');

        fireEvent.click(other);
        expect(onStepClick).toHaveBeenCalledWith(0);

        expect(
            screen.getByRole('progressbar', { name: 'Completeness' }),
        ).toHaveAttribute('aria-valuenow', '40');
    });
});

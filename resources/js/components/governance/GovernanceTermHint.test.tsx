import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { GOVERNANCE_GLOSSARY } from '@/lib/governance-glossary';

import { GovernanceExplainer, GovernanceTermHint } from './GovernanceTermHint';

/**
 * jsdom does not synthesise the click a browser fires when Enter/Space is
 * pressed on a focused <button>, so keyboard activation is modelled as
 * focus → keydown → click on the real button element.
 */
function pressEnter(element: HTMLElement) {
    element.focus();
    fireEvent.keyDown(element, { key: 'Enter', code: 'Enter' });
    fireEvent.click(element);
    fireEvent.keyUp(element, { key: 'Enter', code: 'Enter' });
}

describe('GovernanceTermHint', () => {
    it('renders a focusable icon button with an accessible question name', () => {
        render(<GovernanceTermHint term="quorum" />);

        const trigger = screen.getByRole('button', {
            name: 'What does quorum mean?',
        });
        expect(trigger.tagName).toBe('BUTTON');
        expect(trigger).toHaveAttribute('type', 'button');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        trigger.focus();
        expect(trigger).toHaveFocus();
    });

    it('opens from the keyboard and shows the plain definition', async () => {
        render(<GovernanceTermHint term="quorum" />);
        const trigger = screen.getByRole('button', {
            name: 'What does quorum mean?',
        });

        pressEnter(trigger);

        const popover = await screen.findByRole('dialog');
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(popover).toHaveAccessibleName('Quorum');
        expect(popover).toHaveAccessibleDescription(
            GOVERNANCE_GLOSSARY.quorum.definition,
        );
        expect(
            screen.getByText(GOVERNANCE_GLOSSARY.quorum.definition),
        ).toBeInTheDocument();
    });

    it('closes with Escape and returns focus to the trigger', async () => {
        render(<GovernanceTermHint term="board_limit" />);
        const trigger = screen.getByRole('button', {
            name: 'What does the board’s limit mean?',
        });

        pressEnter(trigger);
        const popover = await screen.findByRole('dialog');

        fireEvent.keyDown(popover, { key: 'Escape', code: 'Escape' });

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(trigger).toHaveFocus();
    });

    it('can use the term text itself as the trigger', async () => {
        render(
            <p>
                A vote needs a{' '}
                <GovernanceTermHint term="quorum">quorum</GovernanceTermHint>.
            </p>,
        );

        const trigger = screen.getByRole('button', {
            name: 'quorum, what does this mean?',
        });
        expect(trigger).toHaveTextContent('quorum');

        pressEnter(trigger);

        expect(await screen.findByRole('dialog')).toHaveAccessibleDescription(
            GOVERNANCE_GLOSSARY.quorum.definition,
        );
    });
});

describe('GovernanceExplainer', () => {
    it('renders a labelled note with its title and body', () => {
        render(
            <GovernanceExplainer
                title="How board voting works"
                body="Each voting member votes For, Against or Abstain."
            />,
        );

        const note = screen.getByRole('note', {
            name: 'How board voting works',
        });
        expect(
            screen.getByRole('heading', {
                level: 3,
                name: 'How board voting works',
            }),
        ).toBeInTheDocument();
        expect(note).toHaveTextContent(
            'Each voting member votes For, Against or Abstain.',
        );
    });
});

describe('glossary', () => {
    it('keeps every definition short and plain', () => {
        for (const [key, entry] of Object.entries(GOVERNANCE_GLOSSARY)) {
            expect(entry.term, key).toMatch(/^\p{Lu}/u);
            expect(entry.definition.length, key).toBeLessThanOrEqual(220);
            expect(entry.definition, key).not.toMatch(
                /\b(immutable|snapshot|recus|attest|electorate|appetite|inherent|residual|capex|opex|YTD)/i,
            );
        }
    });
});

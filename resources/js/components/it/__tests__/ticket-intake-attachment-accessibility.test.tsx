import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ItWizard } from '../it-wizards';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: {
            auth: { user: { id: 230 } },
            draftRecovery: { enabled: false },
        },
    }),
}));

beforeEach(() => {
    clearItTicketDraftMemory();
    sessionStorage.clear();
    localStorage.clear();
});
afterEach(() => {
    cleanup();
    clearItTicketDraftMemory();
    sessionStorage.clear();
    localStorage.clear();
    vi.restoreAllMocks();
});

describe.each(['raise', 'ticket'] as const)(
    '%s attachment accessibility',
    (type) => {
        it('keeps the Photos/files label, keyboard picker and validation description associated after file changes', () => {
            const errors = vi
                .spyOn(console, 'error')
                .mockImplementation(() => undefined);
            render(
                <ItWizard
                    modal={{ type }}
                    assignees={[]}
                    siteOptions={[{ id: 9403, name: 'Approved Site A' }]}
                    onClose={vi.fn()}
                />,
            );
            if (type === 'raise')
                fireEvent.click(
                    screen.getByRole('button', { name: /Add more details/ }),
                );
            const label = screen.getByText('Photos or files').closest('label')!;
            const input = label.control as HTMLInputElement;
            const zone = screen.getByRole('button', {
                name: /Photos or files.*Drop a photo/,
            });
            const originalId = zone.id;
            expect(originalId).not.toBe('');
            expect(input).toHaveAttribute('type', 'file');
            expect(label).toHaveAttribute('for', input.id);
            const picked = vi.fn();
            input.addEventListener('click', picked);
            fireEvent.click(label);
            zone.focus();
            fireEvent.keyDown(zone, { key: 'Enter' });
            fireEvent.keyDown(zone, { key: ' ' });
            expect(picked).toHaveBeenCalledTimes(3);
            expect(zone).toHaveFocus();

            fireEvent.change(input, {
                target: {
                    files: [new File(['Private evidence'], 'evidence.txt')],
                },
            });
            expect(screen.getByText('evidence.txt')).toBeVisible();
            fireEvent.change(input, {
                target: { files: [new File(['Rejected'], 'program.exe')] },
            });
            const error = screen.getByText(
                /Choose an image, PDF, text, CSV, Word or Excel file/,
            );
            expect(error.id).not.toBe('');
            expect(zone).toHaveAttribute('aria-describedby', error.id);
            expect(zone).toHaveAccessibleDescription(error.textContent!);
            expect(zone).toHaveAttribute('aria-invalid', 'true');
            expect(screen.getByText('evidence.txt')).toBeVisible();
            expect(screen.queryByText('program.exe')).toBeNull();
            expect(zone.id).toBe(originalId);

            fireEvent.change(input, {
                target: { files: [new File(['Corrected'], 'corrected.txt')] },
            });
            expect(zone).not.toHaveAttribute('aria-describedby');
            expect(zone).toHaveAttribute('aria-invalid', 'false');
            expect(screen.getByText('evidence.txt')).toBeVisible();
            expect(screen.getByText('corrected.txt')).toBeVisible();
            expect(errors.mock.calls.flat().join(' ')).not.toContain(
                'React.Fragment',
            );
        });
    },
);

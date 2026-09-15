import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { reload: inertia.reload, visit: vi.fn(), post: vi.fn() },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const transform = React.useRef<(values: T) => unknown>((v) => v);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (next: (values: T) => unknown) => {
                    transform.current = next;
                },
                post: (url: string) =>
                    inertia.post(url, transform.current(data)),
            };
        },
    };
});

vi.mock('@/routes/governance/actions', () => ({
    progress: { url: ({ action }: { action: number }) => `/governance/actions/${action}/progress` },
    complete: { url: ({ action }: { action: number }) => `/governance/actions/${action}/complete` },
    escalate: { url: ({ action }: { action: number }) => `/governance/actions/${action}/escalate` },
    block: { url: ({ action }: { action: number }) => `/governance/actions/${action}/block` },
}));

import {
    CompleteActionDialog,
    EscalateActionDialog,
    completionBlockedReason,
    type ActionDialogRecord,
} from './_dialogs';
import { evidenceFileProblem, evidenceState } from './_helpers';

const action: ActionDialogRecord = {
    id: 12,
    title: 'Issue the revised contract',
    action_reference: 'ACT-2026-004',
    version_number: 3,
    evidence_required: true,
    evidence: [],
    legacy_evidence: [],
};

describe('action evidence wording and rules', () => {
    it('says whether evidence was provided, not only whether it is required', () => {
        expect(evidenceState(true, 0).label).toBe('Needed — not yet added');
        expect(evidenceState(true, 2).label).toBe('Added (2)');
        expect(evidenceState(false, 0).label).toBe('Not needed');
    });

    it('explains why a file cannot be uploaded', () => {
        expect(evidenceFileProblem({ name: 'contract.pdf', size: 1000 })).toBeNull();
        expect(evidenceFileProblem({ name: 'page.html', size: 10 })).toContain(
            "can't be used",
        );
        expect(
            evidenceFileProblem({ name: 'scan.pdf', size: 30 * 1024 * 1024 }),
        ).toContain('bigger than 20 MB');
    });

    it('gives a visible reason while Mark as done is unavailable', () => {
        expect(completionBlockedReason('', true, 0)).toBe(
            'Add a note about what was done to continue.',
        );
        expect(completionBlockedReason('Signed and filed', true, 0)).toBe(
            'Upload evidence to continue.',
        );
        expect(completionBlockedReason('Signed and filed', true, 1)).toBeNull();
    });
});

describe('CompleteActionDialog', () => {
    beforeEach(() => inertia.post.mockReset());
    afterEach(cleanup);

    it('uploads files instead of asking for storage paths', () => {
        render(<CompleteActionDialog open onClose={vi.fn()} action={action} />);

        expect(screen.queryByText(/managed storage/i)).toBeNull();
        expect(screen.queryByPlaceholderText(/governance\/evidence/i)).toBeNull();
        expect(screen.getByText('Drag and drop evidence here')).toBeTruthy();
        expect(screen.getByText('Add a note about what was done to continue.')).toBeTruthy();
    });

    it('asks for confirmation, then sends the uploaded evidence ids', () => {
        render(
            <CompleteActionDialog
                open
                onClose={vi.fn()}
                action={{
                    ...action,
                    evidence: [
                        {
                            id: 91,
                            original_name: 'Signed contract.pdf',
                            mime_type: 'application/pdf',
                            size_bytes: 2048,
                            uploaded_at: '2026-09-14T02:00:00Z',
                            uploaded_by_name: 'Aroha',
                            download_url: '/governance/actions/12/evidence/91/download',
                            can_remove: true,
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Signed contract.pdf')).toBeTruthy();
        fireEvent.change(screen.getByLabelText(/what was done/i), {
            target: { value: 'The contract was signed and filed.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Mark as done' }));

        // Nothing is sent until the member confirms the effect.
        expect(inertia.post).not.toHaveBeenCalled();
        expect(screen.getByText('Mark this action as done?')).toBeTruthy();

        const confirmButtons = screen.getAllByRole('button', { name: 'Mark as done' });
        fireEvent.click(confirmButtons[confirmButtons.length - 1]);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/actions/12/complete');
        expect(payload).toMatchObject({
            completion_notes: 'The contract was signed and filed.',
            evidence_ids: [91],
            expected_version: 3,
        });
        expect(payload).not.toHaveProperty('evidence_files');
    });
});

describe('EscalateActionDialog', () => {
    afterEach(cleanup);

    it('says who is told, and never mentions a secretariat', () => {
        render(<EscalateActionDialog open onClose={vi.fn()} action={action} />);

        expect(
            screen.getByText(/The board chair and secretary get an email and a notification/),
        ).toBeTruthy();
        expect(screen.queryByText(/secretariat/i)).toBeNull();
        expect(screen.getByText('Add a reason to continue.')).toBeTruthy();
    });
});

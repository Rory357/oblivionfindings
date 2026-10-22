import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PayrollIndex from './index';

// Net-pay bank evidence used to be a chain of window.prompt() boxes. Each
// action now opens one dialog with labelled, validated fields, and nothing is
// sent until its submit button.

type VisitOptions = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const visit = vi.hoisted(() => ({
    errors: null as Record<string, string> | null,
}));
const router = vi.hoisted(() => ({
    post: vi.fn((_url: string, _data: unknown, options?: VisitOptions) => {
        options?.onStart?.();
        if (visit.errors) options?.onError?.(visit.errors);
        else options?.onSuccess?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    usePage: () => ({ props: { errors: {} } }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageLayout: ({
        hero,
        children,
    }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>
            {hero}
            {children}
        </>
    ),
}));
vi.mock('@/components/hr', () => ({ PayrollTabs: () => null }));
vi.mock('@/components/hr/payroll-hero', () => ({ PayrollHero: () => null }));
vi.mock('@/components/hr/payroll-wizards', () => ({
    CreateRunWizard: () => null,
    ExportProfileWizard: () => null,
}));
vi.mock('@/components/ui/laravel-pagination', () => ({
    LaravelPagination: () => null,
}));

type Settlement = 'exported' | 'accepted' | 'settled';

function run(id: number, status: Settlement, acceptance: string | null = null) {
    return {
        id,
        period_start: '2026-10-01',
        period_end: '2026-10-14',
        status: 'exported' as const,
        total_hours: 120,
        total_gross: 4200,
        items_count: 4,
        created_at: '2026-10-15T09:00:00Z',
        locked_at: '2026-10-15T09:00:00Z',
        exported_at: '2026-10-15T09:00:00Z',
        gl_posted_at: '2026-10-15T09:00:00Z',
        gl_error: null,
        net_paid_at: status === 'settled' ? '2026-10-16T09:00:00Z' : null,
        net_settlement: {
            status,
            artifact_sha256: 'abc',
            exported_at: '2026-10-15T09:00:00Z',
            accepted_at: acceptance ? '2026-10-16T09:00:00Z' : null,
            acceptance_reference: acceptance,
            rejection_reason: null,
        },
        export_profile: null,
        validation_errors: [],
    };
}

function renderPage(runs: ReturnType<typeof run>[]) {
    render(
        <PayrollIndex
            runs={{
                data: runs,
                links: [],
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: runs.length,
            }}
            profiles={[]}
            exportFieldOptions={[]}
            statusCounts={{ total: 1, draft: 0, locked: 0, exported: 1 }}
            can={{ manage: true, export_data: true }}
        />,
    );
}

const mobile = () =>
    within(document.querySelector('[data-payroll-mobile]') as HTMLElement);
const dialog = () => screen.getByRole('dialog');

function type(label: RegExp, value: string) {
    fireEvent.change(within(dialog()).getByLabelText(label), {
        target: { value },
    });
}

beforeEach(() => {
    router.post.mockClear();
    visit.errors = null;
});

describe('Payroll › accept and settle net pay', () => {
    it('asks for the acceptance in labelled fields, then sends the same payload', () => {
        renderPage([run(7, 'exported')]);

        fireEvent.click(
            mobile().getByRole('button', {
                name: /record acceptance & settle/i,
            }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Record bank acceptance and settle?'),
        ).toBeTruthy();

        // Blank fields block the request and say what is missing.
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Record acceptance and settle',
            }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Enter the bank’s reference.'),
        ).toBeTruthy();
        expect(
            within(dialog()).getByText(
                'Enter the confirmation code or evidence reference.',
            ),
        ).toBeTruthy();

        type(/bank acceptance reference/i, ' ACC-991 ');
        type(/confirmation code or evidence reference/i, 'BNZ-OK-55');
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Record acceptance and settle',
            }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/payroll/runs/7/pay',
            {
                idempotency_key: 'payroll-net:7:ACC-991',
                acceptance_reference: 'ACC-991',
                acceptance_evidence: { confirmation_digest: 'BNZ-OK-55' },
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('settles an already-accepted file only after asking', () => {
        renderPage([run(8, 'accepted', 'ACC-100')]);

        fireEvent.click(
            mobile().getByRole('button', {
                name: /record acceptance & settle/i,
            }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(within(dialog()).getByText('Settle this net pay?')).toBeTruthy();
        expect(within(dialog()).getByText('ACC-100')).toBeTruthy();
        expect(within(dialog()).queryByRole('textbox')).toBeNull();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Settle net pay' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/hr/payroll/runs/8/pay',
            {
                idempotency_key: 'payroll-net:8:ACC-100',
                acceptance_reference: 'ACC-100',
                acceptance_evidence: undefined,
            },
            expect.anything(),
        );
    });

    it('Cancel sends nothing', () => {
        renderPage([run(9, 'exported')]);
        fireEvent.click(
            mobile().getByRole('button', {
                name: /record acceptance & settle/i,
            }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(router.post).not.toHaveBeenCalled();
    });
});

describe('Payroll › record bank rejection', () => {
    it('collects reference, reason and evidence before rejecting', () => {
        renderPage([run(10, 'exported')]);

        fireEvent.click(
            mobile().getByRole('button', { name: /record rejection/i }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Record a bank rejection?'),
        ).toBeTruthy();
        const submit = within(dialog()).getByRole('button', {
            name: 'Record rejection',
        });

        type(/bank rejection reference/i, 'REJ-4');
        type(/confirmation code or evidence reference/i, 'BNZ-NO-4');
        fireEvent.click(submit);
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Enter the reason the bank gave.'),
        ).toBeTruthy();

        type(/reason the bank gave/i, 'Account closed');
        fireEvent.click(submit);

        expect(router.post).toHaveBeenCalledWith(
            '/hr/payroll/runs/10/reject-net-pay',
            {
                idempotency_key: 'payroll-reject:10:REJ-4:BNZ-NO-4',
                reference: 'REJ-4',
                reason: 'Account closed',
                evidence: { rejection_digest: 'BNZ-NO-4' },
            },
            expect.anything(),
        );
    });

    it('keeps the dialog open and shows the server’s reason when it refuses', () => {
        visit.errors = {
            net_pay: 'The same person cannot prepare and reject this file.',
        };
        renderPage([run(11, 'exported')]);

        fireEvent.click(
            mobile().getByRole('button', { name: /record rejection/i }),
        );
        type(/bank rejection reference/i, 'REJ-5');
        type(/reason the bank gave/i, 'Account closed');
        type(/confirmation code or evidence reference/i, 'BNZ-NO-5');
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Record rejection' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(
            within(dialog()).getByText(
                'The same person cannot prepare and reject this file.',
            ),
        ).toBeTruthy();
    });
});

describe('Payroll › record bank reconciliation', () => {
    it('needs a whole-number bank transaction before reconciling', () => {
        renderPage([run(12, 'settled', 'ACC-12')]);

        fireEvent.click(
            mobile().getByRole('button', { name: /record reconciliation/i }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Record the bank reconciliation?'),
        ).toBeTruthy();
        const submit = within(dialog()).getByRole('button', {
            name: 'Record reconciliation',
        });

        type(/cleared bank transaction number/i, '12.5');
        type(/bank reconciliation reference/i, 'REC-1');
        type(/confirmation code or evidence reference/i, 'STMT-77');
        fireEvent.click(submit);
        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText(
                'Use the whole number shown on the bank transaction.',
            ),
        ).toBeTruthy();

        type(/cleared bank transaction number/i, '10482');
        fireEvent.click(submit);

        expect(router.post).toHaveBeenCalledWith(
            '/hr/payroll/runs/12/reconcile-net-pay',
            {
                idempotency_key: 'payroll-reconcile:12:10482',
                bank_transaction_id: 10482,
                reference: 'REC-1',
                evidence: { reconciliation_digest: 'STMT-77' },
            },
            expect.anything(),
        );
    });
});

describe('Payroll › no browser prompts left', () => {
    it('never calls window.prompt', () => {
        const prompt = vi.spyOn(window, 'prompt');
        renderPage([run(13, 'exported')]);
        fireEvent.click(
            mobile().getByRole('button', {
                name: /record acceptance & settle/i,
            }),
        );
        expect(prompt).not.toHaveBeenCalled();
        prompt.mockRestore();
    });
});

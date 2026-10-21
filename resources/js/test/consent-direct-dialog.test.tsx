import { Button } from '@/components/ui/button';
import ConsentsIndex from '@/pages/operations/clients/consents/Index';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ post: vi.fn(), canRecord: true }));
vi.mock('@inertiajs/react', () => ({
    router: { post: state.post },
    Head: () => null,
    usePage: () => ({
        props: {
            auth: {
                can: { consents: { record: state.canRecord, request: true } },
            },
        },
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageLayout: ({
        header,
        children,
    }: {
        header: ReactNode;
        children: ReactNode;
    }) => (
        <>
            {header}
            {children}
        </>
    ),
    PageHeader: ({ actions }: { actions: ReactNode }) => <>{actions}</>,
    PageHeaderPrimaryButton: ({
        children,
        onClick,
        disabled,
    }: {
        children: ReactNode;
        onClick: () => void;
        disabled: boolean;
    }) => (
        <Button onClick={onClick} disabled={disabled}>
            {children}
        </Button>
    ),
    PageHeaderSearch: () => null,
    PageHeaderFilterSelect: () => null,
    PageHeaderMeterBig: () => null,
    PageHeaderMeterBlock: () => null,
    PageHeaderMeterCaption: () => null,
    PageHeaderMeterDonut: () => null,
    PageHeaderRail: () => null,
    PageHeaderStatusChip: () => null,
}));
const consentType = {
    id: 7,
    name: 'Fictional support consent',
    purpose: 'The exact fictional purpose to review.',
    description: 'Fictional only.',
    version: 2,
    requires_capacity_assessment: false,
};
function show(requiresAssessment = false) {
    render(
        <ConsentsIndex
            client={{ id: 2, first_name: 'Fictional', last_name: 'Person' }}
            consents={[]}
            stats={{
                total: 0,
                active: 0,
                expiring_soon: 0,
                expired: 0,
                withdrawn: 0,
            }}
            consent_types={[
                {
                    ...consentType,
                    requires_capacity_assessment: requiresAssessment,
                },
            ]}
        />,
    );
}
async function openAndChoose() {
    fireEvent.click(
        screen.getAllByRole('button', {
            name: 'Record consent',
            exact: true,
        })[0],
    );
    fireEvent.pointerDown(
        screen.getByRole('combobox', { name: 'Consent Type *' }),
        { button: 0, ctrlKey: false, pointerType: 'mouse' },
    );
    fireEvent.click(
        await screen.findByRole('option', {
            name: 'Fictional support consent',
        }),
    );
}
beforeEach(() => {
    state.post.mockReset();
    state.canRecord = true;
});

it('requires explicit identity confirmation, shows the purpose, and surfaces server validation', async () => {
    show();
    await openAndChoose();
    expect(screen.getByText(consentType.purpose)).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Record Consent', exact: true }),
    );
    expect(state.post).not.toHaveBeenCalled();
    expect(
        screen.getByText(/confirm that you identified the person/),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I identified Fictional Person/ }),
    );
    state.post.mockImplementation((_url, _payload, options) => {
        options.onError({ given_at: 'This decision date needs review.' });
        options.onFinish();
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Record Consent', exact: true }),
    );
    expect(state.post).toHaveBeenCalledWith(
        '/operations/clients/2/consents',
        expect.objectContaining({
            consent_type_id: '7',
            given_by_relationship: 'self',
        }),
        expect.any(Object),
    );
    expect(
        screen.getByText('This decision date needs review.'),
    ).toBeInTheDocument();
});

it('routes capacity-dependent decisions to a consent request instead of recording a shortcut', async () => {
    show(true);
    await openAndChoose();
    expect(
        screen.getByRole('button', { name: 'Record Consent', exact: true }),
    ).toBeDisabled();
    expect(
        screen.getByRole('link', { name: 'Open consent request (new tab)' }),
    ).toHaveAttribute('href', '/operations/clients/2/consent-requests/create');
    expect(state.post).not.toHaveBeenCalled();
});

it('does not enable recording for a read-only consent viewer', () => {
    state.canRecord = false;
    show();
    for (const button of screen.getAllByRole('button', {
        name: 'Record consent',
        exact: true,
    }))
        expect(button).toBeDisabled();
});

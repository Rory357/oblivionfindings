import ProvisioningTracking from '@/pages/it/provisioning/show';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    MyProvisioningList,
    type MyProvisioningRow,
} from '../my-provisioning-list';

const navigation = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: navigation,
    Link: ({
        children,
        href,
        ...props
    }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    Head: () => null,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

const row: MyProvisioningRow = {
    id: 4,
    reference: 'IT-P000004',
    title: 'Headset request',
    type: 'equipment',
    status: 'pending',
    approval_status: 'pending',
    created_at: '2026-09-12T00:00:00Z',
    updated_at: '2026-09-12T00:00:00Z',
    due_date: null,
    href: '/it/provisioning/4',
};
beforeEach(() => vi.clearAllMocks());

it('opens protected submitted files and includes their field labels in request search', () => {
    render(
        <ProvisioningTracking
            request={{
                ...row,
                viewer_user_id: 1,
                catalogue_version: 2,
                submitted_at: row.created_at,
                answers: [{ label: 'Details', value: 'Requested headset' }],
                events: [],
                attachments: [
                    {
                        id: 7,
                        name: 'proof.txt',
                        size: 128,
                        url: '/it/attachments/7',
                        catalogue_field_label: 'Supporting evidence',
                    },
                ],
            }}
        />,
    );
    const link = screen.getByRole('link', {
        name: 'proof.txt (opens in a new tab)',
    });
    expect(link).toHaveAttribute('href', '/it/attachments/7');
    fireEvent.change(screen.getByPlaceholderText('Search this request…'), {
        target: { value: 'Supporting evidence' },
    });
    expect(link).toBeVisible();
    fireEvent.change(screen.getByPlaceholderText('Search this request…'), {
        target: { value: 'unmatched' },
    });
    expect(
        screen.queryByRole('link', { name: /proof.txt/ }),
    ).not.toBeInTheDocument();
});

it.each(['cards', 'table'] as const)(
    'opens the canonical request from the %s list without work actions',
    (view) => {
        render(
            <MyProvisioningList
                view={view}
                page={{
                    data: [row],
                    total: 1,
                    matched: 1,
                    links: [],
                    last_page: 1,
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Headset request' }),
        ).toHaveAttribute('href', row.href);
        expect(screen.getByText('Waiting for IT')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /fulfil|approve|cancel/i }),
        ).not.toBeInTheDocument();
    },
);

it('searches retained details and public activity and refreshes without changing work', () => {
    render(
        <ProvisioningTracking
            request={{
                ...row,
                viewer_user_id: 1,
                catalogue_version: 2,
                submitted_at: row.created_at,
                answers: [{ label: 'Details', value: 'A headset for calls' }],
                events: [
                    { id: 1, label: 'Request submitted', at: row.created_at },
                ],
            }}
        />,
    );
    expect(screen.getByText('A headset for calls')).toBeVisible();
    const search = screen.getByPlaceholderText('Search this request…');
    fireEvent.change(search, { target: { value: 'nonexistent' } });
    expect(
        screen.getByText('No submitted details match this search.'),
    ).toBeVisible();
    fireEvent.change(search, { target: { value: '' } });
    fireEvent.click(screen.getByRole('tab', { name: 'Activity' }));
    expect(
        within(
            screen.getByRole('region', { name: 'Request activity' }),
        ).getByText('Request submitted'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    expect(navigation.reload).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Refreshing…' })).toBeDisabled();
    expect(navigation.visit).not.toHaveBeenCalled();
});

it.each(['cards', 'table'] as const)(
    'shows progress for the whole original request in the %s list',
    (view) => {
        render(
            <MyProvisioningList
                view={view}
                page={{
                    data: [
                        {
                            ...row,
                            status: 'in_progress',
                            progress: {
                                total: 2,
                                done: 1,
                                failed: 0,
                                cancelled: 0,
                            },
                        },
                    ],
                    total: 1,
                    matched: 1,
                    links: [],
                    last_page: 1,
                }}
            />,
        );
        expect(screen.getByText('1 of 2 tasks completed')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Headset request' }),
        ).toHaveAttribute('href', row.href);
        expect(
            screen.queryByRole('button', { name: /fulfil|approve|cancel/i }),
        ).not.toBeInTheDocument();
    },
);

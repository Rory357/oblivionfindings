import type { ControlledProductPayload } from '@/components/emar/controlled/product-types';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    url: '/emar/controlled?view=register&site_id=8&client_id=42&client_medication_id=17&date=2020-01-01&q=synthetic',
    replace: vi.fn(),
    visit: vi.fn(),
    read: vi.fn(),
    refresh: vi.fn(),
    action: vi.fn(),
    detail: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ url: state.url, props: {} }),
    router: { replace: state.replace, visit: state.visit },
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/emar/controlled/product-client', () => ({
    useControlledProduct: (product: ControlledProductPayload, url: string) => {
        state.read(url);
        return {
            payload: product,
            loading: false,
            error: null,
            offline: false,
            refresh: state.refresh,
            act: state.action,
        };
    },
}));
vi.mock('@/components/emar/controlled/workspace-dialogs', () => ({
    useControlledDialogs: () => ({
        node: null,
        action: state.action,
        detail: state.detail,
    }),
}));

import ControlledRegister from './ControlledRegister';

const product: ControlledProductPayload = {
    medicines: [
        {
            id: 17,
            client_id: 42,
            client_name: 'Ada Synthetic',
            site_id: 8,
            site_name: 'Synthetic house',
            name: 'Synthetic medicine',
            unit: 'tablets',
            balance: 18,
            entry_version: 80,
            nz_class: 'B',
            class_review_required: false,
            can_record: false,
            count: {
                state: 'counted',
                title: 'Counted',
                last_at: '2026-10-02T23:00:00Z',
                last_entry_id: 80,
                due_at: null,
                overdue_at: null,
            },
        },
    ],
    sites: [{ id: 8, name: 'Synthetic house' }],
    people: [
        { id: 42, name: 'Ada Synthetic' },
        { id: 43, name: 'Rewi Synthetic' },
    ],
    witnesses_by_site: {},
    entries: [
        {
            id: 1,
            client_medication_id: 17,
            entry_type: 'receipt',
            quantity: 2,
            on_hand_before: 0,
            on_hand_after: 2,
            recorded_at: '2019-12-31T23:00:00Z',
            recorded_by_name: 'Jo',
            witnessed_by_name: 'Pat',
            notes: null,
        },
    ],
    discrepancies: [],
    losses: [],
    destructions: [],
    requests: [],
    overrides: [],
    current_user_id: 1,
    current_user_name: 'Jo',
    on_site_destruction_allowed: false,
    can: {
        view: true,
        record: false,
        manage: false,
        override: false,
        close_loss: false,
    },
    cadence: { configured: false },
};

beforeEach(() => {
    vi.clearAllMocks();
    state.url =
        '/emar/controlled?view=register&site_id=8&client_id=42&client_medication_id=17&date=2020-01-01&q=synthetic';
});
afterEach(cleanup);

function replacedQuery() {
    return new URL(state.replace.mock.lastCall?.[0].url, 'https://example.test')
        .searchParams;
}

describe('controlled register reader filters', () => {
    it('gives phone record links and all action menu items real frontline targets', () => {
        render(<ControlledRegister product={product} />);
        expect(
            screen.getByRole('button', {
                name: 'Synthetic medicine',
            }),
        ).toHaveClass('frontline-tap');
        const mobileRecord = screen
            .getByRole('button', {
                name: 'Synthetic medicine',
            })
            .closest('li');
        expect(mobileRecord).not.toBeNull();
        const actions = within(mobileRecord!).getByRole('button', {
            name: 'Actions for Synthetic medicine',
        });
        expect(actions).toHaveClass('frontline-tap', 'frontline-focus');
        fireEvent.pointerDown(actions, { button: 0, ctrlKey: false });
        const items = screen.getAllByRole('menuitem');
        expect(items.length).toBeGreaterThan(0);
        items.forEach((item) =>
            expect(item).toHaveClass('frontline-tap', 'frontline-focus'),
        );
    });

    it('clears dependent person and medicine IDs when the house changes', () => {
        render(<ControlledRegister product={product} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clear House' }));
        expect(replacedQuery().has('site_id')).toBe(false);
        expect(replacedQuery().has('client_id')).toBe(false);
        expect(replacedQuery().has('client_medication_id')).toBe(false);
        expect(replacedQuery().get('date')).toBe('2020-01-01');
    });

    it('clears the selected medicine when the person changes and retains house and date', () => {
        render(<ControlledRegister product={product} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clear Person' }));
        expect(replacedQuery().has('client_id')).toBe(false);
        expect(replacedQuery().has('client_medication_id')).toBe(false);
        expect(replacedQuery().get('site_id')).toBe('8');
        expect(replacedQuery().get('date')).toBe('2020-01-01');
    });

    it('shows a labelled NZ history date, current-stock explanation and accessible frontline controls', () => {
        render(<ControlledRegister product={product} />);
        const date = screen.getByLabelText('Register history NZ date');
        expect(date).toHaveValue('2020-01-01');
        expect(date).toHaveClass('frontline-tap', 'frontline-focus');
        expect(
            screen.getByRole('button', { name: 'Ada Synthetic' }),
        ).toHaveClass('frontline-tap');
        expect(
            screen.getByRole('button', { name: 'All entry dates' }),
        ).toHaveClass('frontline-tap');
        expect(
            screen.getByText(
                /Stock, count status and outstanding follow-up are current/,
            ),
        ).toBeInTheDocument();
        expect(state.read).toHaveBeenLastCalledWith(state.url);
    });

    it('preserves person, medicine, house, view and search when the date changes or clears', () => {
        render(<ControlledRegister product={product} />);
        fireEvent.change(screen.getByLabelText('Register history NZ date'), {
            target: { value: '2026-09-27' },
        });
        expect(Object.fromEntries(replacedQuery())).toMatchObject({
            site_id: '8',
            client_id: '42',
            client_medication_id: '17',
            date: '2026-09-27',
            view: 'register',
            q: 'synthetic',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'All entry dates' }),
        );
        expect(replacedQuery().has('date')).toBe(false);
        expect(replacedQuery().get('client_id')).toBe('42');
    });

    it('restores controls and the reader URL on browser back and forward', () => {
        const page = render(<ControlledRegister product={product} />);
        state.url = '/emar/controlled?site_id=8&client_id=43&date=2026-04-05';
        page.rerender(<ControlledRegister product={product} />);
        expect(screen.getByLabelText('Register history NZ date')).toHaveValue(
            '2026-04-05',
        );
        expect(
            screen.getByRole('button', { name: 'Rewi Synthetic' }),
        ).toBeInTheDocument();
        expect(state.read).toHaveBeenLastCalledWith(state.url);
        state.url = '/emar/controlled?client_id=42&date=2020-01-01';
        page.rerender(<ControlledRegister product={product} />);
        expect(screen.getByLabelText('Register history NZ date')).toHaveValue(
            '2020-01-01',
        );
        expect(
            screen.getByRole('button', { name: 'Ada Synthetic' }),
        ).toBeInTheDocument();
        expect(state.read).toHaveBeenLastCalledWith(state.url);
    });
});

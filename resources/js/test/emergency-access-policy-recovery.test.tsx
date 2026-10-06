import { RequestAccessDialog } from '@/pages/emergency/_request-dialog';
import type { EmergencyPolicy } from '@/pages/emergency/_types';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post: inertia.post } }));

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
});

const policy: EmergencyPolicy = {
    default_minutes: 30,
    max_minutes: 60,
    extend_minutes: 15,
    reason_required: true,
    second_person: 'optional',
    review_days: 2,
    repeat_threshold_count: 4,
    repeat_window_days: 7,
};
const props = {
    results: [],
    query: '',
    approvers: [
        {
            id: 9,
            name: 'Synthetic colleague',
            witness_pin: 'set',
            site_ids: [3],
        },
    ],
    prefillClient: {
        id: 7,
        first_name: 'Synthetic',
        last_name: 'person',
        site: { id: 3, name: 'Synthetic house' },
    },
    onCallContacts: {},
    onSearch: vi.fn(),
    onClose: vi.fn(),
};

function next() {
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
}
function enterReasonAndLength() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Covering an absence' }),
    );
    fireEvent.change(screen.getByLabelText(/Explain why/), {
        target: { value: 'Covering an unexpected staff absence' },
    });
    next();
    next();
}
function confirmWithColleague() {
    fireEvent.click(
        screen.getByRole('option', { name: 'Synthetic colleague' }),
    );
    fireEvent.change(screen.getByLabelText(/Synthetic colleague witness PIN/), {
        target: { value: '123456' },
    });
}
function acknowledgeAndStart() {
    for (const checkbox of screen.getAllByRole('checkbox')) {
        if (checkbox.getAttribute('aria-checked') !== 'true')
            fireEvent.click(checkbox);
    }
    fireEvent.click(
        screen.getByRole('button', { name: 'Start emergency access' }),
    );
}
function rejectWithUpdatedRules() {
    const options = inertia.post.mock.calls.at(-1)![2];
    act(() => {
        options.onError({
            authorization_mode:
                'Emergency access rules changed. Check the second person.',
        });
        options.onFinish();
    });
}

it.each(['off', 'optional'] as const)(
    'recovers from %s to required confirmation without losing the request',
    (initialMode) => {
        const { rerender } = render(
            <RequestAccessDialog
                {...props}
                policy={{ ...policy, second_person: initialMode }}
            />,
        );
        enterReasonAndLength();
        next();
        acknowledgeAndStart();
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/clients/7/break-glass',
            expect.objectContaining({ authorization_mode: 'self' }),
            expect.any(Object),
        );

        rejectWithUpdatedRules();
        rerender(
            <RequestAccessDialog
                {...props}
                policy={{ ...policy, second_person: 'required' }}
            />,
        );
        expect(screen.getByPlaceholderText('Find a colleague')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
        confirmWithColleague();
        next();
        expect(
            screen.getByText(
                'Covering an absence — Covering an unexpected staff absence',
            ),
        ).toBeVisible();
        acknowledgeAndStart();
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/clients/7/break-glass',
            expect.objectContaining({
                authorization_mode: 'co_sign',
                co_signed_by: 9,
                co_signer_pin: '123456',
                minutes: 30,
                reason: 'Covering an unexpected staff absence',
            }),
            expect.any(Object),
        );
    },
);

it.each(['optional', 'required'] as const)(
    'recovers from %s confirmation being switched off without sending hidden PIN fields',
    (initialMode) => {
        const { rerender } = render(
            <RequestAccessDialog
                {...props}
                policy={{ ...policy, second_person: initialMode }}
            />,
        );
        enterReasonAndLength();
        if (initialMode === 'optional')
            fireEvent.click(
                screen.getByRole('button', { name: /A colleague confirms/ }),
            );
        confirmWithColleague();
        next();
        acknowledgeAndStart();
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/clients/7/break-glass',
            expect.objectContaining({ authorization_mode: 'co_sign' }),
            expect.any(Object),
        );

        rejectWithUpdatedRules();
        rerender(
            <RequestAccessDialog
                {...props}
                policy={{ ...policy, second_person: 'off' }}
            />,
        );
        expect(
            screen.queryByPlaceholderText('Find a colleague'),
        ).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/witness PIN/)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
        next();
        acknowledgeAndStart();
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/clients/7/break-glass',
            expect.objectContaining({
                authorization_mode: 'self',
                co_signed_by: null,
                co_signer_pin: null,
                reason: 'Covering an unexpected staff absence',
            }),
            expect.any(Object),
        );
    },
);

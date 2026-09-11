import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useSsoLeaveConfirmation } from '../use-sso-leave-confirmation';

type Visit = { method: 'get' | 'post'; url: URL; preserveScroll?: boolean };
type Before = (event: {
    detail: { visit: Visit };
    preventDefault: () => void;
}) => void;
const navigation = vi.hoisted(() => ({
    before: null as Before | null,
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        on: (_name: string, callback: Before) => {
            navigation.before = callback;
            return () => {
                navigation.before = null;
            };
        },
        visit: navigation.visit,
    },
}));
function Harness({
    dirty = true,
    proceed,
}: {
    dirty?: boolean;
    proceed: () => void;
}) {
    const leave = useSsoLeaveConfirmation(dirty);
    return (
        <>
            <input
                aria-label="Unsaved SSO field"
                defaultValue="synthetic entry"
            />
            <Button onClick={() => leave.request(proceed)}>
                Change section
            </Button>
            {leave.confirmation}
        </>
    );
}
beforeEach(() => {
    vi.clearAllMocks();
    navigation.before = null;
});
afterEach(cleanup);
describe('SSO leave review', () => {
    it('keeps entries and current section on cancel, and proceeds only after explicit discard', () => {
        const proceed = vi.fn();
        render(<Harness proceed={proceed} />);
        fireEvent.click(screen.getByRole('button', { name: 'Change section' }));
        expect(proceed).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText('Unsaved SSO field')).toHaveValue(
            'synthetic entry',
        );
        expect(proceed).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Change section' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(proceed).toHaveBeenCalledTimes(1);
    });
    it('drops an obsolete leave request when a pending save becomes clean', () => {
        const proceed = vi.fn();
        const view = render(<Harness proceed={proceed} />);
        fireEvent.click(screen.getByRole('button', { name: 'Change section' }));
        view.rerender(<Harness dirty={false} proceed={proceed} />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        view.rerender(<Harness dirty proceed={proceed} />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(proceed).not.toHaveBeenCalled();
    });
    it('reviews a GET handoff once, preserves its options and never replays POST actions', () => {
        render(<Harness proceed={vi.fn()} />);
        const visit: Visit = {
            method: 'get',
            url: new URL('https://app.example.test/settings/profile'),
            preserveScroll: true,
        };
        const prevented = vi.fn();
        act(() =>
            navigation.before?.({
                detail: { visit },
                preventDefault: prevented,
            }),
        );
        expect(prevented).toHaveBeenCalledTimes(1);
        expect(navigation.visit).not.toHaveBeenCalled();
        const recursivePrevent = vi.fn();
        navigation.visit.mockImplementation(() =>
            navigation.before?.({
                detail: { visit },
                preventDefault: recursivePrevent,
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(navigation.visit).toHaveBeenCalledExactlyOnceWith(
            visit.url,
            visit,
        );
        expect(recursivePrevent).not.toHaveBeenCalled();
        const postPrevent = vi.fn();
        act(() =>
            navigation.before?.({
                detail: { visit: { ...visit, method: 'post' } },
                preventDefault: postPrevent,
            }),
        );
        expect(postPrevent).not.toHaveBeenCalled();
        expect(navigation.visit).toHaveBeenCalledTimes(1);
    });
});

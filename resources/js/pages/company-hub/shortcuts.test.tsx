import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ShortcutDialog } from './_shortcuts';
import type { HubLink } from './_types';

type Submission = {
    onSuccess: () => void;
    onFinish: () => void;
    onError: (errors: Record<string, string>) => void;
};
const { submit, account } = vi.hoisted(() => ({
    submit: vi.fn(),
    account: { id: 11 },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: account } } }),
    router: { on: () => vi.fn() },
    useForm: function useForm(initial: { shortcuts: HubLink[] }) {
        const [data, setData] = useState(initial);
        const [errors, setErrors] = useState<Record<string, string>>({});
        return {
            data,
            errors,
            setData: (key: 'shortcuts', value: HubLink[]) =>
                setData((current) => ({ ...current, [key]: value })),
            put: (url: string, options: Submission) =>
                submit(url, data, { ...options, onError: setErrors }),
        };
    },
}));

const shortcuts: HubLink[] = [
    {
        id: 'day',
        title: 'Daily work',
        url: '/my-day',
        description: 'My own work',
    },
    { id: 'hr', title: 'My HR', url: '/hr/my' },
    { id: 'guide', title: 'Useful guide', url: 'https://example.org/guide' },
];
function order() {
    return within(screen.getByRole('list', { name: 'Your shortcut order' }))
        .queryAllByRole('heading')
        .map((item) => item.textContent);
}
beforeEach(() => {
    submit.mockReset();
    account.id = 11;
});

describe('Personal shortcut panel', () => {
    it('reorders, removes and undoes locally, then submits the exact private order with retained metadata', () => {
        const close = vi.fn();
        render(<ShortcutDialog open close={close} shortcuts={shortcuts} />);
        expect(
            screen.getByRole('button', { name: 'Move Daily work up' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Move Useful guide down' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Move My HR up' }));
        expect(order()).toEqual(['My HR', 'Daily work', 'Useful guide']);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Daily work' }),
        );
        expect(order()).toEqual(['My HR', 'Useful guide']);
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(order()).toEqual(['My HR', 'Daily work', 'Useful guide']);
        expect(submit).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save shortcuts' }));
        expect(submit).toHaveBeenCalledWith(
            '/company-hub/shortcuts',
            { shortcuts: [shortcuts[1], shortcuts[0], shortcuts[2]] },
            expect.any(Object),
        );
        act(() => submit.mock.calls[0][2].onSuccess());
        expect(close).toHaveBeenCalledOnce();
    });

    it('adds a preset destination and keeps an existing link description when editing', () => {
        render(<ShortcutDialog open close={vi.fn()} shortcuts={shortcuts} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add a shortcut' }));
        expect(
            screen.getByRole('button', { name: 'Save shortcuts' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Link label'), {
            target: { value: '  My resources  ' },
        });
        fireEvent.click(screen.getByRole('combobox', { name: 'Destination' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Company resources' }),
        );
        expect(screen.queryByLabelText('Web address')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep link' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit Daily work' }),
        );
        fireEvent.change(screen.getByLabelText('Link label'), {
            target: { value: 'Today' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Keep link' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save shortcuts' }));
        expect(submit.mock.calls[0][1].shortcuts[0]).toEqual({
            ...shortcuts[0],
            title: 'Today',
        });
        expect(submit.mock.calls[0][1].shortcuts[3]).toMatchObject({
            title: 'My resources',
            url: '/company-hub?view=resources',
        });
    });

    it('keeps unsaved link text when closing is cancelled and only discards by choice', () => {
        const close = vi.fn();
        render(<ShortcutDialog open close={close} shortcuts={shortcuts} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit Daily work' }),
        );
        fireEvent.change(screen.getByLabelText('Link label'), {
            target: { value: 'Unfinished label' },
        });
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Discard shortcut changes?',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Link label')).toHaveValue(
            'Unfinished label',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(submit).not.toHaveBeenCalled();
        expect(shortcuts[0].title).toBe('Daily work');
    });

    it.each([
        'javascript:alert(1)',
        '//example.org',
        'https://user:secret@example.org',
        '/back\\slash',
    ])('rejects unsafe destination %s and keeps the entered label', (url) => {
        render(<ShortcutDialog open close={vi.fn()} shortcuts={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add a shortcut' }));
        fireEvent.change(screen.getByLabelText('Link label'), {
            target: { value: 'My website' },
        });
        fireEvent.change(screen.getByLabelText('Web address'), {
            target: { value: url },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Keep link' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Use a secure https:// address',
        );
        expect(screen.getByLabelText('Link label')).toHaveValue('My website');
        expect(submit).not.toHaveBeenCalled();
    });

    it('locks pending changes and ignores an old completion after the account changes', () => {
        const close = vi.fn();
        const view = render(
            <ShortcutDialog open close={close} shortcuts={shortcuts} />,
        );
        const save = screen.getByRole('button', { name: 'Save shortcuts' });
        fireEvent.click(save);
        fireEvent.click(save);
        expect(submit).toHaveBeenCalledOnce();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Edit Daily work' }),
        ).toBeDisabled();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(close).not.toHaveBeenCalled();
        account.id = 12;
        view.rerender(<ShortcutDialog open close={close} shortcuts={[]} />);
        act(() => {
            submit.mock.calls[0][2].onSuccess();
            submit.mock.calls[0][2].onFinish();
        });
        expect(close).not.toHaveBeenCalled();
        expect(screen.queryByText('Daily work')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save shortcuts' }),
        ).toBeEnabled();
    });

    it('retains ordered changes on a failed save and allows retry', () => {
        render(<ShortcutDialog open close={vi.fn()} shortcuts={shortcuts} />);
        fireEvent.click(screen.getByRole('button', { name: 'Move My HR up' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save shortcuts' }));
        act(() => {
            submit.mock.calls[0][2].onError({ shortcuts: 'Please try again.' });
            submit.mock.calls[0][2].onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Please try again.',
        );
        expect(order()).toEqual(['My HR', 'Daily work', 'Useful guide']);
        fireEvent.click(screen.getByRole('button', { name: 'Save shortcuts' }));
        expect(submit).toHaveBeenCalledTimes(2);
        expect(submit.mock.calls[1][1]).toEqual(submit.mock.calls[0][1]);
    });

    it('keeps the canonical maximum of twenty shortcuts', () => {
        render(
            <ShortcutDialog
                open
                close={vi.fn()}
                shortcuts={Array.from({ length: 20 }, (_, i) => ({
                    id: String(i),
                    title: `Shortcut ${i + 1}`,
                    url: '/my-day',
                }))}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Add a shortcut' }),
        ).toBeDisabled();
        expect(screen.getByText('20 of 20 shortcuts')).toBeVisible();
    });
});

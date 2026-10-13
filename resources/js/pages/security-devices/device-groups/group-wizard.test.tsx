import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GroupWizard, type WizardGroup } from './_dialogs';
import DeviceGroupForm from './create';
const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    saved: vi.fn(),
    close: vi.fn(),
    actor: 7,
    before: null as
        | null
        | ((event: {
              preventDefault: () => void;
              detail: { visit: { method: string; url: URL } };
          }) => void),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: mocks.actor },
                    can: { 'securityDevices.groups.manage': true },
                },
            },
        }),
        router: {
            ...actual.router,
            visit: mocks.visit,
            on: (_event: string, callback: typeof mocks.before) => {
                mocks.before = callback;
                return () => {
                    mocks.before = null;
                };
            },
        },
    };
});
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        put: mocks.put,
        isAxiosError: (error: unknown) =>
            Boolean((error as { isAxiosError?: boolean })?.isAxiosError),
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const group: WizardGroup = {
    id: 12,
    name: 'Pump equipment',
    type: 'maintenance',
    description: 'Approved equipment',
    auto_rules: {
        match: 'all',
        conditions: [{ field: 'domain', op: 'equals', value: 'facilities' }],
    },
};
const reply = (value: WizardGroup, actor = 7) => ({
    data: { saved: true, saved_by: actor, group: value },
});
const error = (status: number, errors?: Record<string, string[]>) => ({
    isAxiosError: true,
    response: { status, data: { errors } },
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((res) => {
        resolve = res;
    });
    return { promise, resolve };
}
function details() {
    fireEvent.click(
        screen.getByRole('button', {
            name: /^Group details.*Name and purpose/,
        }),
    );
}
function review() {
    fireEvent.click(
        screen.getByRole('button', { name: /^Review.*Check before saving/ }),
    );
}
function openCreate() {
    return render(
        <GroupWizard open onClose={mocks.close} onSaved={mocks.saved} />,
    );
}
function enterName(value = 'New group') {
    details();
    fireEvent.change(screen.getByLabelText(/Group name/), {
        target: { value },
    });
}
afterEach(cleanup);
beforeEach(() => {
    mocks.post.mockReset();
    mocks.put.mockReset();
    mocks.visit.mockReset();
    mocks.saved.mockReset();
    mocks.close.mockReset();
    mocks.actor = 7;
    mocks.before = null;
});
describe('Shared Device group wizard', () => {
    it('starts with the categorical picker, permits step navigation and sends no write before review validation', () => {
        openCreate();
        expect(
            screen.getByRole('button', { name: /Custom.*another purpose/ }),
        ).toHaveAttribute('aria-pressed', 'true');
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Enter a group name',
        );
        expect(screen.getByLabelText(/Group name/)).toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('creates through the existing JSON owner route and shows success only for a matching confirmed record', async () => {
        mocks.post.mockResolvedValue(
            reply({
                id: 24,
                name: 'New group',
                type: 'custom',
                description: null,
                auto_rules: null,
            }),
        );
        openCreate();
        enterName(' New group ');
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByRole('heading', { name: 'Group created' });
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/device-groups',
            {
                name: 'New group',
                type: 'custom',
                description: '',
                auto_rules: null,
            },
        ]);
        expect(mocks.post.mock.calls[0][2].headers.Accept).toBe(
            'application/json',
        );
        expect(mocks.saved).toHaveBeenCalledWith(
            expect.objectContaining({ id: 24 }),
        );
        expect(
            screen.getByText(/Saving a rule does not change membership/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'View group' }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/device-groups/24',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create another' }));
        details();
        expect(screen.getByLabelText(/Group name/)).toHaveValue('');
    });
    it('reuses the same steps with edit values and normalises the submitted rule without applying membership', async () => {
        const edit = {
            ...group,
            auto_rules: {
                match: 'any' as const,
                conditions: [
                    {
                        field: 'category' as const,
                        op: 'in' as const,
                        value: 'pump, pump, sensor',
                    },
                ],
            },
        };
        const normal = {
            ...edit,
            auto_rules: {
                match: 'any' as const,
                conditions: [
                    {
                        field: 'category' as const,
                        op: 'in' as const,
                        value: ['pump', 'sensor'],
                    },
                ],
            },
        };
        mocks.put.mockResolvedValue(
            reply({
                ...normal,
                auto_rules: {
                    match: 'any',
                    conditions: [
                        {
                            op: 'in',
                            field: 'category',
                            value: ['pump', 'sensor'],
                        },
                    ],
                },
            }),
        );
        render(
            <GroupWizard
                open
                group={edit}
                onClose={mocks.close}
                onSaved={mocks.saved}
            />,
        );
        details();
        expect(screen.getByLabelText(/Group name/)).toHaveValue(group.name);
        review();
        expect(
            screen.getByText('Device type · is one of · pump, sensor'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await screen.findByRole('heading', { name: 'Group updated' });
        expect(mocks.put.mock.calls[0][0]).toBe(
            '/security-devices/device-groups/12',
        );
        expect(mocks.put.mock.calls[0][1].auto_rules).toEqual(
            normal.auto_rules,
        );
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('keeps a draft on cancel and guards Inertia navigation until an explicit discard', () => {
        openCreate();
        enterName('Unsaved private group');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'unsaved group details',
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText(/Group name/)).toHaveValue(
            'Unsaved private group',
        );
        expect(mocks.close).not.toHaveBeenCalled();
        const preventDefault = vi.fn();
        act(() =>
            mocks.before?.({
                preventDefault,
                detail: {
                    visit: {
                        method: 'get',
                        url: new URL(
                            'http://localhost/security-devices/device-groups',
                        ),
                    },
                },
            }),
        );
        expect(preventDefault).toHaveBeenCalled();
        expect(mocks.visit).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(mocks.visit).toHaveBeenCalledTimes(1);
    });
    it('retains validation failures, moves to the field step and permits a corrected save', async () => {
        mocks.post
            .mockRejectedValueOnce(
                error(422, { name: ['This name is already used.'] }),
            )
            .mockResolvedValueOnce(
                reply({
                    id: 24,
                    name: 'New unique group',
                    type: 'custom',
                    description: null,
                    auto_rules: null,
                }),
            );
        openCreate();
        enterName();
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByText('This name is already used.');
        expect(screen.getByLabelText(/Group name/)).toHaveValue('New group');
        fireEvent.change(screen.getByLabelText(/Group name/), {
            target: { value: 'New unique group' },
        });
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByRole('heading', { name: 'Group created' });
        expect(mocks.post).toHaveBeenCalledTimes(2);
    });
    it('blocks automatic replay after an uncertain save and does not turn a response for another identity into success', async () => {
        mocks.put.mockResolvedValue(reply({ ...group, id: 99 }));
        render(
            <GroupWizard
                open
                group={group}
                onClose={mocks.close}
                onSaved={mocks.saved}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await screen.findByText(/The save could not be confirmed/);
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('heading', { name: 'Group updated' }),
        ).not.toBeInTheDocument();
        expect(mocks.saved).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(mocks.put).toHaveBeenCalledTimes(1);
    });
    it('keeps denied saves distinct from uncertain outcomes and preserves entered details', async () => {
        mocks.post.mockRejectedValue(error(403));
        openCreate();
        enterName('Permission denied draft');
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
        await screen.findByText(/current access/);
        expect(
            screen.getByRole('button', { name: 'Create group' }),
        ).toBeDisabled();
        details();
        expect(screen.getByLabelText(/Group name/)).toHaveValue(
            'Permission denied draft',
        );
        expect(mocks.saved).not.toHaveBeenCalled();
    });
    it('disables repeated saves and ignores a late acknowledgement after the actor and record are replaced', async () => {
        const pending = deferred<ReturnType<typeof reply>>();
        mocks.put.mockReturnValue(pending.promise);
        const view = render(
            <GroupWizard
                open
                group={group}
                onClose={mocks.close}
                onSaved={mocks.saved}
            />,
        );
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        const signal = mocks.put.mock.calls[0][2].signal as AbortSignal;
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        mocks.actor = 8;
        const next = { ...group, id: 13, name: 'Replacement group' };
        view.rerender(
            <GroupWizard
                open
                group={next}
                onClose={mocks.close}
                onSaved={mocks.saved}
            />,
        );
        pending.resolve(reply(group));
        expect(signal.aborted).toBe(true);
        details();
        expect(screen.getByLabelText(/Group name/)).toHaveValue(
            'Replacement group',
        );
        await waitFor(() => expect(mocks.saved).not.toHaveBeenCalled());
    });
    it('aborts a read-only rule preview when its value changes and discards the late result', async () => {
        const pending = deferred<{
            data: { count: number; sample: never[] };
        }>();
        mocks.post.mockReturnValue(pending.promise);
        openCreate();
        fireEvent.click(
            screen.getByRole('button', {
                name: /^Automatic membership.*Optional saved rule/,
            }),
        );
        fireEvent.click(
            screen.getByRole('switch', { name: 'Automatic membership' }),
        );
        fireEvent.change(screen.getByLabelText('Value'), {
            target: { value: 'security' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview matches' }),
        );
        expect(mocks.post.mock.calls[0][0]).toBe(
            '/security-devices/device-groups/auto-rules/preview',
        );
        const signal = mocks.post.mock.calls[0][2].signal as AbortSignal;
        fireEvent.change(screen.getByLabelText('Value'), {
            target: { value: 'facilities' },
        });
        expect(signal.aborted).toBe(true);
        pending.resolve({ data: { count: 99, sample: [] } });
        await waitFor(() =>
            expect(
                screen.queryByText('99 devices match'),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: 'Preview matches' }),
        ).toBeEnabled();
    });
    it('keeps direct create/edit entry routes as adapters to the same shared wizard', () => {
        const view = render(<DeviceGroupForm />);
        expect(screen.getByRole('dialog')).toHaveTextContent('Create group');
        view.rerender(<DeviceGroupForm group={group} isEdit />);
        details();
        expect(screen.getByLabelText(/Group name/)).toHaveValue(group.name);
        expect(screen.getByRole('dialog')).toHaveTextContent('Edit group');
    });
});

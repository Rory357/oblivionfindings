import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import {
    createContext,
    useContext,
    type PropsWithChildren,
    type ReactNode,
} from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SsoGroupMappings, type SsoMapping } from '../sso-group-mappings';
vi.mock('axios', () => ({
    default: {
        put: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'isAxiosError' in value,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { on: vi.fn(() => () => {}) } }));
vi.mock('@/components/wizard/shell', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/wizard/shell')>()),
    WizardShell: ({
        open,
        title,
        children,
        footerStart,
        footerEnd,
        onClose,
        success,
    }: {
        open: boolean;
        title: string;
        children: ReactNode;
        footerStart: ReactNode;
        footerEnd: ReactNode;
        onClose: () => void;
        success?: ReactNode;
    }) =>
        open ? (
            <section role="dialog" aria-label={title}>
                {success || (
                    <>
                        {children}
                        {footerStart}
                        {footerEnd}
                    </>
                )}
                <button onClick={onClose}>Close dialog</button>
            </section>
        ) : null,
}));
vi.mock('@/components/ui/select', () => {
    const SelectState = createContext({
        value: '',
        onValueChange: (_value: string) => {},
        disabled: false,
    });
    return {
        Select: ({
            value,
            onValueChange,
            disabled = false,
            children,
        }: PropsWithChildren<{
            value: string;
            onValueChange: (value: string) => void;
            disabled?: boolean;
        }>) => (
            <SelectState.Provider value={{ value, onValueChange, disabled }}>
                {children}
            </SelectState.Provider>
        ),
        SelectTrigger: ({ id }: { id?: string }) => {
            const context = useContext(SelectState);
            return (
                <input
                    id={id}
                    role="combobox"
                    value={context.value}
                    disabled={context.disabled}
                    onChange={(event) =>
                        context.onValueChange(event.target.value)
                    }
                />
            );
        },
        SelectValue: () => null,
        SelectContent: () => null,
        SelectItem: () => null,
    };
});
const rule: SsoMapping = {
    id: 41,
    version: 'a'.repeat(64),
    provider: 'microsoft',
    external_group_id: 'synthetic-group',
    external_group_name: 'Synthetic care group',
    role_id: 2,
    auto_assign: false,
    auto_remove: false,
    last_synced_at: null,
};
const roles = [
    { id: 2, name: 'support_worker', label: 'Support worker' },
    { id: 3, name: 'admin', label: 'Administrator' },
];
function error(status: number) {
    return { isAxiosError: true, response: { status } };
}
beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
function setup() {
    const onChange = vi.fn();
    render(
        <SsoGroupMappings
            mappings={[rule]}
            roles={roles}
            onChange={onChange}
        />,
    );
    return onChange;
}
function edit() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Edit Synthetic care group' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
describe('SSO group mapping lifecycle', () => {
    it('reviews discard without a native prompt and retains entries when cancelled', () => {
        setup();
        edit();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Application role' }),
            { target: { value: '3' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }));
        const review = screen.getByRole('alertdialog');
        expect(window.confirm).not.toHaveBeenCalled();
        fireEvent.click(within(review).getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('combobox', { name: 'Application role' }),
        ).toHaveValue('3');
        expect(axios.put).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('shows reviewed values and an acknowledged saved result with Done', async () => {
        setup();
        edit();
        expect(screen.getByText('Mapping to save')).toBeInTheDocument();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                status: 'saved',
                mapping: { ...rule, version: 'd'.repeat(64) },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        await screen.findByRole('heading', { name: 'Group mapping saved' });
        expect(
            screen.getByRole('button', { name: 'Add another mapping' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(axios.put).toHaveBeenCalledTimes(1);
    });
    it('cancels removal without a request and reconciles unknown removal without repeating it', async () => {
        setup();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Synthetic care group' }),
        );
        fireEvent.click(
            within(
                screen.getByRole('dialog', { name: 'Remove group mapping?' }),
            ).getByRole('button', { name: 'Cancel' }),
        );
        expect(axios.delete).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Synthetic care group' }),
        );
        vi.mocked(axios.delete).mockRejectedValue(
            new Error('lost acknowledgement'),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove mapping' }));
        await screen.findByText(/command outcome is unconfirmed/);
        expect(
            screen.getByRole('button', { name: 'Remove mapping' }),
        ).toBeDisabled();
        expect(axios.delete).toHaveBeenCalledWith(
            '/settings/sso-groups/41',
            expect.objectContaining({
                data: { expected_version: rule.version },
            }),
        );
        vi.mocked(axios.get).mockResolvedValue({ data: { mappings: [] } });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Reload saved rules and discard form entries',
            }),
        );
        await screen.findByText(/Current saved rules loaded/);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Synthetic care group'),
        ).not.toBeInTheDocument();
        expect(axios.delete).toHaveBeenCalledTimes(1);
    });
    it('does not fetch providers automatically or claim directory synchronization is active', () => {
        setup();
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                /Automatic directory synchronization is not enabled/,
            ),
        ).toBeInTheDocument();
    });
    it('requires deliberate role-grant review and sends the exact current mapping version', async () => {
        const onChange = setup();
        edit();
        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Assign role when membership is verified',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Save mapping' }),
        ).toBeDisabled();
        fireEvent.change(
            screen.getByLabelText('Reason for granting this role'),
            { target: { value: 'Synthetic authorized mapping review.' } },
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'I reviewed this role and the group allowed to receive it.',
            }),
        );
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                status: 'saved',
                mapping: {
                    ...rule,
                    version: 'b'.repeat(64),
                    auto_assign: true,
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        await screen.findByText(
            'Group mapping saved. Existing user roles were not changed.',
        );
        expect(axios.put).toHaveBeenCalledWith(
            '/settings/sso-groups/41',
            expect.objectContaining({
                expected_version: rule.version,
                confirm_role_assignment: true,
                assignment_reason: 'Synthetic authorized mapping review.',
            }),
            expect.anything(),
        );
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ id: 41, auto_assign: true }),
        ]);
    });
    it('a lost response freezes edits until explicit reload without repeating the command', async () => {
        setup();
        edit();
        vi.mocked(axios.put).mockResolvedValue({ data: { success: true } });
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        await screen.findByText(/command outcome is unconfirmed/);
        expect(
            screen.getByRole('button', { name: 'Save mapping' }),
        ).toBeDisabled();
        expect(
            within(screen.getByRole('dialog')).getByRole('alert'),
        ).toHaveFocus();
        vi.mocked(axios.get).mockResolvedValue({
            data: { mappings: [{ ...rule, version: 'c'.repeat(64) }] },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Reload saved rules and discard form entries',
            }),
        );
        await screen.findByText(/Current saved rules loaded/);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(axios.put).toHaveBeenCalledTimes(1);
    });
    it('revoked access conceals rows, modal payload and any directory evidence', async () => {
        setup();
        edit();
        vi.mocked(axios.put).mockRejectedValue(error(403));
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        await screen.findByText(/current access does not allow group mapping/);
        expect(
            screen.queryByText('Synthetic care group'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('Cancel wait remains inside the dialog and never claims that the server rolled back', async () => {
        setup();
        edit();
        vi.mocked(axios.put).mockImplementation(
            (_url, _body, options) =>
                new Promise((_resolve, reject) =>
                    options?.signal?.addEventListener?.('abort', () =>
                        reject(new Error('aborted')),
                    ),
                ),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Cancel wait' }),
        );
        await screen.findByText(/Cancelling the wait does not undo a save/);
        expect(
            screen.queryByText(
                'Group mapping saved. Existing user roles were not changed.',
            ),
        ).not.toBeInTheDocument();
    });
    it('unmount aborts the local wait and ignores late mutation acknowledgements', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.put).mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const onChange = vi.fn();
        const { unmount } = render(
            <SsoGroupMappings
                mappings={[rule]}
                roles={roles}
                onChange={onChange}
            />,
        );
        edit();
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        const signal = vi.mocked(axios.put).mock.calls[0][2]?.signal;
        unmount();
        expect(signal?.aborted).toBe(true);
        await act(async () =>
            resolve({ data: { status: 'saved', mapping: rule } }),
        );
        expect(onChange).not.toHaveBeenCalled();
    });
    it('only an authoritative empty directory response is shown as zero groups', async () => {
        setup();
        vi.mocked(axios.post)
            .mockResolvedValueOnce({ data: { groups: [] } })
            .mockResolvedValueOnce({ data: { status: 'fetched', groups: [] } });
        fireEvent.click(
            screen.getByRole('button', { name: 'Fetch Microsoft groups' }),
        );
        await screen.findByText(/directory request did not complete/);
        expect(
            screen.queryByText('No groups were returned by the directory.'),
        ).not.toBeInTheDocument();
        vi.mocked(axios.get).mockResolvedValue({ data: { mappings: [rule] } });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Reload saved rules and discard form entries',
            }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Fetch Microsoft groups' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Fetch Microsoft groups' }),
        );
        await screen.findByText('No groups were returned by the directory.');
    });
});

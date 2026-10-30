import { ChatError, chatRequest } from '@/components/chat/api';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ChatSettingsPage from './chat';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
const account = vi.hoisted(() => ({ id: 1 }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: account } } }),
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({ confirmation: null }),
}));
vi.mock('@/components/chat/api', async (original) => ({
    ...(await original<typeof import('@/components/chat/api')>()),
    chatRequest: vi.fn(),
}));

beforeEach(() => {
    account.id = 1;
    vi.mocked(chatRequest).mockReset();
});

it('requires review of the latest settings after a conflict and preserves unrelated administrator changes', async () => {
    const settings = {
        version: 1,
        enabled: true,
        family_enabled: true,
        files_enabled: true,
        max_file_mb: 20,
        max_files: 5,
        message_change_window_minutes: null,
        calls_enabled: false,
        family_calls_enabled: false,
        max_call_participants: 8,
        response_expectation: '',
        response_schedule: null,
        site_overrides: {},
    };
    const latest = {
        ...settings,
        version: 2,
        enabled: false,
        site_overrides: {
            '9': { response_expectation: 'Latest site wording' },
        },
    };
    let puts = 0;
    let submitted: Record<string, unknown> | null = null;
    vi.mocked(chatRequest).mockImplementation(async (path, options) => {
        if (path === '/settings/chat/configuration')
            return { settings: latest };
        if (path === '/settings/chat' && options?.method === 'PUT') {
            if (++puts === 1)
                throw new ChatError(
                    'Another administrator updated Chat settings.',
                    409,
                );
            submitted = JSON.parse(String(options.body));
            return { settings: { ...submitted, version: 3 } };
        }
        throw new Error(`Unexpected request ${path}`);
    });
    render(
        <ChatSettingsPage
            chatSettings={settings}
            chatSites={[{ id: 9, name: 'Tōtara service' }]}
            callingReady={false}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Family contact' }));
    fireEvent.keyDown(screen.getByLabelText('Response expectation'), {
        key: 'Enter',
    });
    fireEvent.click(
        screen.getByRole('option', { name: 'Working-hours target' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Save reviewed changes',
        }),
    );
    await screen.findByRole('button', {
        name: 'Load latest and review my changes',
    });
    expect(
        within(dialog).getByRole('button', {
            name: 'Save reviewed changes',
        }),
    ).toBeDisabled();
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Load latest and review my changes',
        }),
    );
    await screen.findByText(/Latest settings loaded/);
    expect(
        within(dialog).getByText(/After: 240 working minutes/),
    ).toBeVisible();
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Save reviewed changes',
        }),
    );
    await waitFor(() =>
        expect(screen.getByText('Chat settings saved.')).toBeVisible(),
    );
    expect(submitted).toMatchObject({
        version: 2,
        enabled: false,
        response_schedule: { response_minutes: 240 },
        site_overrides: latest.site_overrides,
    });
});

const scopedSettings = () => ({
    version: 1,
    enabled: true,
    family_enabled: true,
    files_enabled: true,
    max_file_mb: 20,
    max_files: 5,
    message_change_window_minutes: null,
    calls_enabled: false,
    family_calls_enabled: false,
    max_call_participants: 8,
    response_expectation: '',
    response_schedule: null,
    site_overrides: {
        '8': { max_file_mb: 4, response_expectation: 'Kōwhai wording' },
        '9': { max_files: 2 },
    },
});
function chooseScope(name: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Policy scope' }), {
        key: 'Enter',
    });
    fireEvent.click(screen.getByRole('option', { name }));
}

const sites = [
    { id: 8, name: 'Kōwhai House' },
    { id: 9, name: 'Tōtara service' },
];

it.each(['success', 'failure'])(
    'resets reviewed edits on an account switch and ignores the old save %s',
    async (outcome) => {
        let finish!: (value: unknown) => void;
        let reject!: (reason: Error) => void;
        vi.mocked(chatRequest).mockImplementationOnce(
            () =>
                new Promise((resolve, fail) => {
                    finish = resolve;
                    reject = fail;
                }),
        );
        const props = {
            chatSettings: scopedSettings(),
            chatSites: sites,
            callingReady: false,
        };
        const view = render(<ChatSettingsPage {...props} />);
        fireEvent.click(screen.getByRole('switch', { name: 'Enable Chat' }));
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save reviewed changes' }),
        );
        await waitFor(() => expect(finish).toBeDefined());
        const options = vi.mocked(chatRequest).mock.calls[0][1]!;
        expect(new Headers(options.headers).get('X-Chat-Actor')).toBe('1');
        account.id = 2;
        view.rerender(<ChatSettingsPage {...props} />);
        expect(options.signal?.aborted).toBe(true);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('switch', { name: 'Enable Chat' }),
        ).toBeChecked();
        await act(async () => {
            if (outcome === 'success')
                finish({
                    settings: {
                        ...scopedSettings(),
                        enabled: false,
                        version: 2,
                    },
                });
            else reject(new Error('Old account failed'));
        });
        expect(
            screen.queryByText('Chat settings saved.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Old account failed'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('switch', { name: 'Enable Chat' }),
        ).toBeChecked();
    },
);

it.each([401, 403, 419])(
    'hides settings and disables further saves after access expires (%s)',
    async (status) => {
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Refresh to check your current access.', status),
        );
        render(
            <ChatSettingsPage
                chatSettings={scopedSettings()}
                chatSites={sites}
                callingReady={false}
            />,
        );
        fireEvent.click(screen.getByRole('switch', { name: 'Enable Chat' }));
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save reviewed changes' }),
        );
        const heading = await screen.findByRole('heading', {
            name: 'Chat settings unavailable',
        });
        expect(heading).toHaveFocus();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('Kōwhai wording')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Reload Chat settings' }),
        ).toHaveAttribute('href', '/settings/chat');
        expect(chatRequest).toHaveBeenCalledTimes(1);
    },
);

it('protects unsaved changes when navigating sections or changing policy scope', () => {
    render(
        <ChatSettingsPage
            chatSettings={scopedSettings()}
            chatSites={sites}
            callingReady={false}
        />,
    );
    fireEvent.click(screen.getByRole('switch', { name: 'Enable Chat' }));
    fireEvent.click(screen.getByRole('button', { name: 'Messages and files' }));
    const guard = screen.getByRole('dialog', {
        name: 'Keep your settings changes?',
    });
    expect(
        screen.getByRole('heading', { level: 1, hidden: true }),
    ).toHaveTextContent('General');
    fireEvent.click(within(guard).getByRole('button', { name: 'Stay here' }));
    expect(
        screen.getByRole('switch', { name: 'Enable Chat' }),
    ).not.toBeChecked();
    chooseScope('Kōwhai House');
    expect(
        screen.getByRole('combobox', { name: 'Policy scope', hidden: true }),
    ).toHaveTextContent('Organisation defaults');
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard and continue' }),
    );
    expect(
        screen.getByRole('combobox', { name: 'Policy scope' }),
    ).toHaveTextContent('Kōwhai House');
    expect(screen.getByRole('switch', { name: 'Enable Chat' })).toBeChecked();
    expect(screen.getByText('No unsaved changes')).toBeInTheDocument();
});

it('reviews the effective site file override and resets only the selected section', async () => {
    let sent: ReturnType<typeof scopedSettings> | undefined;
    vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
        sent = JSON.parse(String(options?.body));
        return { settings: { ...sent, version: 2 } };
    });
    render(
        <ChatSettingsPage
            chatSettings={scopedSettings()}
            chatSites={sites}
            callingReady={false}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Messages and files' }));
    chooseScope('Kōwhai House');
    expect(
        screen.getByRole('spinbutton', { name: /Maximum file size/ }),
    ).toHaveValue(4);
    fireEvent.click(screen.getByRole('button', { name: 'Reset to inherited' }));
    const review = screen.getByRole('dialog', { name: 'Review Chat settings' });
    expect(within(review).getByText('Before: Override: 4')).toBeInTheDocument();
    expect(
        within(review).getByText('After: Inherited: 20'),
    ).toBeInTheDocument();
    expect(vi.mocked(chatRequest)).not.toHaveBeenCalled();
    fireEvent.click(
        within(review).getByRole('button', { name: 'Save reviewed changes' }),
    );
    await screen.findByText('Chat settings saved.');
    expect(sent?.site_overrides).toEqual({
        '8': { response_expectation: 'Kōwhai wording' },
        '9': { max_files: 2 },
    });
    expect(
        screen.getByRole('spinbutton', { name: /Maximum file size/ }),
    ).toHaveValue(20);
});

it('retains site edits after a failed save and disables inputs while saving', async () => {
    let reject!: (reason: Error) => void;
    vi.mocked(chatRequest).mockImplementation(
        () =>
            new Promise((_resolve, failure) => {
                reject = failure;
            }),
    );
    render(
        <ChatSettingsPage
            chatSettings={scopedSettings()}
            chatSites={sites}
            callingReady={false}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Messages and files' }));
    chooseScope('Tōtara service');
    fireEvent.change(
        screen.getByRole('spinbutton', { name: /Files per message/ }),
        { target: { value: '3' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Save reviewed changes' }),
    );
    expect(
        screen.getByRole('spinbutton', {
            name: /Files per message/,
            hidden: true,
        }),
    ).toBeDisabled();
    reject(new Error('Save failed'));
    await screen.findByText('Save failed');
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(
        screen.getByRole('spinbutton', { name: /Files per message/ }),
    ).toHaveValue(3);
    expect(
        screen.getByRole('combobox', { name: 'Policy scope' }),
    ).toHaveTextContent('Tōtara service');
});

import { ChatError, chatRequest } from '@/components/chat/api';
import ChatSettingsPage from '@/pages/settings/chat';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    ChatCallingSettings,
    type ChatCallingSettingsData,
} from './chat-calling-settings';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({ confirmation: null }),
}));
vi.mock('@/components/chat/api', async (original) => ({
    ...(await original<typeof import('@/components/chat/api')>()),
    chatRequest: vi.fn(),
}));

const plain = (server: string | string[] | number | null = null) => ({
    on_page: false,
    value: null,
    server,
});
const key = (on_server = false) => ({ on_page: false, on_server });

function calling(): ChatCallingSettingsData {
    return {
        version: 0,
        updated_at: null,
        updated_by: null,
        fields: {
            relay_urls: plain(),
            relay_secret: key(),
            relay_credential_seconds: plain(3600),
            relay_health_url: plain(),
            relay_control_urls: plain(),
            relay_control_secret: key(),
            relay_control_key_id: plain(),
            calling_url: plain('wss://calls.server.test'),
            calling_api_url: plain('https://calls.server.test'),
            calling_api_key: plain('server-key'),
            calling_api_secret: key(true),
        },
        status: {
            relay: false,
            control: false,
            calling: true,
            calling_verified: false,
        },
    };
}

beforeEach(() => {
    vi.mocked(chatRequest).mockReset();
});

it('saves a generated relay key and new addresses only after the password, and never shows a saved key', async () => {
    let submitted: Record<string, unknown> | null = null;
    vi.mocked(chatRequest).mockImplementation(async (path, options) => {
        if (path !== '/settings/chat/calling')
            throw new Error(`Unexpected request ${path}`);
        if (options?.method !== 'PUT') return calling();
        submitted = JSON.parse(String(options.body));
        const saved = calling();
        saved.version = 1;
        saved.fields.relay_urls = {
            on_page: true,
            value: ['turn:relay.example.test:3479?transport=udp'],
            server: null,
        };
        saved.fields.relay_secret = { on_page: true, on_server: false };
        return saved;
    });
    render(<ChatCallingSettings actorId={1} />);

    await screen.findByRole('heading', { name: 'Chat calling settings' });
    expect(
        screen.getByText('Uses the server’s setting: wss://calls.server.test'),
    ).toBeVisible();
    expect(screen.getByText('Not marked as checked')).toBeVisible();

    fireEvent.change(screen.getByLabelText('Relay addresses'), {
        target: { value: 'turn:relay.example.test:3479?transport=udp\n' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Set relay key' }));
    fireEvent.click(screen.getByRole('button', { name: 'Generate key' }));
    const generated = (screen.getByLabelText('Relay key') as HTMLInputElement)
        .value;
    expect(generated).toMatch(/^[A-Za-z0-9+/]{64}$/);
    expect(screen.getByText(/Copy this key to the server now/)).toBeVisible();
    expect(screen.getByText('2 unsaved changes')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('After: A new key')).toBeVisible();
    expect(within(dialog).queryByText(generated)).toBeNull();
    const save = within(dialog).getByRole('button', {
        name: 'Save reviewed changes',
    });
    expect(save).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText('Your password'), {
        target: { value: 'my password' },
    });
    fireEvent.click(save);

    await waitFor(() =>
        expect(screen.getByText('Calling settings saved.')).toBeVisible(),
    );
    expect(submitted).toEqual({
        version: 0,
        password: 'my password',
        values: { relay_urls: ['turn:relay.example.test:3479?transport=udp'] },
        secrets: { relay_secret: generated },
        clear_secrets: [],
    });
    expect(screen.queryByDisplayValue(generated)).toBeNull();
    expect(screen.getAllByText('Saved on this page')).toHaveLength(1);
    expect(screen.getByText('No unsaved changes')).toBeVisible();
});

it('offers a reload when someone else saved first', async () => {
    vi.mocked(chatRequest).mockImplementation(async (_path, options) => {
        if (options?.method === 'PUT')
            throw new ChatError(
                'Someone else saved the calling settings while you were editing. Reload them and make your change again.',
                409,
                'chat_calling_conflict',
            );
        return calling();
    });
    render(<ChatCallingSettings actorId={1} />);
    fireEvent.change(await screen.findByLabelText('Control key name'), {
        target: { value: 'k2026a' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Your password'), {
        target: { value: 'my password' },
    });
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Save reviewed changes' }),
    );
    await within(dialog).findByText(/Someone else saved/);
    expect(
        within(dialog).getByRole('button', { name: 'Save reviewed changes' }),
    ).toBeDisabled();
    fireEvent.click(
        within(dialog).getByRole('button', {
            name: 'Reload calling settings',
        }),
    );
    await waitFor(() =>
        expect(screen.getByText('No unsaved changes')).toBeVisible(),
    );
});

const policy = {
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

it('shows Chat calling settings under Settings audit for administrators only', async () => {
    vi.mocked(chatRequest).mockResolvedValue(calling());
    const { rerender } = render(
        <ChatSettingsPage
            chatSettings={policy}
            chatSites={[]}
            callingReady={false}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Chat calling settings' }),
    ).toBeNull();

    rerender(
        <ChatSettingsPage
            chatSettings={policy}
            chatSites={[]}
            callingReady={false}
            chatCallingManager
        />,
    );
    const nav = screen.getByRole('navigation', {
        name: 'Chat settings sections',
    });
    const labels = within(nav)
        .getAllByRole('button')
        .map((button) => button.textContent);
    expect(labels.slice(-2)).toEqual([
        'Settings audit',
        'Chat calling settings',
    ]);
    fireEvent.click(
        within(nav).getByRole('button', { name: 'Chat calling settings' }),
    );
    await screen.findByRole('heading', { name: 'Chat calling settings' });
    expect(screen.queryByLabelText('Policy scope')).toBeNull();
    expect(
        within(nav).getByRole('button', { name: 'Chat calling settings' }),
    ).toHaveAttribute('aria-current', 'page');

    // A calling draft asks before leaving, like the policy draft.
    fireEvent.change(screen.getByLabelText('Control key name'), {
        target: { value: 'k2026a' },
    });
    fireEvent.click(within(nav).getByRole('button', { name: 'General' }));
    const ask = screen.getByRole('dialog', {
        name: 'Keep your settings changes?',
    });
    fireEvent.click(
        within(ask).getByRole('button', { name: 'Discard and continue' }),
    );
    await screen.findByRole('heading', { name: 'General' });
});

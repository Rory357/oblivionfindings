import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import NotificationDefaults from './notification-defaults';
import NotificationPreferences from './notifications';

const capture = vi.hoisted(() => ({
    put: vi.fn(),
    errors: {} as Record<string, string>,
    upgrade: false,
    subscribe: vi.fn(),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/hooks/use-web-push', () => ({
    useWebPush: () => ({
        supported: capture.upgrade,
        configured: capture.upgrade,
        enabled: capture.upgrade,
        needsUpgrade: capture.upgrade,
        permission: 'granted',
        processing: false,
        subscribe: capture.subscribe,
    }),
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        Head: () => null,
        router: { put: vi.fn() },
        useForm: (initial: Record<string, unknown>) => {
            const [data, set] = useState(initial);
            return {
                data,
                setData: (key: string, value: unknown) =>
                    set((prior) => ({ ...prior, [key]: value })),
                processing: false,
                errors: capture.errors,
                put: (url: string) => capture.put(url, data),
            };
        },
    };
});

const enabled = { enabled: true, inapp: true, email: false, push: false };
const props = {
    groups: {
        Chat: ['chat.message.received', 'chat.mentioned', 'chat.family.reply'],
    },
    delivery: {
        dnd_enabled: false,
        dnd_until: null,
        desktop_notifications_enabled: false,
        notification_sounds_enabled: true,
        email_digest_frequency: 'instant' as const,
    },
    userPrefs: {},
    roleDefaults: { 'chat.mentioned': { ...enabled, inapp: false } },
    canManageRoleDefaults: false,
};

beforeEach(() => {
    capture.put.mockReset();
    capture.errors = {};
    capture.upgrade = false;
    capture.subscribe.mockReset();
});

it('lets an existing device explicitly upgrade Chat notification support without switching alerts off', () => {
    capture.upgrade = true;
    render(<NotificationPreferences {...props} />);
    expect(
        screen.getByRole('switch', {
            name: 'Push notifications for this device',
        }),
    ).toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Update this device' }));
    expect(capture.subscribe).toHaveBeenCalledOnce();
});

it('saves unchanged Chat rows as inherited and changes only the explicitly selected event', () => {
    render(<NotificationPreferences {...props} />);
    expect(screen.getAllByText('Following role defaults')).toHaveLength(1);
    expect(screen.getAllByText('Following organisation defaults')).toHaveLength(
        2,
    );
    expect(
        screen.getByRole('checkbox', { name: 'Mentions in Chat: in-app' }),
    ).not.toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put).toHaveBeenLastCalledWith('/settings/notifications', {
        prefs: {
            'chat.message.received': null,
            'chat.mentioned': null,
            'chat.family.reply': null,
        },
    });
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Mentions in Chat: in-app' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put.mock.lastCall?.[1].prefs).toEqual({
        'chat.message.received': null,
        'chat.mentioned': enabled,
        'chat.family.reply': null,
    });
});

it('restores current role defaults explicitly without turning on unavailable channels', () => {
    render(
        <NotificationPreferences
            {...props}
            userPrefs={{ 'chat.mentioned': enabled }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Use role defaults' }));
    expect(
        screen.getByRole('checkbox', { name: 'Mentions in Chat: in-app' }),
    ).not.toBeChecked();
    for (const name of ['Mentions in Chat: email', 'Mentions in Chat: push']) {
        expect(screen.getByRole('checkbox', { name })).toBeDisabled();
        expect(screen.getByRole('checkbox', { name })).not.toBeChecked();
    }
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put.mock.lastCall?.[1].prefs['chat.mentioned']).toBeNull();
});

it('keeps an edited preference visible after a rejected save', () => {
    capture.errors = { 'prefs.chat.mentioned': 'Please try again.' };
    render(<NotificationPreferences {...props} />);
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Mentions in Chat: in-app' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Preferences were not saved. Please try again.',
    );
    expect(
        screen.getByRole('checkbox', { name: 'Mentions in Chat: in-app' }),
    ).toBeChecked();
});

it('bulk enabling personal notices does not enable unavailable Chat transports', () => {
    render(<NotificationPreferences {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Enable All' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put.mock.lastCall?.[1].prefs).toEqual({
        'chat.message.received': enabled,
        'chat.mentioned': enabled,
        'chat.family.reply': enabled,
    });
});

it('uses the same controls in role defaults and keeps bulk enable limited to available Chat channels', () => {
    render(
        <NotificationDefaults
            groups={props.groups}
            roles={[{ id: 7, name: 'worker', label: 'Support worker' }]}
            matrix={{
                7: {
                    'chat.message.received': enabled,
                    'chat.mentioned': enabled,
                    'chat.family.reply': enabled,
                },
            }}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Support worker: Mentions in Chat',
        }),
    );
    const panel = screen.getByRole('dialog');
    expect(
        within(panel).getByRole('checkbox', { name: 'Mentions in Chat: push' }),
    ).toBeDisabled();
    fireEvent.click(
        within(panel).getByRole('checkbox', {
            name: 'Mentions in Chat: receive notices',
        }),
    );
    fireEvent.keyDown(panel, { key: 'Escape' });
    fireEvent.click(screen.getByRole('button', { name: 'Save Defaults' }));
    expect(
        capture.put.mock.lastCall?.[1].matrix[7]['chat.mentioned'].enabled,
    ).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'All' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Defaults' }));
    expect(capture.put.mock.lastCall?.[1].matrix[7]).toEqual({
        'chat.message.received': enabled,
        'chat.mentioned': enabled,
        'chat.family.reply': enabled,
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enable All' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save Defaults' }));
    expect(capture.put.mock.lastCall?.[1].matrix[7]).toEqual({
        'chat.message.received': enabled,
        'chat.mentioned': enabled,
        'chat.family.reply': enabled,
    });
});

it('offers missed-call notices with personal inheritance and accurate delivery wording', () => {
    render(
        <NotificationPreferences
            {...props}
            groups={{ Chat: ['chat.call.missed'] }}
            roleDefaults={{}}
        />,
    );
    expect(
        screen.getByRole('checkbox', { name: 'Missed Chat calls: in-app' }),
    ).toBeChecked();
    expect(
        screen.getByRole('checkbox', { name: 'Missed Chat calls: push' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('checkbox', { name: 'Missed Chat calls: email' }),
    ).toBeDisabled();
    expect(
        screen.getByText(/your invitation and current history access/),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Missed Chat calls: in-app' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put.mock.lastCall?.[1].prefs['chat.call.missed']).toEqual({
        ...enabled,
        inapp: false,
    });
});

it('offers family assignment notices with inherited in-app defaults and unavailable transports disabled', () => {
    render(
        <NotificationPreferences
            {...props}
            groups={{ Chat: ['chat.family.assigned'] }}
            roleDefaults={{}}
        />,
    );
    expect(
        screen.getByRole('checkbox', {
            name: 'Family work assigned to you: in-app',
        }),
    ).toBeChecked();
    expect(
        screen.getByRole('checkbox', {
            name: 'Family work assigned to you: email',
        }),
    ).toBeDisabled();
    expect(
        screen.getByRole('checkbox', {
            name: 'Family work assigned to you: push',
        }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(capture.put.mock.lastCall?.[1].prefs).toEqual({
        'chat.family.assigned': null,
    });
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Family work assigned to you: in-app',
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save Preferences' }));
    expect(
        capture.put.mock.lastCall?.[1].prefs['chat.family.assigned'],
    ).toEqual({ ...enabled, inapp: false });
    fireEvent.click(
        screen.getByRole('button', { name: 'Use organisation defaults' }),
    );
    expect(
        screen.getByRole('checkbox', {
            name: 'Family work assigned to you: receive notices',
        }),
    ).toHaveFocus();
});

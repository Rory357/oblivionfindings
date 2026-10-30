import { render } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { startChatMessageSounds } from './chat-message-sound-session';
import { ChatMessageSounds } from './chat-message-sounds';

const state = vi.hoisted(() => ({
    preferences: {
        loaded: true,
        error: '',
        values: { sound_enabled: true, quiet_enabled: false },
    },
    calling: { active: false },
}));
vi.mock('./chat-personal-preferences', () => ({
    useChatPreferences: () => state.preferences,
}));
vi.mock('./chat-calling', () => ({ useChatCalling: () => state.calling }));
vi.mock('./chat-message-sound-session', () => ({
    startChatMessageSounds: vi.fn(() => ({
        updated: vi.fn(),
        dispose: vi.fn(),
    })),
}));
beforeEach(() => {
    vi.clearAllMocks();
    state.preferences = {
        loaded: true,
        error: '',
        values: { sound_enabled: true, quiet_enabled: false },
    };
    state.calling = { active: false };
});

it('mounts silently for a signed-in enabled actor and replaces the complete lifecycle on account change', () => {
    const view = render(<ChatMessageSounds actorId={1} enabled={false} />);
    expect(startChatMessageSounds).not.toHaveBeenCalled();
    view.rerender(<ChatMessageSounds actorId={1} enabled />);
    expect(view.container).toBeEmptyDOMElement();
    const first = vi.mocked(startChatMessageSounds).mock.results[0].value;
    expect(vi.mocked(startChatMessageSounds).mock.calls[0][1]()).toEqual({
        enabled: true,
        activeCall: false,
    });
    view.rerender(<ChatMessageSounds actorId={2} enabled />);
    expect(first.dispose).toHaveBeenCalledOnce();
    expect(startChatMessageSounds).toHaveBeenLastCalledWith(
        2,
        expect.any(Function),
    );
    const second = vi.mocked(startChatMessageSounds).mock.results[1].value;
    view.rerender(<ChatMessageSounds actorId={null} enabled />);
    expect(second.dispose).toHaveBeenCalledOnce();
});

it('applies live quiet preference and call changes without rebuilding the audio lifecycle', () => {
    const view = render(<ChatMessageSounds actorId={1} enabled />);
    const current = vi.mocked(startChatMessageSounds).mock.calls[0][1];
    state.preferences.values.quiet_enabled = true;
    view.rerender(<ChatMessageSounds actorId={1} enabled />);
    expect(current().enabled).toBe(false);
    state.preferences.values.quiet_enabled = false;
    state.calling.active = true;
    view.rerender(<ChatMessageSounds actorId={1} enabled />);
    expect(current()).toEqual({ enabled: true, activeCall: true });
    state.preferences.error = 'Preferences could not be checked';
    view.rerender(<ChatMessageSounds actorId={1} enabled />);
    expect(current().enabled).toBe(false);
    expect(startChatMessageSounds).toHaveBeenCalledOnce();
    view.rerender(<ChatMessageSounds actorId={1} enabled={false} />);
    expect(
        vi.mocked(startChatMessageSounds).mock.results[0].value.dispose,
    ).toHaveBeenCalledOnce();
});

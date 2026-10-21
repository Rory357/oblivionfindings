import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AudioPreview } from './audio-preview';

beforeEach(() => {
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (
        this: HTMLMediaElement,
    ) {
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
    });
    vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(function (
        this: HTMLMediaElement,
    ) {
        this.dispatchEvent(new Event('pause'));
    });
    vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('pauses the first voice message when another starts, retaining its reading position', () => {
    const view = render(
        <>
            <AudioPreview url="blob:first" label="First" duration={20} />
            <AudioPreview url="blob:second" label="Second" duration={10} />
        </>,
    );
    const [first, second] = view.container.querySelectorAll('audio');
    fireEvent.click(screen.getByRole('button', { name: 'Play First' }));
    first.currentTime = 7;
    fireEvent.timeUpdate(first);
    expect(screen.getByRole('button', { name: 'Pause First' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Play Second' }));
    expect(screen.getByRole('button', { name: 'Play First' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Pause Second' })).toBeVisible();
    expect(first.currentTime).toBe(7);
    fireEvent.ended(first);
    fireEvent.play(first);
    expect(screen.getByRole('button', { name: 'Play Second' })).toBeVisible();
    expect(vi.mocked(HTMLMediaElement.prototype.pause).mock.contexts).toContain(
        second,
    );
});

it('releases old audio and resets errors, time and speed when the private file changes', () => {
    const view = render(<AudioPreview url="blob:old" duration={20} />);
    const old = view.container.querySelector('audio')!;
    old.currentTime = 9;
    fireEvent.timeUpdate(old);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '2' } });
    fireEvent.error(old);
    expect(screen.getByRole('alert')).toBeVisible();
    view.rerender(<AudioPreview url="blob:new" duration={12} />);
    const current = view.container.querySelector('audio')!;
    expect(current).not.toBe(old);
    expect(old).not.toHaveAttribute('src');
    expect(current).toHaveAttribute('src', 'blob:new');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByRole('slider')).toHaveValue('0');
    expect(screen.getByRole('combobox')).toHaveValue('1');
    expect(screen.getByText('0:00 / 0:12')).toBeVisible();
});

it('keeps the source playable through Strict Mode effect replay and clears it on unmount', () => {
    const view = render(
        <StrictMode>
            <AudioPreview url="blob:strict" autoPlay />
        </StrictMode>,
    );
    const audio = view.container.querySelector('audio')!;
    expect(audio).toHaveAttribute('src', 'blob:strict');
    expect(
        screen.getByRole('button', { name: 'Pause Voice message' }),
    ).toBeVisible();
    view.unmount();
    expect(audio).not.toHaveAttribute('src');
});

it('does not remove the source when autoplay preference changes', () => {
    const view = render(<AudioPreview url="blob:same" />);
    view.rerender(<AudioPreview url="blob:same" autoPlay />);
    expect(view.container.querySelector('audio')).toHaveAttribute(
        'src',
        'blob:same',
    );
    expect(
        within(view.container).getByRole('button', {
            name: 'Pause Voice message',
        }),
    ).toBeVisible();
});

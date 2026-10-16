import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useHubPageCreationWorkspace } from './_page-creation-context';

function Workspace() {
    const { creation, open, close } = useHubPageCreationWorkspace();
    return (
        <>
            <section hidden={!!creation}>
                <button onClick={() => open()}>Write a story</button>
            </section>
            {creation && (
                <section>
                    <h1 data-hub-page-creation-heading tabIndex={-1}>
                        Start with a purpose
                    </h1>
                    <button onClick={() => close()}>Cancel</button>
                    <button onClick={() => close(false)}>
                        Open created draft
                    </button>
                </section>
            )}
        </>
    );
}

describe('page creation browse return', () => {
    const frames = new Map<number, FrameRequestCallback>();
    let sequence = 0;
    const flush = () =>
        act(() => {
            const pending = [...frames.values()];
            frames.clear();
            pending.forEach((callback) => callback(0));
        });
    beforeEach(() => {
        frames.clear();
        window.history.replaceState({}, '', '/company-hub?view=news');
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => {
                frames.set(++sequence, callback);
                return sequence;
            },
        );
        vi.stubGlobal('cancelAnimationFrame', (id: number) =>
            frames.delete(id),
        );
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
        vi.spyOn(window, 'scrollY', 'get').mockReturnValue(640);
        Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
            configurable: true,
            value: vi.fn(),
        });
    });
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('focuses the page heading and restores the opener and scroll regions on cancellation', () => {
        const view = render(
            <div data-testid="scroll" scroll-region="">
                <Workspace />
            </div>,
        );
        const region = screen.getByTestId('scroll');
        region.scrollTop = 180;
        const opener = screen.getByRole('button', { name: 'Write a story' });
        opener.focus();
        fireEvent.click(opener);
        flush();
        expect(
            screen.getByRole('heading', { name: 'Start with a purpose' }),
        ).toHaveFocus();
        region.scrollTop = 12;
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        flush();
        expect(opener).toHaveFocus();
        expect(window.scrollTo).toHaveBeenCalledWith({
            top: 640,
            behavior: 'instant',
        });
        expect(region.scrollTop).toBe(180);
        view.unmount();
    });

    it('does not restore old browse focus or scroll when opening the created draft', () => {
        render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        flush();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open created draft' }),
        );
        flush();
        expect(window.scrollTo).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Write a story' }),
        ).not.toHaveFocus();
    });

    it('cancels deferred focus on unmount and suppresses restoration after navigating elsewhere', () => {
        const view = render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        view.unmount();
        expect(frames.size).toBe(0);
        render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        flush();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        window.history.replaceState({}, '', '/company-hub?view=events');
        flush();
        expect(window.scrollTo).not.toHaveBeenCalled();
    });
});

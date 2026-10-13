import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useSettingsLeaveConfirmation } from './use-settings-leave-confirmation';
const routerMock = vi.hoisted(() => ({
    visit: vi.fn(),
    before: null as
        | null
        | ((event: {
              preventDefault: () => void;
              detail: { visit: { method: string; url: URL } };
          }) => void),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        visit: routerMock.visit,
        on: (_type: string, callback: typeof routerMock.before) => {
            routerMock.before = callback;
            return () => {
                routerMock.before = null;
            };
        },
    },
}));
class FakeNavigation extends EventTarget {
    currentEntry = { key: 'current' };
    traverseTo = vi.fn(() => ({ finished: Promise.resolve() }));
    back() {
        const event = Object.assign(
            new Event('navigate', { cancelable: true }),
            {
                navigationType: 'traverse',
                destination: { key: 'previous', sameDocument: true },
            },
        );
        this.dispatchEvent(event);
        return event;
    }
}
let navigation: FakeNavigation;
function Harness({
    dirty = true,
    scope = 'actor-7',
}: {
    dirty?: boolean;
    scope?: string;
}) {
    return <Draft key={scope} dirty={dirty} />;
}
function Draft({ dirty }: { dirty: boolean }) {
    const leave = useSettingsLeaveConfirmation(dirty);
    return (
        <>
            <input aria-label="Private draft" defaultValue="unsaved value" />
            <Button
                type="button"
                onClick={() => leave.request(() => routerMock.visit('/next'))}
            >
                Leave form
            </Button>
            <output>
                {leave.historyProtectionAvailable
                    ? 'History protection available'
                    : 'Use Cancel to leave safely'}
            </output>
            {leave.confirmation}
        </>
    );
}
beforeEach(() => {
    routerMock.visit.mockReset();
    navigation = new FakeNavigation();
    Object.defineProperty(window, 'navigation', {
        configurable: true,
        value: navigation,
    });
});
afterEach(() => {
    cleanup();
    Reflect.deleteProperty(window, 'navigation');
});
describe('Shared draft confirmation with native history', () => {
    it('keeps values and current URL unchanged when a Back prompt is cancelled', () => {
        render(<Harness />);
        let event!: Event;
        act(() => {
            event = navigation.back();
        });
        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'unsaved entries',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText('Private draft')).toHaveValue(
            'unsaved value',
        );
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('approves the captured history entry only after explicit discard', () => {
        render(<Harness />);
        act(() => navigation.back());
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(navigation.traverseTo).toHaveBeenCalledExactlyOnceWith(
            'previous',
        );
        expect(routerMock.visit).not.toHaveBeenCalled();
    });
    it('removes a pending traversal when a save becomes clean and does not reuse it for later edits', async () => {
        const view = render(<Harness />);
        act(() => navigation.back());
        view.rerender(<Harness dirty={false} />);
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        view.rerender(<Harness />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('does not carry a pending old-actor traversal into replacement scope', () => {
        const view = render(<Harness />);
        act(() => navigation.back());
        view.rerender(<Harness scope="actor-8" />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(navigation.traverseTo).not.toHaveBeenCalled();
    });
    it('keeps cross-document reload protection and removes it when clean', () => {
        const view = render(<Harness />);
        const dirtyUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirtyUnload);
        expect(dirtyUnload.defaultPrevented).toBe(true);
        view.rerender(<Harness dirty={false} />);
        const cleanUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(cleanUnload);
        expect(cleanUnload.defaultPrevented).toBe(false);
    });
    it('reports unavailable history protection while retaining explicit close and GET guards', () => {
        Reflect.deleteProperty(window, 'navigation');
        render(<Harness />);
        expect(
            screen.getByText('Use Cancel to leave safely'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Leave form' }));
        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(routerMock.visit).not.toHaveBeenCalled();
    });
});

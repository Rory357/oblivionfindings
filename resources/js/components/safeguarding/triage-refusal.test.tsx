import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { type CloseCheckItem, type ConcernDetail, TriagePane, triageRefusals } from './concern-dialog';

// Bug (6 Nov 2026): when the server refused a triage, the refusal came back under `override_reason` or `triage`,
// which the pane never showed, so nothing seemed to happen. The pane now shows every refusal it has no field for.
// B5 (Stephan, 6 Nov 2026: "Keep checks, say what to close"): choosing "No further action" lists what must be closed
// first, each with a link.
const state = vi.hoisted(() => ({ errors: {} as Record<string, string> }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: ({ children, href }: { children: unknown; href: string }) => React.createElement('a', { href }, children as never),
        router: { on: () => () => undefined, reload: vi.fn() },
        usePage: () => ({ props: { flash: {} } }),
        useForm: (defaults: Record<string, unknown>) => {
            const [data, setData] = React.useState(defaults);
            return {
                data, errors: state.errors, processing: false, isDirty: false,
                setData: (key: string, value: unknown) => setData((previous) => ({ ...previous, [key]: value })),
                setError: vi.fn(), clearErrors: vi.fn(), reset: vi.fn(), post: vi.fn(),
            };
        },
    };
});

const OPEN: CloseCheckItem[] = [
    { key: 'hs_event', label: 'Close its H&S safety review HS-2026-0094', detail: 'Close it with its WorkSafe decision and closing summary.', done: false, href: '/health-safety/events/94' },
    { key: 'control_room', label: 'Resolve its Control Room alert CR-2026-0031', detail: 'Resolve it with a resolution.', done: false, href: '/control-room/alerts/31' },
    { key: 'external_referral', label: 'Log the external referral', detail: 'An external referral is flagged but not logged yet. Log it on this concern.', done: false, href: null },
];
const concern = (checklist: CloseCheckItem[] = []) =>
    ({ id: 11, reference_number: 'SG-2026-0006', assignable_staff: [], current_risk_level: 'low', close_checklist: checklist }) as unknown as ConcernDetail;
const open = (checklist: CloseCheckItem[] = []) => render(<TriagePane d={concern(checklist)} onDone={vi.fn()} />);
beforeEach(() => { state.errors = {}; });
afterEach(cleanup);

it('shows no banner when nothing was refused', () => {
    open();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('shows an unlogged-referral refusal in plain words', () => {
    state.errors = { override_reason: 'Open work or an unlogged referral remains — record why you are closing anyway.' };
    open();
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('The concern wasn’t triaged');
    expect(alert).toHaveTextContent('An external referral is still to be logged, or other safeguarding work is still open.');
    expect(alert).not.toHaveTextContent('closing anyway');
});

it('shows the server’s plain refusal as it is', () => {
    state.errors = { triage: 'Its H&S safety review is still open. Close it first, with its WorkSafe decision and closing summary.' };
    open();
    expect(screen.getByRole('alert')).toHaveTextContent('Its H&S safety review is still open. Close it first, with its WorkSafe decision and closing summary.');
});

it('shows any other refusal or unmapped field error word for word, never silently', () => {
    state.errors = { triage: 'This concern has already been triaged.', lead_user_id: 'The selected lead is not eligible.' };
    open();
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('This concern has already been triaged.');
    expect(alert).toHaveTextContent('The selected lead is not eligible.');
});

it('leaves field errors beside their own field and lists each refusal once', () => {
    expect(triageRefusals({ notes: 'Record why no further action is required.', substantiation: 'Pick one.' })).toEqual([]);
    expect(triageRefusals({
        override_reason: 'Open work or an unlogged referral remains — record why you are closing anyway.',
        triage: 'An override reason alone cannot authorise closure with open safeguarding work.',
    })).toHaveLength(1);
});

it('lists what to close first, with links, once "No further action" is chosen', () => {
    open(OPEN);
    expect(screen.queryByRole('list', { name: 'Close these first' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /No further action/ }));

    const list = screen.getByRole('list', { name: 'Close these first' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(3);
    expect(within(items[0]).getByRole('link', { name: /Open/ })).toHaveAttribute('href', '/health-safety/events/94');
    expect(within(items[1]).getByRole('link', { name: /Open/ })).toHaveAttribute('href', '/control-room/alerts/31');
    expect(within(items[2]).queryByRole('link')).not.toBeInTheDocument();
    expect(items[2]).toHaveTextContent('Log the external referral');
});

it('shows no list when everything is already closed', () => {
    open(OPEN.map((item) => ({ ...item, done: true })));
    fireEvent.click(screen.getByRole('button', { name: /No further action/ }));
    expect(screen.queryByRole('list', { name: 'Close these first' })).not.toBeInTheDocument();
});

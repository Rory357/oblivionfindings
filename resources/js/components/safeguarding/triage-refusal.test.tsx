import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { type ConcernDetail, TriagePane, triageRefusals } from './concern-dialog';

// Bug (6 Nov 2026): when the server refused a triage, the refusal came back under `override_reason` or `triage`,
// which the pane never showed, so nothing seemed to happen. The pane now shows every refusal it has no field for.
const state = vi.hoisted(() => ({ errors: {} as Record<string, string> }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: ({ children }: { children: unknown }) => children,
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

const concern = { id: 11, reference_number: 'SG-2026-0006', assignable_staff: [], current_risk_level: 'low' } as unknown as ConcernDetail;
const open = () => render(<TriagePane d={concern} onDone={vi.fn()} />);
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

it('shows an open H&S event refusal in plain words', () => {
    state.errors = { triage: 'Close the canonical H&S safeguarding event with its required evidence first.' };
    open();
    expect(screen.getByRole('alert')).toHaveTextContent('Its H&S safeguarding event is still open. Close that event first.');
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
    expect(triageRefusals({ triage: 'The canonical Control Room safeguarding alert is missing or conflicting.' }))
        .toEqual(['Its linked incident, H&S event or Control Room alert can’t be found or doesn’t match. Ask an administrator to check the links.']);
});

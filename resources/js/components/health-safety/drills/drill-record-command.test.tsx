import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DrillRecordCommandForm } from './drill-record-command';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

const state = vi.hoisted(() => ({
    submit: vi.fn(),
    response: 'confirmed' as 'confirmed' | 'unconfirmed' | 'error',
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { on: () => () => {} },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                errors: {},
                isDirty: false,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                reset: vi.fn(),
                submit: (
                    method: string,
                    url: string,
                    options: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    state.submit(method, url, transform.current(data));
                    if (state.response !== 'unconfirmed')
                        options.onSuccess({
                            props:
                                state.response === 'error'
                                    ? {
                                          flash: {
                                              error: 'This drill changed. Review the current record.',
                                          },
                                      }
                                    : { detail: { id: 8, lock_version: 5 } },
                        });
                    options.onFinish();
                },
            };
        },
    };
});
const detail = {
    id: 8,
    expected_version: 4,
    lock_version: 4,
    findings: [],
    participants: [],
    attachments: [],
    can: {
        start: true,
        cancel: true,
        add_participant: true,
        add_finding: true,
    },
} as unknown as DrillWorkspaceDetail;
const pickers: DrillPickers = {
    sites: [],
    staff: [],
    drill_types: [],
    finding_types: ['observation'],
    severities: ['medium'],
    outcomes: [],
};
beforeEach(() => {
    state.submit.mockReset();
    state.response = 'confirmed';
});
afterEach(cleanup);

it('keeps the reviewed parent revision when a background record update arrives before submission', () => {
    const props = {
        detail,
        pickers,
        command: 'cancel' as const,
        onSaved: vi.fn(),
        onCancel: vi.fn(),
    };
    const view = render(<DrillRecordCommandForm {...props} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for cancellation/ }),
        { target: { value: 'Venue no longer available.' } },
    );
    view.rerender(
        <DrillRecordCommandForm
            {...props}
            detail={{ ...detail, expected_version: 6, lock_version: 6 }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel drill' }));
    expect(state.submit).toHaveBeenCalledWith(
        'post',
        '/health-safety/drills/8/cancel',
        expect.objectContaining({
            expected_version: 4,
            reason: 'Venue no longer available.',
            idempotency_key: expect.any(String),
        }),
    );
});

it('retains notes and the command identity after an unconfirmed response', () => {
    state.response = 'unconfirmed';
    const saved = vi.fn();
    render(
        <DrillRecordCommandForm
            detail={detail}
            pickers={pickers}
            command="cancel"
            onSaved={saved}
            onCancel={vi.fn()}
        />,
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for cancellation/ }),
        { target: { value: 'Retain this cancellation reason.' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel drill' }));
    const first = state.submit.mock.calls[0][2];
    expect(screen.getByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(
        screen.getByRole('textbox', { name: /Reason for cancellation/ }),
    ).toHaveValue('Retain this cancellation reason.');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel drill' }));
    expect(state.submit.mock.calls[1][2]).toEqual(first);
    expect(saved).not.toHaveBeenCalled();
});

it('stops a command when permission is revoked without submitting old editable values', () => {
    const props = {
        detail,
        pickers,
        command: 'cancel' as const,
        onSaved: vi.fn(),
        onCancel: vi.fn(),
    };
    const view = render(<DrillRecordCommandForm {...props} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for cancellation/ }),
        { target: { value: 'Still private draft.' } },
    );
    view.rerender(
        <DrillRecordCommandForm
            {...props}
            detail={{ ...detail, can: { ...detail.can, cancel: false } }}
        />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('no longer available');
    expect(
        screen.queryByRole('button', { name: 'Cancel drill' }),
    ).not.toBeInTheDocument();
    expect(state.submit).not.toHaveBeenCalled();
});

it('keeps a linked finding unresolved when independent verification blocks the command', () => {
    const finding = {
        id: 19,
        description: 'Exit route obstructed.',
        can: {
            edit: true,
            resolve: false,
            create_action: false,
            link_action: false,
        },
    } as DrillWorkspaceDetail['findings'][number];
    render(
        <DrillRecordCommandForm
            detail={{ ...detail, findings: [finding] }}
            pickers={pickers}
            command="resolve-finding"
            findingId={19}
            onSaved={vi.fn()}
            onCancel={vi.fn()}
        />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('linked remedy');
    expect(
        screen.queryByRole('button', { name: 'Record finding resolution' }),
    ).not.toBeInTheDocument();
    expect(state.submit).not.toHaveBeenCalled();
});

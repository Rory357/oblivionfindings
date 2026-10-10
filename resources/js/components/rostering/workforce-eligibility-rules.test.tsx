import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    WorkforceEligibilityRules,
    parseEligibilityRules,
    type EligibilityRules,
} from './workforce-eligibility-rules';
const transport = vi.hoisted(() => ({ patch: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: transport }));
const rules: EligibilityRules = {
    values: {
        unmapped_mandatory_qualification: 'warn',
        house_qualification_approach: 'per_requirement',
    },
    defaults: {
        unmapped_mandatory_qualification: 'warn',
        house_qualification_approach: 'per_requirement',
    },
    revision: 'a'.repeat(64),
    source: 'deployment_defaults',
    scope: 'organisation',
    can_edit: true,
    can_view_history: true,
    urls: {
        update: '/operations/workforce-settings/eligibility-rules',
        history: '/operations/workforce-settings/eligibility-rules/history',
    },
};
const props = {
    actorId: 7,
    rules,
    visible: true,
    query: '',
    timezone: 'Pacific/Auckland',
    onDirtyChange: vi.fn(),
    onUncertainChange: vi.fn(),
    onShow: vi.fn(),
};
const read = (latest: EligibilityRules) => ({
    props: { auth: { user: { id: 7 } }, eligibilityRules: latest },
});
function chooseBlock() {
    fireEvent.click(
        screen.getByLabelText('Unrecognised mandatory qualification'),
    );
    fireEvent.click(
        screen.getByRole('option', { name: 'Block until resolved' }),
    );
}
function reviewBlock() {
    chooseBlock();
    fireEvent.click(
        screen.getByRole('button', { name: 'Review qualification rule' }),
    );
}
function submitBlock() {
    reviewBlock();
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: {
            value: 'Require a recognised qualification before the next roster.',
        },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Save qualification rule' }),
    );
}
const receipt = {
    action: 'eligibility_rules',
    actor_id: 7,
    expected_revision: rules.revision,
    prior_revision: rules.revision,
    revision: 'b'.repeat(64),
    values: {
        unmapped_mandatory_qualification: 'block',
        house_qualification_approach: 'per_requirement',
    },
    changed: true,
    refresh: { status: 'staged', recheck_id: 21, source_version: 1 },
};
beforeEach(() => vi.clearAllMocks());
describe('Qualification policy settings', () => {
    it('shows the supplied warning default and protects the read-only role', () => {
        render(
            <WorkforceEligibilityRules
                {...props}
                rules={{
                    ...rules,
                    can_edit: false,
                    can_view_history: false,
                    urls: { update: null, history: null },
                }}
            />,
        );
        expect(
            screen.getByText('Warn — authorised manager may proceed'),
        ).toBeVisible();
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Review qualification rule' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Change history' }),
        ).not.toBeInTheDocument();
        expect(transport.patch).not.toHaveBeenCalled();
    });
    it('reviews the exact change, requires a reason and confirms only a staged saved result', () => {
        render(<WorkforceEligibilityRules {...props} />);
        reviewBlock();
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                /Warn — authorised manager may proceed → Block until resolved/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save qualification rule' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: { value: 'Require mapping for this house.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save qualification rule' }),
        );
        expect(transport.patch.mock.calls[0][1]).toEqual({
            expected_revision: rules.revision,
            values: receipt.values,
            reason: 'Require mapping for this house.',
        });
        act(() => {
            transport.patch.mock.calls[0][2].onSuccess({
                props: {
                    auth: { user: { id: 7 } },
                    flash: { workforce_settings_result: receipt },
                },
            });
            transport.patch.mock.calls[0][2].onFinish();
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText(/Qualification rule saved/)).toBeVisible();
        expect(
            screen.getByText('No unsaved qualification changes'),
        ).toBeVisible();
    });
    it.each(['missing refresh', 'other actor', 'different value'] as const)(
        'holds %s evidence and preserves the choice through recovery',
        (failure) => {
            render(<WorkforceEligibilityRules {...props} />);
            submitBlock();
            const wrong =
                failure === 'missing refresh'
                    ? { ...receipt, refresh: undefined }
                    : failure === 'other actor'
                      ? { ...receipt, actor_id: 9 }
                      : { ...receipt, values: rules.values };
            act(() => {
                transport.patch.mock.calls[0][2].onSuccess({
                    props: {
                        auth: { user: { id: 7 } },
                        flash: { workforce_settings_result: wrong },
                    },
                });
                transport.patch.mock.calls[0][2].onFinish();
            });
            expect(
                screen.queryByText(/Qualification rule saved/),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Save qualification rule' }),
            ).toBeDisabled();
            fireEvent.click(
                within(screen.getByRole('dialog')).getByRole('button', {
                    name: 'Check current qualification rule',
                }),
            );
            act(() => {
                transport.reload.mock.calls[0][0].onSuccess(read(rules));
                transport.reload.mock.calls[0][0].onFinish();
            });
            expect(
                screen.getByLabelText('Unrecognised mandatory qualification'),
            ).toHaveTextContent('Block until resolved');
            expect(
                screen.getByText(/This read does not confirm the earlier save/),
            ).toBeVisible();
            expect(transport.patch).toHaveBeenCalledOnce();
        },
    );
    it('retains unsaved choice on a newer projection and prevents saving after authority is removed', () => {
        const rendered = render(<WorkforceEligibilityRules {...props} />);
        reviewBlock();
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: { value: 'Retain this draft.' },
        });
        rendered.rerender(
            <WorkforceEligibilityRules
                {...props}
                rules={{
                    ...rules,
                    revision: 'c'.repeat(64),
                    can_edit: false,
                    urls: { ...rules.urls, update: null },
                }}
            />,
        );
        expect(screen.getByLabelText('Reason for this change')).toHaveValue(
            'Retain this draft.',
        );
        expect(
            screen.getByRole('button', { name: 'Save qualification rule' }),
        ).toBeDisabled();
        expect(transport.patch).not.toHaveBeenCalled();
    });
    it('keeps the draft mounted when its settings section is hidden', () => {
        const rendered = render(<WorkforceEligibilityRules {...props} />);
        chooseBlock();
        rendered.rerender(
            <WorkforceEligibilityRules {...props} visible={false} />,
        );
        expect(
            screen.getByText('You have an unsaved qualification rule.'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review qualification rule' }),
        );
        expect(props.onShow).toHaveBeenCalledOnce();
        rendered.rerender(<WorkforceEligibilityRules {...props} />);
        expect(
            screen.getByLabelText('Unrecognised mandatory qualification'),
        ).toHaveTextContent('Block until resolved');
    });
    it('does not treat malformed or unknown policy values as a safe default', () => {
        expect(parseEligibilityRules(rules)).toEqual(rules);
        expect(
            parseEligibilityRules({
                ...rules,
                values: { unmapped_mandatory_qualification: 'ignore' },
            }),
        ).toBeNull();
        expect(
            parseEligibilityRules({ ...rules, revision: 'stale' }),
        ).toBeNull();
        expect(
            parseEligibilityRules({
                ...rules,
                values: { ...rules.values, extra: 'block' },
            }),
        ).toBeNull();
    });
});

it('defaults House requirements to individual choices and reviews a changed default without rewriting existing choices', () => {
    render(<WorkforceEligibilityRules {...props} />);
    expect(
        screen.getByLabelText('House qualification approach'),
    ).toHaveTextContent('Choose per requirement');
    expect(
        screen.getByText(/Existing requirements keep their own choices/),
    ).toBeVisible();
    fireEvent.click(screen.getByLabelText('House qualification approach'));
    fireEvent.click(
        screen.getByRole('option', {
            name: 'Minimum number of qualified workers',
        }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review qualification rule' }),
    );
    expect(
        within(screen.getByRole('dialog')).getByText(
            /Choose per requirement → Minimum number of qualified workers/,
        ),
    ).toBeVisible();
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: {
            value: 'New House requirements will specify their required count.',
        },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Save qualification rule' }),
    );
    expect(transport.patch.mock.calls[0][1].values).toEqual({
        unmapped_mandatory_qualification: 'warn',
        house_qualification_approach: 'minimum_staff',
    });
    expect(transport.patch.mock.calls[0][1]).not.toHaveProperty(
        'minimum_qualified_staff',
    );
});

it('retains the qualification history selector through page changes', async () => {
    const fetchMock = vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (input) => {
            const page = Number(
                new URL(String(input)).searchParams.get('page'),
            );
            return {
                ok: true,
                json: async () => ({
                    data: [],
                    current_page: page,
                    last_page: 2,
                    total: 0,
                }),
            } as Response;
        });
    try {
        render(
            <WorkforceEligibilityRules
                {...props}
                rules={{
                    ...rules,
                    urls: {
                        ...rules.urls,
                        history:
                            '/operations/workforce-settings/history?action=eligibility_rules',
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Change history' }));
        await screen.findByText('0 recorded changes · Page 1 of 2');
        let requested = new URL(String(fetchMock.mock.calls[0][0]));
        expect(requested.searchParams.get('action')).toBe('eligibility_rules');
        expect(requested.searchParams.get('page')).toBe('1');
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        requested = new URL(String(fetchMock.mock.calls[1][0]));
        expect(requested.searchParams.get('action')).toBe('eligibility_rules');
        expect(requested.searchParams.get('page')).toBe('2');
        await screen.findByText('0 recorded changes · Page 2 of 2');
    } finally {
        fetchMock.mockRestore();
    }
});

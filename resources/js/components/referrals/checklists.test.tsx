import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ChecklistPanel } from './checklist-panel';
import type { ChecklistRecord } from './checklist-types';
import { SettingsChecklists } from './settings-checklists';
import {
    configurationChanges,
    type ReferralConfiguration,
} from './settings-types';
import type { ReferralDetail } from './types';

const { patch, reload } = vi.hoisted(() => ({
    patch: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        patch,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
const template: ChecklistRecord = {
    id: 'preparation',
    label: 'Application preparation',
    enabled: true,
    audience: 'funding',
    role_id: null,
    service_ids: [],
    gate: 'handover',
    editable: true,
    items: [
        {
            id: 'next_steps',
            title: 'Discuss next steps',
            instruction: 'Agree the next action with the person',
            enabled: true,
            required: true,
            completed: false,
            decision: null,
        },
    ],
};
const referral = {
    reference: 'fictional-checklist',
    version: 7,
    name: 'Fictional person',
    checklists: [template],
} as ReferralDetail;
beforeEach(() => {
    vi.clearAllMocks();
    HTMLElement.prototype.scrollTo = vi.fn();
});

it('requires a review note and records the exact checklist item and record version', async () => {
    patch.mockResolvedValue({ data: { saved: true } });
    render(<ChecklistPanel referral={referral} />);
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Record completion for Discuss next steps',
        }),
    );
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What was completed?' }),
        { target: { value: 'Discussed the fictional application next steps' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        within(screen.getByRole('dialog')).getByRole('button', {
            name: 'Record completion',
        }),
    );
    await screen.findByText('Checklist updated');
    expect(
        within(screen.getByRole('dialog')).queryByRole('button', {
            name: 'Record completion',
        }),
    ).not.toBeInTheDocument();
    expect(patch).toHaveBeenCalledWith(
        '/operations/referrals/fictional-checklist/checklists',
        {
            version: 7,
            template_id: 'preparation',
            item_id: 'next_steps',
            completed: true,
            note: 'Discussed the fictional application next steps',
        },
    );
    expect(reload).toHaveBeenCalledTimes(1);
});

it('compares checklist policy by stable references when templates and steps are reordered', () => {
    const other = {
        ...template,
        id: 'other',
        items: [...template.items, { ...template.items[0], id: 'extra' }],
    };
    const before = {
        checklists: [template, other],
    } as unknown as ReferralConfiguration;
    const after = {
        checklists: [{ ...other, items: [...other.items].reverse() }, template],
    } as unknown as ReferralConfiguration;
    expect(configurationChanges(before, after)).toEqual([]);
});

it('does not resend an uncertain completion or show a false success', async () => {
    patch.mockRejectedValue({ isAxiosError: true });
    render(<ChecklistPanel referral={referral} />);
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Record completion for Discuss next steps',
        }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What was completed?' }),
        { target: { value: 'Fictional preparation completed' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        within(screen.getByRole('dialog')).getByRole('button', {
            name: 'Record completion',
        }),
    );
    await screen.findByText(/Save was not confirmed/);
    expect(
        within(screen.getByRole('dialog')).getByRole('button', {
            name: 'Record completion',
        }),
    ).toBeDisabled();
    expect(screen.queryByText('Checklist updated')).not.toBeInTheDocument();
    expect(patch).toHaveBeenCalledTimes(1);
});

it('preserves an earlier completion while explaining that changed requirements need review', () => {
    render(
        <ChecklistPanel
            referral={{
                ...referral,
                checklists: [
                    {
                        ...template,
                        editable: false,
                        items: [
                            {
                                ...template.items[0],
                                decision: {
                                    completed: true,
                                    note: 'Original fictional review',
                                    recorded_at: '2026-10-18T01:00:00Z',
                                    recorded_by: 1,
                                    recorded_by_name: 'Original reviewer',
                                    settings_version_id: 2,
                                    template_label: 'Original checklist',
                                    item: template.items[0],
                                    signature: 'old',
                                },
                            },
                        ],
                    },
                ],
            }}
        />,
    );
    expect(screen.getByText('0 of 1 completed')).toBeInTheDocument();
    expect(screen.getByText('Review changed requirements')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /Record completion/ }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByText('Last recorded review · Original reviewer'),
    );
    expect(screen.getByText('Original fictional review')).toBeInTheDocument();
});

it('archives a configured step without removing its stable reference', () => {
    const change = vi.fn();
    const { container } = render(
        <SettingsChecklists
            value={
                {
                    checklists: [template],
                    services: [],
                } as unknown as ReferralConfiguration
            }
            onChange={change}
            roles={[]}
            disabled={false}
        />,
    );
    fireEvent.click(container.querySelector('summary')!);
    fireEvent.click(
        screen.getByRole('switch', {
            name: 'Use this step',
        }),
    );
    expect(change.mock.calls[0][0].checklists[0].items[0]).toMatchObject({
        id: 'next_steps',
        enabled: false,
    });
    expect(template.items[0].enabled).toBe(true);
});

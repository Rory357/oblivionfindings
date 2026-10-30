import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import {
    configurationChanges,
    defaultWorkflowTransitions,
    type ReferralConfiguration,
} from './settings-types';
import { SettingsWorkflow } from './settings-workflow';

const configuration = {
    stages: [
        { id: 'new', label: 'New' },
        { id: 'triage', label: 'Triage' },
        { id: 'assessment', label: 'Assessment' },
        { id: 'funding_assistance', label: 'Funding assistance' },
    ],
    workflow: {
        transitions: [
            { from: 'new', to: 'triage', role_id: null, evidence: [] },
            { from: 'triage', to: 'assessment', role_id: null, evidence: [] },
            {
                from: 'assessment',
                to: 'funding_assistance',
                role_id: 2,
                evidence: ['funding:application'],
            },
        ],
    },
} as ReferralConfiguration;

it('edits evidence requirements without mutating the saved configuration', () => {
    const change = vi.fn();
    const { container } = render(
        <SettingsWorkflow
            value={configuration}
            onChange={change}
            roles={[{ id: 2, name: 'Navigator' }]}
            disabled={false}
        />,
    );
    const detail = container.querySelectorAll('details')[1];
    fireEvent.click(detail.querySelector('summary')!);
    fireEvent.click(
        within(detail).getByRole('checkbox', { name: 'Assessment · Identity' }),
    );
    expect(change).toHaveBeenCalledWith(
        expect.objectContaining({
            workflow: {
                transitions: [
                    configuration.workflow!.transitions[0],
                    {
                        ...configuration.workflow!.transitions[1],
                        evidence: ['assessment:identity'],
                    },
                    configuration.workflow!.transitions[2],
                ],
            },
        }),
    );
    expect(configuration.workflow!.transitions[1].evidence).toEqual([]);
});

it('protects the initial triage route while allowing later transitions to be removed', () => {
    const change = vi.fn();
    const { container } = render(
        <SettingsWorkflow
            value={configuration}
            onChange={change}
            roles={[]}
            disabled={false}
        />,
    );
    const first = container.querySelectorAll('details')[0];
    fireEvent.click(first.querySelector('summary')!);
    expect(
        within(first).queryByRole('button', { name: 'Remove this transition' }),
    ).not.toBeInTheDocument();
    expect(within(first).queryByRole('checkbox')).not.toBeInTheDocument();
    const second = container.querySelectorAll('details')[1];
    fireEvent.click(second.querySelector('summary')!);
    fireEvent.click(
        within(second).getByRole('button', { name: 'Remove this transition' }),
    );
    expect(change.mock.calls[0][0].workflow.transitions).toHaveLength(2);
});

it('shows published role and evidence requirements without editable controls', () => {
    const { container } = render(
        <SettingsWorkflow
            value={configuration}
            onChange={vi.fn()}
            roles={[{ id: 2, name: 'Navigator' }]}
            disabled={false}
            readOnly
        />,
    );
    const last = container.querySelectorAll('details')[2];
    fireEvent.click(last.querySelector('summary')!);
    expect(within(last).getByText('Funding · Application')).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Add allowed transition' }),
    ).not.toBeInTheDocument();
});

it('compares legacy defaults truthfully and keeps transition diffs stable when a route is removed', () => {
    const { workflow: omitted, ...legacy } = configuration;
    void omitted;
    const explicit = {
        ...legacy,
        workflow: { transitions: defaultWorkflowTransitions() },
    } as ReferralConfiguration;
    expect(
        configurationChanges(legacy as ReferralConfiguration, explicit),
    ).toEqual([]);
    const changed = {
        ...explicit,
        workflow: {
            transitions: explicit.workflow!.transitions.filter(
                (rule) => !(rule.from === 'new' && rule.to === 'assessment'),
            ),
        },
    };
    const differences = configurationChanges(explicit, changed);
    expect(differences.length).toBeGreaterThan(0);
    expect(
        differences.every((item) => item.label.includes('new → assessment')),
    ).toBe(true);
});

import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { WorkflowRequirements } from './workflow-requirements';

it('explains visible requirements without implying verified funding or available files', () => {
    render(
        <WorkflowRequirements
            target={{
                id: 'assessment',
                label: 'Assessment review',
                required_evidence: ['funding:external_decision'],
            }}
        />,
    );
    expect(
        screen.getByText('Before moving to Assessment review'),
    ).toBeInTheDocument();
    expect(screen.getByText('Funding · External decision')).toBeInTheDocument();
    expect(
        screen.getByText(/not a confirmation that the documents are ready/),
    ).toBeInTheDocument();
});

it('asks for an authorized colleague without inventing restricted document details', () => {
    render(
        <WorkflowRequirements
            target={{
                id: 'assessment',
                label: 'Assessment',
                required_evidence: [],
                restricted_requirements: true,
            }}
        />,
    );
    expect(screen.getByText(/outside your access/)).toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
});

it('does not add an empty checklist for a legacy unrestricted transition', () => {
    const { container } = render(
        <WorkflowRequirements target={{ id: 'triage', label: 'Triage' }} />,
    );
    expect(container).toBeEmptyDOMElement();
});

it('explains that ready for handover re-checks the preparation gate on save', () => {
    render(
        <WorkflowRequirements
            target={{
                id: 'ready_for_handover',
                label: 'Ready for handover',
                required_evidence: [],
                restricted_requirements: false,
                requires_readiness: true,
            }}
        />,
    );
    expect(
        screen.getByText('Before moving to Ready for handover'),
    ).toBeInTheDocument();
    expect(
        screen.getByText(/Every handover preparation check must pass/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
});

import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { SettingsImpact } from './settings-impact';

const impact = {
    checked_at: '2026-10-18T01:00:00Z',
    version_id: 3,
    scope: 'Only records you can currently view.',
    open_referrals: 2,
    without_owner: 1,
    outside_enabled_services: 1,
    retaining_another_version: 2,
    funding_outside_enabled_pathways: null,
    intake_team: 'Central intake',
    enabled_services: ['Supported living'],
    public_enabled: true,
    notice_version: 'v3',
};

it('keeps unavailable funding totals distinct from zero and explains the scope', () => {
    render(<SettingsImpact impact={impact} />);
    expect(
        screen.getByText(/Only records you can currently view\./),
    ).toBeInTheDocument();
    expect(
        screen.getByText(/Funding impact requires permission/),
    ).toBeInTheDocument();
    expect(screen.getByText('Not available')).toBeInTheDocument();
    expect(
        screen.getByText(/publishing does not reassign or migrate/),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Nothing that people use changes in this version.'),
    ).toBeInTheDocument();
});

it('groups plain-language change lines by area and flags weaker safeguards', () => {
    render(
        <SettingsImpact
            impact={{
                ...impact,
                lines: [
                    {
                        area: 'workflow',
                        affects: 'People waiting for first contact',
                        text: 'New referrals are due for first contact within 4 working days instead of 2.',
                        loosens: true,
                    },
                    {
                        area: 'funding',
                        affects: 'Staff preparing funding',
                        text: 'Contact details for “DSS / NASC” change.',
                        loosens: false,
                    },
                ],
            }}
        />,
    );
    expect(screen.getByText('Workflow')).toBeInTheDocument();
    expect(screen.getByText('Funding')).toBeInTheDocument();
    expect(screen.queryByText('Intake')).not.toBeInTheDocument();
    expect(screen.getAllByText('Loosens a safeguard')).toHaveLength(1);
    expect(
        screen.getByText('People waiting for first contact'),
    ).toBeInTheDocument();
});

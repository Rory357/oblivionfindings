import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ClientSafetyRibbon, { type ClientSafety } from './client-safety-ribbon';

const empty: ClientSafety = {
    has_any: false,
    allergies: [],
    critical_risks: [],
    other_risks_count: 0,
    active_risks_count: 0,
    care_flags: [],
    risk_level: null,
    safeguarding_flag: false,
};

describe('canonical allergy safety summary', () => {
    it('does not imply absence when medical details are withheld', () => {
        const { container } = render(
            <ClientSafetyRibbon safety={{ ...empty, allergy_record: null }} />,
        );
        expect(container).toBeEmptyDOMElement();
    });
    it('shows unreviewed empty evidence without claiming no known allergies', () => {
        render(
            <ClientSafetyRibbon
                safety={{
                    ...empty,
                    allergy_record: {
                        status: 'none',
                        entries: [],
                        reviewed: null,
                        digest: 'current',
                    },
                }}
            />,
        );
        expect(screen.getByText('Allergies not reviewed')).toBeInTheDocument();
        expect(
            screen.queryByText('No known allergies'),
        ).not.toBeInTheDocument();
    });
    it('only labels reviewed absence as no known allergies', () => {
        render(
            <ClientSafetyRibbon
                safety={{
                    ...empty,
                    allergy_record: {
                        status: 'no_known',
                        entries: [],
                        reviewed: {
                            at: '2026-10-08T01:00:00Z',
                            by: 'Clinical lead',
                            how: 'Checked current record',
                        },
                        digest: 'current',
                    },
                }}
            />,
        );
        expect(screen.getByText('No known allergies')).toBeInTheDocument();
        expect(
            screen.queryByText('Allergies not reviewed'),
        ).not.toBeInTheDocument();
    });
    it('shows canonical life-threatening severity in the visible allergy label', () => {
        render(
            <ClientSafetyRibbon
                safety={{
                    ...empty,
                    has_any: true,
                    allergies: [
                        {
                            key: 'shellfish',
                            label: 'Shellfish',
                            group: 'food',
                            severity: 'life_threatening',
                            reaction: 'Anaphylaxis',
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByText('Allergy: Shellfish · life threatening'),
        ).toBeInTheDocument();
    });
});

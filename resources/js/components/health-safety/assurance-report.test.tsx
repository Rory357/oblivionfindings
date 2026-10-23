import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AssuranceReport } from './assurance-report';

describe('readable safety assurance reports', () => {
    it('preserves zero, missing and false as different facts', () => {
        render(
            <AssuranceReport
                query=""
                report={{
                    items: [
                        {
                            reference_number: 'HS-1',
                            worksafe_reference: null,
                            immediate_causes_count: 0,
                            investigation_required: false,
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('Not recorded')).toBeInTheDocument();
        expect(screen.getByText('0')).toBeInTheDocument();
        expect(screen.getByText('No')).toBeInTheDocument();
    });

    it('does not turn empty training or effectiveness denominators into assured compliance', () => {
        render(
            <AssuranceReport
                query=""
                report={{
                    training_compliance: {
                        total_requirements: 0,
                        non_compliant_staff: 0,
                        compliance_rate: 100,
                    },
                    corrective_action_summary: {
                        open: 0,
                        effectiveness_rate: 100,
                    },
                }}
            />,
        );
        expect(screen.queryByText('100')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                /Missing or unassessed records do not establish compliance/,
            ),
        ).toBeInTheDocument();
    });

    it('searches full retained report text without changing report summary counts', () => {
        render(
            <AssuranceReport
                query="repair"
                report={{
                    summary: { total: 2 },
                    items: [
                        {
                            reference_number: 'CA-1',
                            title: 'Door repair',
                            assigned_to: 'Sam',
                        },
                        { reference_number: 'CA-2', title: 'Policy review' },
                    ],
                }}
            />,
        );
        expect(screen.getByText('1 of 2 records')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'CA-1' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'CA-2' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Sam')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('distinguishes a scoped empty report from an unmatched search', () => {
        const { rerender } = render(
            <AssuranceReport query="" report={{ items: [] }} />,
        );
        expect(
            screen.getByText('No records in this report’s scope.'),
        ).toBeInTheDocument();
        rerender(
            <AssuranceReport
                query="missing"
                report={{ items: [{ reference_number: 'HS-1' }] }}
            />,
        );
        expect(
            screen.getByText('No records match your search.'),
        ).toBeInTheDocument();
    });
});

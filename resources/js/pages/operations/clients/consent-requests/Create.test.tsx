import { configure, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConsentRequestsCreate from './Create';

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }));
configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', async () => {
    const ReactModule = await import('react');

    return {
        Head: () => null,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, updateData] = ReactModule.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                post: (url: string) => postMock(url, data),
                setData: (keyOrData: keyof T | T, value?: T[keyof T]) => {
                    if (typeof keyOrData === 'object') {
                        updateData(keyOrData);
                        return;
                    }

                    updateData(
                        (current) =>
                            ({
                                ...current,
                                [keyOrData]: value,
                            }) as T,
                    );
                },
            };
        },
    };
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/page', () => ({
    PageHero: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

vi.mock('@/components/ui/select', async () => {
    const ReactModule = await import('react');

    const SelectTrigger = () => null;
    const SelectContent = ({ children }: { children: React.ReactNode }) => (
        <>{children}</>
    );
    const SelectItem = ({
        value,
        children,
    }: {
        value: string;
        children: React.ReactNode;
    }) => <option value={value}>{children}</option>;
    const SelectValue = () => null;
    const Select = ({
        children,
        value,
        onValueChange,
        disabled,
    }: {
        children: React.ReactNode;
        value: string;
        onValueChange: (value: string) => void;
        disabled?: boolean;
    }) => {
        const elements = ReactModule.Children.toArray(children).filter(
            ReactModule.isValidElement,
        ) as React.ReactElement[];
        const trigger = elements.find(
            (element) => element.type === SelectTrigger,
        );
        const content = elements.find(
            (element) => element.type === SelectContent,
        );
        const triggerProps = (trigger?.props ?? {}) as Record<string, unknown>;

        return (
            <select
                id={triggerProps.id as string | undefined}
                data-test={triggerProps['data-test'] as string | undefined}
                value={value}
                disabled={disabled}
                onChange={(event) => onValueChange(event.target.value)}
            >
                <option value="">Select…</option>
                {content
                    ? (content.props as { children: React.ReactNode }).children
                    : null}
            </select>
        );
    };

    return {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
        SelectValue,
    };
});

const props = {
    client: { id: 41, full_name: 'Aroha Rangi' },
    consent_types: [{ id: 7, name: 'Care information', category: 'care' }],
    portal_users: [
        {
            id: 19,
            name: 'Moana Rangi',
            email: 'moana@example.test',
            relationship: 'next_of_kin',
        },
    ],
    relationship_options: {
        welfare_guardian: 'Welfare Guardian (PPPR Act)',
        next_of_kin: 'Next of Kin (informational only)',
        client: 'Client themselves',
    },
};

describe('canonical consent-request evidence UI', () => {
    beforeEach(() => postMock.mockClear());

    it('submits the complete decision-specific evidence bundle for a substitute request', () => {
        render(<ConsentRequestsCreate {...props} />);

        fireEvent.change(screen.getByTestId('consent-relationship-select'), {
            target: { value: 'welfare_guardian' },
        });
        expect(
            screen.getByTestId('substitute-decision-evidence'),
        ).toBeVisible();

        fireEvent.change(screen.getByTestId('capacity-outcome-select'), {
            target: { value: 'lacks_capacity' },
        });
        fireEvent.change(screen.getByTestId('capacity-assessed-at-input'), {
            target: { value: '2026-08-20T09:00' },
        });
        fireEvent.change(screen.getByTestId('capacity-expires-at-input'), {
            target: { value: '2026-09-20T09:00' },
        });
        fireEvent.change(screen.getByTestId('capacity-reason-input'), {
            target: {
                value: 'The client could not understand or weigh this specific decision.',
            },
        });
        fireEvent.change(screen.getByTestId('capacity-evidence-type-input'), {
            target: { value: 'Documented assessment' },
        });
        fireEvent.change(
            screen.getByTestId('capacity-evidence-reference-input'),
            { target: { value: 'CAP-2026-0042' } },
        );
        fireEvent.change(screen.getByTestId('best-interests-reason-input'), {
            target: {
                value: 'Known wishes, effects and less restrictive alternatives were reviewed.',
            },
        });
        fireEvent.change(
            screen.getByTestId('best-interests-evidence-type-input'),
            { target: { value: 'Multidisciplinary review' } },
        );
        fireEvent.change(
            screen.getByTestId('best-interests-evidence-reference-input'),
            { target: { value: 'BI-2026-0042' } },
        );
        fireEvent.change(
            screen.getByTestId('best-interests-consultees-input'),
            {
                target: {
                    value: 'Key worker\nClinical lead\nWelfare guardian',
                },
            },
        );

        fireEvent.submit(screen.getByTestId('consent-request-create-form'));

        expect(postMock).toHaveBeenCalledWith(
            '/operations/clients/41/consent-requests',
            expect.objectContaining({
                recipient_relationship: 'welfare_guardian',
                capacity_outcome: 'lacks_capacity',
                capacity_assessed_at: '2026-08-20T09:00',
                capacity_assessment_expires_at: '2026-09-20T09:00',
                capacity_evidence_reference: 'CAP-2026-0042',
                best_interests_evidence_reference: 'BI-2026-0042',
                best_interests_consultees: [
                    'Key worker',
                    'Clinical lead',
                    'Welfare guardian',
                ],
            }),
        );
    });

    it('keeps the non-substitute path unchanged and clears hidden substitute evidence', () => {
        render(<ConsentRequestsCreate {...props} />);

        fireEvent.change(screen.getByTestId('consent-relationship-select'), {
            target: { value: 'welfare_guardian' },
        });
        fireEvent.change(screen.getByTestId('capacity-reason-input'), {
            target: { value: 'Decision-specific evidence entered here.' },
        });
        fireEvent.change(screen.getByTestId('consent-relationship-select'), {
            target: { value: 'next_of_kin' },
        });

        expect(
            screen.queryByTestId('substitute-decision-evidence'),
        ).not.toBeInTheDocument();
        expect(screen.getByTestId('consent-purpose-input')).toBeVisible();
        expect(screen.getByTestId('consent-request-submit')).toBeEnabled();

        fireEvent.submit(screen.getByTestId('consent-request-create-form'));
        expect(postMock).toHaveBeenLastCalledWith(
            '/operations/clients/41/consent-requests',
            expect.objectContaining({
                recipient_relationship: 'next_of_kin',
                capacity_outcome: '',
                capacity_assessment_reason: '',
                best_interests_consultees: [],
            }),
        );
    });
});

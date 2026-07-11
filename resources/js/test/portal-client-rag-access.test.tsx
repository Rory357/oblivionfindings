import { render, screen } from '@testing-library/react';
import type React from 'react';
import { expect, it, vi } from 'vitest';

import PortalClient from '@/pages/portal/client';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@/components/shift-timeline-summary', () => ({
    default: () => null,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactModule = await import('react');

    return {
        Head: () => null,
        useForm: <T,>(initial: T) => {
            const [data, setState] = ReactModule.useState(initial);

            return {
                data,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setState((current) => ({
                        ...current,
                        [key]: value,
                    })),
                processing: false,
                errors: {},
                post: vi.fn(),
            };
        },
    };
});

const baseProps = {
    client: {
        id: 41,
        first_name: 'Aroha',
        last_name: 'Ngata',
    },
    documents: [],
    incidents: [],
    events: [],
    assets: [],
    can: {
        viewIncidents: false,
        downloadIncidentAttachments: false,
        askRag: false,
    },
};

it('hides the unredacted RAG form when the portal identity is not the client', () => {
    render(<PortalClient {...baseProps} />);

    expect(
        screen.queryByRole('heading', { name: 'Ask about Aroha Ngata' }),
    ).not.toBeInTheDocument();
});

it('shows the RAG form when the server grants self-record access', () => {
    render(
        <PortalClient
            {...baseProps}
            can={{
                ...baseProps.can,
                askRag: true,
            }}
        />,
    );

    expect(
        screen.getByRole('heading', { name: 'Ask about Aroha Ngata' }),
    ).toBeVisible();
});

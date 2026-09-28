import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initialDefinition, type Source } from './model';
import { ReportWorkspace } from './workspace';

const navigation = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: navigation,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('./model', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./model')>()),
    api: vi.fn(
        async (
            _url: string,
            _method?: string,
            body?: { definition?: unknown },
        ) => ({
            json: async () =>
                body?.definition
                    ? { definition: body.definition }
                    : { targets: [], sites: [], runs: [] },
        }),
    ),
}));

const sources: Record<string, Source> = Object.fromEntries(
    ['journeys', 'demand', 'maintenance', 'resource_costs'].map((key) => [
        key,
        {
            label: key,
            domain: 'fleet',
            note: 'Permitted source',
            fields: {
                status: {
                    label: 'Status',
                    type: 'text',
                    unit: '',
                    description: 'Recorded status',
                },
            },
        },
    ]),
);
const draftKey = 'operational-report-draft:42:fleet';
const props = {
    domain: 'fleet',
    sources,
    templates: [],
    saved: [],
    viewerId: 42,
    initialView: 'library',
};

beforeEach(() => {
    window.sessionStorage.clear();
    vi.clearAllMocks();
});
afterEach(cleanup);

describe('Report workspace navigation and recovery', () => {
    it('keeps the existing builder draft while browsing the library and focused reports', () => {
        const draft = JSON.stringify(
            initialDefinition('journeys', sources, 'Recover this design'),
        );
        sessionStorage.setItem(draftKey, draft);
        render(<ReportWorkspace {...props} />);
        expect(sessionStorage.getItem(draftKey)).toBe(draft);
        expect(
            screen.getByRole('button', { name: 'Recover draft' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('tab', { name: 'Demand & delivery' }));
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Demand & delivery',
        );
        expect(navigation.push).toHaveBeenLastCalledWith(
            expect.objectContaining({
                url: '/fleet-assets/reports?view=demand',
            }),
        );
        expect(sessionStorage.getItem(draftKey)).toBe(draft);
    });

    it('persists a builder edit and recovers it after returning through the library', async () => {
        const builder = render(
            <ReportWorkspace {...props} initialView="builder" />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Report name' }), {
            target: { value: 'Fleet handover evidence' },
        });
        expect(JSON.parse(sessionStorage.getItem(draftKey)!).name).toBe(
            'Fleet handover evidence',
        );
        builder.unmount();
        render(<ReportWorkspace {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Recover draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', { name: 'Report name' }),
            ).toHaveValue('Fleet handover evidence'),
        );
        expect(navigation.push).toHaveBeenLastCalledWith(
            expect.objectContaining({ url: '/fleet-assets/reports/builder' }),
        );
    });

    it('honours focused deep links and restores the source on history navigation', () => {
        const page = render(
            <ReportWorkspace {...props} initialView="demand" />,
        );
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Demand & delivery',
        );
        page.rerender(<ReportWorkspace {...props} initialView="readiness" />);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Disruption & readiness',
        );
        expect(
            screen.getByRole('textbox', { name: 'Report name' }),
        ).toHaveValue('Disruption & readiness');
        page.rerender(<ReportWorkspace {...props} initialView="saved" />);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
            'Saved reports',
        );
        expect(
            screen.getByRole('heading', { name: 'No saved reports yet' }),
        ).toBeVisible();
    });
});

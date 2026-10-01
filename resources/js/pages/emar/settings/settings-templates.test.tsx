import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import type { SettingsPayload } from './_model';
import {
    CreateRounds,
    daysText,
    LEGACY_HOUSE,
    RoundTemplates,
    templateOverlaps,
    TemplateToggle,
    TemplateWizard,
    type RoundTemplate,
    type TemplateData,
} from './_templates';
import { RoundsOverview } from './_timing';

const routerMock = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: routerMock }));

const tpl = (over: Partial<RoundTemplate>): RoundTemplate => ({
    id: 1,
    name: 'Morning round',
    scheduled_time: '08:00',
    window_minutes: 60,
    days_of_week: [],
    status: 'active',
    site_id: 10,
    site_name: 'Kōwhai House',
    default_assigned_to: null,
    default_staff: null,
    today: { doses: 6, people: 3 },
    last_changed_by: 'Hana Kereama',
    last_changed_at: '2026-09-29T09:12:00+13:00',
    can_change: true,
    ...over,
});

const templates: RoundTemplate[] = [
    tpl({}),
    tpl({
        id: 2,
        name: 'Lunch round',
        scheduled_time: '12:00',
        window_minutes: 30,
        days_of_week: [1, 2, 3, 4, 5],
        status: 'paused',
        today: null,
        default_assigned_to: 7,
        default_staff: 'Jordan Tipene',
    }),
    tpl({
        id: 3,
        name: 'Old evening round',
        scheduled_time: '18:00',
        status: 'retired',
        today: null,
        can_change: false,
    }),
    tpl({
        id: 4,
        name: 'Legacy night round',
        scheduled_time: '21:00',
        status: 'paused',
        site_id: null,
        site_name: null,
        today: null,
    }),
];

const data = (over: Partial<TemplateData> = {}): TemplateData => ({
    templates,
    access: {
        read: true,
        manage: true,
        all_houses: false,
        sites: [{ id: 10, name: 'Kōwhai House' }],
    },
    staff: [{ id: 7, name: 'Jordan Tipene', site_ids: [10] }],
    readOnlyAudit: false,
    ...over,
});

function renderWith(node: ReactNode, over: Partial<SettingsContext> = {}) {
    const s = {
        groups: {},
        definitions: {},
        values: {},
        reviewed: {},
        site_values: {},
        site_reviewed: {},
        history: [],
        can_manage_organisation: true,
    } as SettingsPayload;
    const ctx: SettingsContext = {
        s,
        draft: {},
        setDraft: vi.fn(),
        canEdit: () => true,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
        ...over,
    };
    return {
        ctx,
        ...render(
            <SettingsCtx.Provider value={ctx}>{node}</SettingsCtx.Provider>,
        ),
    };
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('round template words', () => {
    it('says which days, and finds overlapping windows at the same house', () => {
        expect(daysText([])).toBe('Every day');
        expect(daysText([5, 4, 3, 2, 1])).toBe('Monday to Friday');
        expect(daysText([6, 7])).toBe('Saturday and Sunday');
        expect(daysText([1, 3])).toBe('Mon, Wed');
        const brunch = {
            id: 9,
            name: 'Brunch',
            site_id: 10,
            scheduled_time: '08:45',
            window_minutes: 30,
            days_of_week: [],
        };
        expect(templateOverlaps(templates, brunch).map((t) => t.id)).toEqual([
            1,
        ]);
        expect(templateOverlaps(templates, { ...brunch, site_id: 11 })).toEqual(
            [],
        );
    });
});

describe('Rounds & timing › Round templates', () => {
    it('lists active and paused templates with their house, staff and today', () => {
        renderWith(
            <RoundTemplates
                data={data()}
                q=""
                house="all"
                status="current"
                clear={vi.fn()}
            />,
        );
        expect(screen.getByText('3 of 4 shown')).toBeInTheDocument();
        expect(
            screen.getByText('8:00 am, 60 minutes either side · Every day'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '12:00 pm, 30 minutes either side · Monday to Friday',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('6 doses · 3 people')).toBeInTheDocument();
        expect(screen.getByText('Jordan Tipene')).toBeInTheDocument();
        expect(screen.queryByText('Old evening round')).toBeNull();
        // A template without a house never reads as every house.
        expect(screen.getByText(LEGACY_HOUSE)).toBeInTheDocument();
        expect(screen.queryByText('All houses')).toBeNull();
    });

    it('can’t turn a template with no house on from the list', () => {
        renderWith(
            <RoundTemplates
                data={data()}
                q=""
                house="all"
                status="current"
                clear={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('switch', {
                name: 'Create rounds from Legacy night round, ' + LEGACY_HOUSE,
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('switch', {
                name: 'Create rounds from Morning round, Kōwhai House',
            }),
        ).toBeEnabled();
    });

    it('shows a retired template read-only, with when it was retired', () => {
        renderWith(
            <RoundTemplates
                data={data()}
                q=""
                house="all"
                status="retired"
                clear={vi.fn()}
            />,
        );
        expect(screen.getByText('Old evening round')).toBeInTheDocument();
        expect(screen.getByText(/^Retired/)).toBeInTheDocument();
        expect(screen.queryByRole('switch')).toBeNull();
    });

    it('offers adding and creating rounds only to people who manage templates', () => {
        const { ctx } = renderWith(
            <RoundTemplates
                data={data()}
                q=""
                house="all"
                status="current"
                clear={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add a template' }));
        expect(ctx.open).toHaveBeenCalledWith({ kind: 'tpl', id: 'new' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create rounds for a day' }),
        );
        expect(ctx.open).toHaveBeenCalledWith({ kind: 'gen' });
        cleanup();
        renderWith(
            <RoundTemplates
                data={data({
                    access: {
                        read: true,
                        manage: false,
                        all_houses: false,
                        sites: [],
                    },
                    readOnlyAudit: true,
                    templates: templates.map((t) => ({
                        ...t,
                        can_change: false,
                    })),
                })}
                q=""
                house="all"
                status="current"
                clear={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Add a template' }),
        ).toBeNull();
        expect(
            screen.getByText(
                /Only people who manage orders at a house can change its round templates/,
            ),
        ).toBeInTheDocument();
    });
});

describe('Pause, turn back on, retire', () => {
    it('confirms a pause in red and saves it through the template', () => {
        renderWith(<TemplateToggle id={1} data={data()} />);
        expect(
            screen.getByText('Stop creating rounds from this template?'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Pause template' }));
        expect(routerMock.put).toHaveBeenCalledWith(
            '/emar/rounds/templates/1',
            { active: false },
            expect.anything(),
        );
    });

    it('asks for a house before a template with none can be turned on', () => {
        renderWith(<TemplateToggle id={4} data={data()} />);
        expect(screen.getByText('Choose a house first')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Turn template on' }),
        ).toBeNull();
    });

    it('retires through the retirement transition', () => {
        renderWith(<TemplateToggle id={1} retire data={data()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retire template' }),
        );
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/rounds/templates/1/retire',
            {},
            expect.anything(),
        );
    });
});

describe('Add a round template', () => {
    it('checks the name and the window before moving on', () => {
        renderWith(<TemplateWizard id="new" data={data()} />);
        fireEvent.change(screen.getByLabelText(/Doses due within/), {
            target: { value: '200' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(screen.getByText('Give the round a name.')).toBeInTheDocument();
        expect(
            screen.getByText('Enter a whole number of minutes from 5 to 120.'),
        ).toBeInTheDocument();
    });

    it('saves a template at its house with the chosen staff', () => {
        renderWith(<TemplateWizard id="new" data={data()} />);
        fireEvent.change(screen.getByLabelText(/^Name/), {
            target: { value: 'Breakfast round' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        fireEvent.click(screen.getByText('One person by default'));
        fireEvent.click(screen.getByText('Search and choose a person'));
        fireEvent.click(screen.getByText('Jordan Tipene'));
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        fireEvent.click(screen.getByRole('button', { name: /Save template/ }));
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/rounds/templates',
            {
                name: 'Breakfast round',
                scheduled_time: '08:00',
                window_minutes: 60,
                days_of_week: [],
                default_assigned_to: 7,
                active: true,
                site_id: 10,
            },
            expect.anything(),
        );
    });
});

describe('Create rounds for a day', () => {
    it('previews new and existing rounds, then creates them at the house', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                rounds: [
                    {
                        template_id: 1,
                        name: 'Morning round',
                        scheduled_time: '08:00',
                        status: 'exists',
                    },
                    {
                        template_id: 2,
                        name: 'Lunch round',
                        scheduled_time: '12:00',
                        status: 'new',
                    },
                ],
                create: 1,
                exists: 1,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        renderWith(<CreateRounds data={data()} />);
        await waitFor(() =>
            expect(screen.getByText('Creates 1 round')).toBeInTheDocument(),
        );
        expect(fetchMock.mock.calls[0][0]).toContain('site_id=10');
        expect(
            screen.getByText('Already exists — skipped'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Create rounds' }));
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/rounds/generate',
            expect.objectContaining({ site_id: 10, generate_all: false }),
            expect.anything(),
        );
        vi.unstubAllGlobals();
    });
});

describe('Rounds & timing › Overview', () => {
    it('counts active and paused templates', () => {
        renderWith(<RoundsOverview q="" templates={templates} />);
        expect(
            screen.getByText('1 active across 1 house · 2 paused.'),
        ).toBeInTheDocument();
    });
});

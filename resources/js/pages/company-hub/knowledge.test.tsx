import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubBlocks } from './_blocks';
import {
    ChecklistSettings,
    KnowledgeReader,
    isKnowledgePage,
} from './_knowledge';
import type {
    HubChecklistProgress,
    HubContent,
    HubKnowledge,
    HubProps,
} from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
it('keeps all intersecting guide sections when deciding the current contents link', () => {
    let notify!: IntersectionObserverCallback;
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            constructor(callback: IntersectionObserverCallback) {
                notify = callback;
            }
            observe() {}
            disconnect() {}
        },
    );
    try {
        render(
            <KnowledgeReader
                content={{
                    ...content,
                    sections: [
                        {
                            id: 'reading',
                            columns: 1,
                            blocks: [
                                {
                                    id: 'a',
                                    type: 'text',
                                    title: 'First section',
                                },
                                {
                                    id: 'b',
                                    type: 'text',
                                    title: 'Second section',
                                },
                            ],
                        },
                    ],
                }}
            >
                <section id="block-a">First body</section>
                <section id="block-b">Second body</section>
            </KnowledgeReader>,
        );
        const a = document.getElementById('block-a')!;
        const b = document.getElementById('block-b')!;
        vi.spyOn(a, 'getBoundingClientRect').mockReturnValue({
            top: 130,
        } as DOMRect);
        vi.spyOn(b, 'getBoundingClientRect').mockReturnValue({
            top: 250,
        } as DOMRect);
        const entry = (target: Element, isIntersecting: boolean) =>
            ({ target, isIntersecting }) as IntersectionObserverEntry;
        act(() =>
            notify(
                [entry(a, true), entry(b, true)],
                {} as IntersectionObserver,
            ),
        );
        expect(
            screen.getByRole('link', { name: 'First section' }),
        ).toHaveAttribute('aria-current', 'location');
        act(() => notify([entry(a, false)], {} as IntersectionObserver));
        expect(
            screen.getByRole('link', { name: 'Second section' }),
        ).toHaveAttribute('aria-current', 'location');
        act(() => notify([entry(a, true)], {} as IntersectionObserver));
        expect(
            screen.getByRole('link', { name: 'First section' }),
        ).toHaveAttribute('aria-current', 'location');
    } finally {
        vi.unstubAllGlobals();
    }
});
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post: vi.fn() },
}));

const content: HubContent = {
    title: 'Your first week',
    summary: 'Find your feet',
    category: 'Getting started',
    hero_label: 'Welcome',
    cover_id: null,
    featured: false,
    audience: { type: 'all', ids: [] },
    comments_enabled: false,
    reactions_enabled: false,
    requires_approval: false,
    show_in_navigation: false,
    navigation_order: 0,
    review_on: '2027-01-12',
    resource_type: 'Checklist',
    checklist: [
        { id: 'welcome', title: 'Meet your buddy' },
        { id: 'tools', title: 'Check your tools' },
    ],
    sections: [
        {
            id: 'first-week',
            columns: 1,
            blocks: [
                {
                    id: 'getting-started',
                    type: 'text',
                    title: 'Getting started',
                    body: 'A friendly introduction.',
                },
            ],
        },
    ],
};
const knowledge: HubKnowledge = {
    actor_id: 7,
    page_id: 11,
    revision_id: 30,
    version: 3,
    steps: content.checklist!.map((step) => ({
        ...step,
        key: step.id.repeat(8),
        done: false,
    })),
    owner: {
        id: 50,
        name: 'Aroha Bennett',
        position: 'People advisor',
        department: 'People team',
        work_email: 'aroha@company.test',
        work_phone: '021 123 456',
    },
    teams: [{ id: 4, name: 'People team' }],
    contacts: [],
    related: [],
    spaces: [],
};
function Reader({
    data = knowledge,
    preview = false,
    resourceType = 'Checklist',
}: {
    data?: HubKnowledge;
    preview?: boolean;
    resourceType?: string;
}) {
    return (
        <KnowledgeReader
            content={{ ...content, resource_type: resourceType }}
            knowledge={data}
            publishedAt="2026-10-13T00:00:00Z"
            preview={preview}
        >
            <HubBlocks
                sections={content.sections}
                data={{ pages: [] } as unknown as HubProps}
                onEvent={() => {}}
            />
        </KnowledgeReader>
    );
}

describe('knowledge reader', () => {
    beforeEach(() => {
        request.mockReset();
    });
    it('connects the contents rail to the rendered guide and shows real owner and review details', () => {
        render(<Reader />);
        const target = screen.getByRole('heading', { name: 'Getting started' });
        const link = within(
            screen.getByRole('navigation', { name: 'Guide contents' }),
        ).getByRole('link', { name: 'Getting started' });
        expect(link).toHaveAttribute('href', `#${target.id}`);
        expect(target).toHaveAttribute('tabindex', '-1');
        expect(screen.getByText(/Published version 3/)).toBeVisible();
        expect(screen.getByText(/Next review:/)).toHaveTextContent('Jan');
        expect(
            screen.getByRole('link', { name: 'Aroha Bennett' }),
        ).toHaveAttribute('href', '/company-hub/people/50');
        expect(
            screen.getByRole('link', { name: 'aroha@company.test' }),
        ).toHaveAttribute('href', 'mailto:aroha@company.test');
        expect(
            screen.getByText(/do not record a policy read confirmation/),
        ).toBeVisible();
    });
    it.each(['Checklist', 'Process'])(
        'saves and removes an exact personal %s step and displays server-confirmed progress',
        async (resourceType) => {
            request.mockImplementation((_url, _method, body) =>
                Promise.resolve({
                    ...knowledge,
                    steps: knowledge.steps.map((step) => ({
                        ...step,
                        done: step.id === body.step_id && body.done,
                    })),
                }),
            );
            render(<Reader resourceType={resourceType} />);
            fireEvent.click(
                screen.getByRole('checkbox', { name: 'Meet your buddy' }),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('checkbox', { name: 'Meet your buddy' }),
                ).toBeChecked(),
            );
            expect(request).toHaveBeenCalledWith(
                '/company-hub/pages/11/checklist',
                'PUT',
                {
                    actor_id: 7,
                    revision_id: 30,
                    step_id: 'welcome',
                    step_key: knowledge.steps[0].key,
                    done: true,
                },
            );
            expect(screen.getByText('1 of 2 complete')).toBeVisible();
            fireEvent.click(
                screen.getByRole('checkbox', { name: 'Meet your buddy' }),
            );
            await waitFor(() =>
                expect(
                    screen.getByRole('checkbox', { name: 'Meet your buddy' }),
                ).not.toBeChecked(),
            );
            expect(screen.getByRole('status')).toHaveTextContent(
                'Progress saved',
            );
        },
    );
    it('keeps the previous state and explains a rejected stale instruction', async () => {
        request.mockRejectedValue(
            new Error(
                'This guide has changed. Reload to see the latest checklist.',
            ),
        );
        render(<Reader />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Meet your buddy' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent('Reload');
        expect(
            screen.getByRole('checkbox', { name: 'Meet your buddy' }),
        ).not.toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'Meet your buddy' }),
        ).toBeEnabled();
    });
    it('does not accept progress returned for a different account', async () => {
        request.mockResolvedValue({
            ...knowledge,
            actor_id: 8,
            steps: knowledge.steps.map((step) => ({ ...step, done: true })),
        });
        render(<Reader />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Meet your buddy' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'account has changed',
        );
        expect(screen.getByText('0 of 2 complete')).toBeVisible();
    });
    it('ignores a response after the reader has moved to another account or revision', async () => {
        let resolve!: (value: HubChecklistProgress) => void;
        request.mockReturnValue(
            new Promise<HubChecklistProgress>((done) => {
                resolve = done;
            }),
        );
        const view = render(<Reader />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Meet your buddy' }),
        );
        view.rerender(
            <Reader data={{ ...knowledge, actor_id: 8, revision_id: 31 }} />,
        );
        await act(async () =>
            resolve({
                ...knowledge,
                steps: knowledge.steps.map((step) => ({ ...step, done: true })),
            }),
        );
        expect(screen.getByText('0 of 2 complete')).toBeVisible();
        expect(screen.queryByText('Progress saved')).not.toBeInTheDocument();
    });
    it.each(['Checklist', 'Process'])(
        'previews disabled unchecked %s steps without writing personal progress',
        (resourceType) => {
            render(
                <Reader
                    data={{
                        ...knowledge,
                        steps: knowledge.steps.map((step) => ({
                            ...step,
                            done: true,
                        })),
                    }}
                    preview
                    resourceType={resourceType}
                />,
            );
            expect(
                screen.getByRole('checkbox', { name: 'Meet your buddy' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('checkbox', { name: 'Meet your buddy' }),
            ).not.toBeChecked();
            expect(screen.getByText(/Draft preview/)).toBeVisible();
            expect(request).not.toHaveBeenCalled();
        },
    );
    it('treats a Process as a knowledge page without inventing steps for a prose-only process', () => {
        const prose = { ...content, resource_type: 'Process', checklist: [] };
        expect(isKnowledgePage('page', prose)).toBe(true);
        render(
            <KnowledgeReader
                content={prose}
                knowledge={{ ...knowledge, steps: [] }}
            >
                <p>Read the procedure.</p>
            </KnowledgeReader>,
        );
        expect(screen.getByText('Read the procedure.')).toBeVisible();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByText('Your checklist')).not.toBeInTheDocument();
    });
    it('edits reorders and removes checklist steps without replacing their stable identities', () => {
        let result = content.checklist!;
        function Settings() {
            const [value, setValue] = useState(result);
            return (
                <ChecklistSettings
                    value={value}
                    onChange={(next) => {
                        result = next;
                        setValue(next);
                    }}
                />
            );
        }
        render(<Settings />);
        fireEvent.click(screen.getByRole('button', { name: 'Move step 2 up' }));
        expect(result.map((step) => step.id)).toEqual(['tools', 'welcome']);
        fireEvent.change(screen.getByRole('textbox', { name: 'Step 1' }), {
            target: { value: 'Check your work tools' },
        });
        expect(result[0]).toEqual({
            id: 'tools',
            title: 'Check your work tools',
        });
        fireEvent.click(screen.getByRole('button', { name: 'Remove step 2' }));
        expect(result).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Add checklist step' }),
        );
        expect(result).toHaveLength(2);
        expect(result[0].id).toBe('tools');
        expect(result[1].id).not.toBe('tools');
    });
});

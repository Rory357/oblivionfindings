import { act, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HubPageCreationContext } from './_page-creation-context';
import { CompanySpaces } from './_spaces';
import { hubData, hubStory } from './_test-fixtures';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post, on: () => () => undefined },
}));
beforeEach(() => {
    post.mockReset();
});

it('continues team results and keeps search and return context specific to teams', () => {
    const pages = Array.from({ length: 26 }, (_, index) => ({
        ...hubStory,
        id: index + 1,
        kind: 'department',
        title: `Team ${index + 1}`,
        slug: `team-${index + 1}`,
    }));
    render(<CompanySpaces mode="teams" data={hubData({ pages })} />);
    expect(screen.getAllByRole('article')).toHaveLength(24);
    fireEvent.click(screen.getByRole('button', { name: 'Show more teams' }));
    expect(screen.getAllByRole('article')).toHaveLength(26);
    fireEvent.change(screen.getByRole('textbox', { name: 'Search teams' }), {
        target: { value: 'Team 26' },
    });
    expect(screen.getAllByRole('article')).toHaveLength(1);
    const destination = new URL(
        screen.getByRole('link', { name: 'Team 26' }).getAttribute('href')!,
        'https://hub.test',
    );
    expect(
        JSON.parse(destination.searchParams.get('hubBrowse')!),
    ).toMatchObject({
        view: 'teams',
        filters: { query: 'Team 26' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(screen.getAllByRole('article')).toHaveLength(24);
});

it('preserves the canonical follow state on failure and allows a retry after completion', () => {
    render(
        <CompanySpaces
            mode="campaigns"
            data={hubData({
                pages: [{ ...hubStory, kind: 'campaign' }],
            })}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Follow', exact: true }),
    );
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    const [url, payload, options] = post.mock.calls[0];
    expect(url).toBe('/company-hub/pages/50/follow');
    expect(payload).toEqual({ following: true });
    act(() => {
        options.onError();
        options.onFinish();
    });
    expect(screen.getByRole('alert')).toHaveTextContent('could not be saved');
    expect(
        screen.getByRole('button', { name: 'Follow', exact: true }),
    ).toBeEnabled();
    expect(
        screen.getByRole('button', { name: 'Follow', exact: true }),
    ).toHaveAttribute('aria-pressed', 'false');
});

it('opens the correct team template and hides authoring from readers', () => {
    const create = vi.fn();
    const { rerender } = render(
        <HubPageCreationContext.Provider value={create}>
            <CompanySpaces
                mode="teams"
                data={hubData({ capabilities: { write: true } })}
            />
        </HubPageCreationContext.Provider>,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Create team space', exact: true }),
    );
    expect(create).toHaveBeenCalledWith({
        initial: { title: '', kind: 'department', template: 'team' },
    });
    rerender(
        <HubPageCreationContext.Provider value={create}>
            <CompanySpaces mode="teams" data={hubData()} />
        </HubPageCreationContext.Provider>,
    );
    expect(
        screen.queryByRole('button', { name: /Create/ }),
    ).not.toBeInTheDocument();
});

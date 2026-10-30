import type { HubBlock, HubPageSummary, HubProps, HubSection } from './_types';

/** Match the department reader's fallback pools; an empty team selection stays empty. */
export function previewSourceData(
    data: HubProps,
    sourceTeamIds?: number[],
): HubProps {
    if (sourceTeamIds === undefined) return data;
    const ids = (data.interests?.available_teams ?? [])
        .filter((team) => sourceTeamIds.includes(team.id))
        .map((team) => team.id);
    return {
        ...data,
        blockData: undefined,
        pages: data.pages.filter((page) =>
            page.team_ids?.some((id) => ids.includes(id)),
        ),
        resourceItems: [],
        people: (data.people ?? []).filter((person) =>
            ids.includes(person.department_id ?? 0),
        ),
        kudos: data.kudos.filter((post) =>
            post.team_ids?.some((id) => ids.includes(id)),
        ),
        events: data.events.filter((event) =>
            ids.includes(event.extendedProps.departmentId ?? 0),
        ),
    };
}

/** Unsaved editor/showcase previews use complete, already-authorised source pools.
 * Published readers use the server blockData and never reconstruct retained overrides. */
export function previewEditorialBlocks(
    sections: HubSection[],
    data: HubProps,
    sourceTeamIds?: number[],
): NonNullable<HubProps['blockData']> {
    const available = new Set(
        (data.interests?.available_teams ?? []).map((team) => team.id),
    );
    const scoped = (block: HubBlock, pages: HubPageSummary[]) => {
        const selected = block.team_ids ?? [];
        const scope =
            sourceTeamIds === undefined
                ? selected.filter((id) => available.has(id))
                : sourceTeamIds.filter(
                      (id) =>
                          available.has(id) &&
                          (!selected.length || selected.includes(id)),
                  );
        const constrained = selected.length > 0 || sourceTeamIds !== undefined;
        return pages.filter(
            (page) =>
                (!constrained ||
                    page.team_ids?.some((id) => scope.includes(id))) &&
                (!block.category || page.category === block.category),
        );
    };
    const ordered = (pages: HubPageSummary[]) =>
        [...pages].sort(
            (a, b) =>
                b.published_at.localeCompare(a.published_at) || b.id - a.id,
        );
    const stories = ordered(
        data.pages.filter(
            (page) => page.kind === 'news' && page.show_in_news !== false,
        ),
    );
    const blocks = sections.flatMap((section) => section.blocks);
    const visibleBlockIds = new Set(
        sections
            .filter((section) => !section.hidden)
            .flatMap((section) =>
                section.blocks
                    .filter((block) => !block.hidden)
                    .map((block) => block.id),
            ),
    );
    const result: NonNullable<HubProps['blockData']> = {};
    const featuredIds = new Set<number>();
    for (const block of blocks.filter((item) => item.type === 'featured')) {
        const pool = scoped(block, stories);
        const chosen = pool.find((page) => page.id === block.story_id);
        const canonical = chosen ?? pool[0] ?? null;
        const image =
            chosen && block.media_id
                ? data.featuredMedia?.find(
                      (media) => media.id === block.media_id,
                  )
                : null;
        const lead = canonical
            ? {
                  ...canonical,
                  title:
                      chosen && block.title?.trim()
                          ? block.title
                          : canonical.title,
                  summary:
                      chosen && block.body?.trim()
                          ? block.body
                          : canonical.summary,
                  cover_url: image?.url ?? canonical.cover_url,
              }
            : null;
        const supporting =
            lead && block.layout !== 'single'
                ? pool.filter((page) => page.id !== lead.id).slice(0, 2)
                : [];
        result[block.id] = { featured: { lead, supporting } };
        if (visibleBlockIds.has(block.id) && lead)
            [lead, ...supporting].forEach((page) => featuredIds.add(page.id));
    }
    for (const block of blocks.filter((item) =>
        ['news', 'resources', 'campaign'].includes(item.type),
    )) {
        const limit = Math.max(
            1,
            Math.min(12, block.limit ?? (block.type === 'campaign' ? 1 : 4)),
        );
        const pool = scoped(
            block,
            block.type === 'news'
                ? stories
                : block.type === 'campaign'
                  ? ordered(
                        data.pages.filter((page) => page.kind === 'campaign'),
                    )
                  : data.pages.filter((page) =>
                        ['page', 'knowledge'].includes(page.kind),
                    ),
        );
        const withoutFeatured =
            block.type === 'news'
                ? pool.filter((page) => !featuredIds.has(page.id))
                : pool;
        result[block.id] = {
            [block.type === 'campaign' ? 'campaigns' : block.type]:
                (withoutFeatured.length ? withoutFeatured : pool).slice(
                    0,
                    limit,
                ),
        };
    }
    return result;
}

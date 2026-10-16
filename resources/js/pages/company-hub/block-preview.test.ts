import { describe, expect, it } from 'vitest';
import { previewEditorialBlocks } from './_block-preview';
import { hubData, hubStory } from './_test-fixtures';
import type { HubBlock, HubSection } from './_types';

const stories = [1, 2, 3, 4].map((id) => ({
    ...hubStory,
    id,
    title: `Story ${id}`,
    cover_url: `/company-hub/media/${id}`,
    team_ids: id === 4 ? [4] : [3],
}));
const data = hubData({
    pages: stories,
    interests: {
        actor_id: 11,
        version: 1,
        topics: [],
        teams: [],
        available_topics: [],
        available_teams: [
            { id: 3, name: 'Learning' },
            { id: 4, name: 'Garden' },
        ],
    },
    featuredMedia: [{ id: 10, url: '/company-hub/media/10', alt: 'Workshop' }],
});
const feature: HubBlock = {
    id: 'feature',
    type: 'featured',
    title: 'Our lead',
    body: 'An editorial message',
    story_id: 2,
    media_id: 10,
};
const news: HubBlock = { id: 'news', type: 'news', title: 'News', limit: 4 };
const section = (...blocks: HubBlock[]): HubSection[] => [
    { id: 'stories', columns: 1, blocks },
];

describe('authorised unsaved editorial preview', () => {
    it('keeps hidden section stories in the editor without displacing visible news', () => {
        const result = previewEditorialBlocks(
            [
                { ...section(feature)[0], hidden: true },
                { id: 'visible', columns: 1, blocks: [news] },
            ],
            data,
        );
        expect(result.feature.featured?.lead?.id).toBe(2);
        expect(result.news.news?.map((page) => page.id)).toEqual([4, 3, 2, 1]);
    });
    it('projects canonical campaigns from the complete authorised pool, intersects teams and defaults to one', () => {
        const campaigns = {
            ...data,
            pages: [
                ...stories,
                ...stories.map((page) => ({
                    ...page,
                    id: page.id + 10,
                    kind: 'campaign',
                    campaign_progress: { total: 5, done: 2, ends_on: null },
                })),
            ],
        };
        const block = {
            id: 'campaign',
            type: 'campaign',
            title: 'Initiatives',
        };
        expect(
            previewEditorialBlocks(
                section(block),
                campaigns,
            ).campaign.campaigns?.map((page) => page.id),
        ).toEqual([14]);
        expect(
            previewEditorialBlocks(
                section({ ...block, team_ids: [4] }),
                campaigns,
                [3],
            ).campaign.campaigns,
        ).toEqual([]);
        expect(
            previewEditorialBlocks(
                section({ ...block, team_ids: [99] }),
                campaigns,
            ).campaign.campaigns,
        ).toEqual([]);
        expect(
            previewEditorialBlocks(
                section({ ...block, limit: 3 }),
                campaigns,
                [3],
            ).campaign.campaigns?.map((page) => page.id),
        ).toEqual([13, 12, 11]);
    });
    it('keeps opt-out stories out of news blocks and featured fallbacks', () => {
        const pool = {
            ...data,
            pages: data.pages.map((page) => ({
                ...page,
                show_in_news: page.id !== 2 && page.id !== 4,
            })),
        };
        const result = previewEditorialBlocks(section(feature, news), pool);
        expect(result.feature.featured?.lead?.id).toBe(3);
        expect(
            result.feature.featured?.supporting.map((page) => page.id),
        ).toEqual([1]);
        expect(result.news.news?.map((page) => page.id)).toEqual([3, 1]);
    });
    it('applies overrides only to the explicit eligible story and approved destination image', () => {
        const original = structuredClone(data);
        expect(
            previewEditorialBlocks(section(feature), data).feature.featured
                ?.lead,
        ).toMatchObject({
            id: 2,
            title: 'Our lead',
            summary: 'An editorial message',
            cover_url: '/company-hub/media/10',
        });
        expect(
            previewEditorialBlocks(section({ ...feature, media_id: 999 }), data)
                .feature.featured?.lead?.cover_url,
        ).toBe('/company-hub/media/2');
        const withdrawn = previewEditorialBlocks(
            section({ ...feature, story_id: 999 }),
            data,
        ).feature.featured;
        expect(withdrawn?.lead).toMatchObject({
            id: 4,
            title: 'Story 4',
            summary: hubStory.summary,
            cover_url: '/company-hub/media/4',
        });
        expect(JSON.stringify(withdrawn)).not.toContain('An editorial message');
        expect(
            previewEditorialBlocks(
                section({ ...feature, story_id: null }),
                data,
            ).feature.featured?.lead?.title,
        ).toBe('Story 4');
        expect(data).toEqual(original);
    });
    it('deduplicates only visible rendered stories and restores a category pool when exclusion empties it', () => {
        const mosaic = previewEditorialBlocks(section(feature, news), data);
        expect(
            mosaic.feature.featured?.supporting.map((page) => page.id),
        ).toEqual([4, 3]);
        expect(mosaic.news.news?.map((page) => page.id)).toEqual([1]);
        expect(
            previewEditorialBlocks(
                section({ ...feature, layout: 'single' }, news),
                data,
            ).news.news?.map((page) => page.id),
        ).toEqual([4, 3, 1]);
        expect(
            previewEditorialBlocks(
                section({ ...feature, hidden: true }, news),
                data,
            ).news.news?.map((page) => page.id),
        ).toEqual([4, 3, 2, 1]);
        const three = { ...data, pages: stories.slice(0, 3) };
        expect(
            previewEditorialBlocks(
                section(feature, news),
                three,
            ).news.news?.map((page) => page.id),
        ).toEqual([3, 2, 1]);
    });
    it('intersects source teams with current options without widening an empty scope', () => {
        expect(
            previewEditorialBlocks(section(feature, news), data, []).feature
                .featured,
        ).toEqual({ lead: null, supporting: [] });
        expect(
            previewEditorialBlocks(
                section(feature, { ...news, team_ids: [4] }),
                data,
                [3],
            ).news.news,
        ).toEqual([]);
        expect(
            previewEditorialBlocks(
                section({ ...feature, story_id: 4 }),
                data,
                [3],
            ).feature.featured?.lead,
        ).toMatchObject({ id: 3, title: 'Story 3' });
        expect(
            previewEditorialBlocks(section({ ...news, team_ids: [99] }), data)
                .news.news,
        ).toEqual([]);
    });
    it('uses canonical page and knowledge resources and preserves true empty feature state', () => {
        const resource: HubBlock = {
            id: 'guides',
            type: 'resources',
            title: 'Guides',
        };
        const scoped = {
            ...data,
            resourceItems: [],
            pages: [
                { ...hubStory, kind: 'knowledge' },
                { ...hubStory, id: 99, kind: 'campaign' },
            ],
        };
        const projection = previewEditorialBlocks(
            section(feature, resource),
            scoped,
        );
        expect(projection.guides.resources).toEqual([
            { ...hubStory, kind: 'knowledge' },
        ]);
        expect(projection.feature.featured).toEqual({
            lead: null,
            supporting: [],
        });
    });
});

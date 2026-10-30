import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { CampaignReader, campaignPreview } from './_campaign-reader';
import { CampaignSettings } from './_campaign-settings';
import { hubContent, hubData, hubPerson, hubStory } from './_test-fixtures';
import type { HubContent, HubEvent } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post: vi.fn() },
    useForm: () => ({ data: { body: '' }, errors: {} }),
}));
// Shared calendar behaviour is covered by date-fields.test.tsx; these checks retain the campaign payload contract.
vi.mock('./_date-fields', () => ({
    HubDateField: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
const campaign: NonNullable<HubContent['campaign']> = {
    ends_on: '2026-12-31',
    steps: [{ id: 'read', title: 'Read a guide', url: '/guide' }],
    milestones: [
        { id: 'launch', title: 'Guides shared', status: 'done' },
        { id: 'meet', title: 'Learning circles', status: 'active' },
        { id: 'reflect', title: 'Reflect together', status: 'next' },
    ],
    story_ids: [50],
    resource_ids: [51],
    event_keys: [],
};
const data = hubData({
    pages: [
        hubStory,
        {
            ...hubStory,
            id: 51,
            kind: 'knowledge',
            slug: 'handbook',
            title: 'Handbook',
        },
    ],
    people: [hubPerson],
});
function Settings() {
    const [value, setValue] = useState<HubContent['campaign']>(
        structuredClone(campaign),
    );
    return (
        <>
            <CampaignSettings value={value} onChange={setValue} data={data} />
            <output aria-label="Saved campaign">{JSON.stringify(value)}</output>
        </>
    );
}
function saved() {
    return JSON.parse(
        screen.getByLabelText('Saved campaign').textContent!,
    ) as NonNullable<HubContent['campaign']>;
}

describe('shared campaign content', () => {
    it('keeps sample campaign resources and return links within the showcase', () => {
        const content = { ...hubContent, campaign };
        render(
            <CampaignReader
                content={content}
                campaign={campaignPreview(content, hubPerson.user_id!, data)}
                data={{ ...data, demo: true }}
                onEvent={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'All campaigns' }),
        ).toHaveAttribute('href', '/company-hub/showcase?view=campaigns');
        expect(screen.getByRole('link', { name: /Handbook/ })).toHaveAttribute(
            'href',
            '/company-hub/showcase?story=handbook',
        );
        expect(
            screen.queryByRole('link', { name: hubPerson.name }),
        ).not.toBeInTheDocument();
    });
    it('keeps shared milestones and curated selections when the last personal activity is removed or the end date changes', () => {
        render(<Settings />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove activity' }),
        );
        expect(saved().steps).toEqual([]);
        expect(saved().milestones).toEqual(campaign.milestones);
        expect(saved().resource_ids).toEqual([51]);
        fireEvent.change(screen.getByLabelText('Campaign end date'), {
            target: { value: '2027-01-20' },
        });
        expect(saved().ends_on).toBe('2027-01-20');
        expect(saved().story_ids).toEqual([50]);
    });
    it('edits status, orders milestones and curates links without changing personal completion definitions', () => {
        render(<Settings />);
        choose('Milestone 2 status', 'Complete');
        fireEvent.click(
            screen.getByRole('button', { name: 'Move milestone 2 up' }),
        );
        expect(saved().milestones?.[0]).toMatchObject({
            id: 'meet',
            status: 'done',
        });
        expect(saved().steps).toEqual(campaign.steps);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove resources 1' }),
        );
        choose('Add resources', 'Handbook');
        expect(saved().resource_ids).toEqual([51]);
        expect(saved().story_ids).toEqual([50]);
    });
    it('renders shared statuses and authorised resources, keeping personal actions out of preview', () => {
        const content = { ...hubContent, campaign };
        const preview = campaignPreview(content, 11, data);
        render(
            <CampaignReader
                content={content}
                campaign={preview}
                data={data}
                onEvent={vi.fn()}
                preview
            />,
        );
        expect(
            screen.getByRole('progressbar', {
                name: 'Shared campaign progress',
            }),
        ).toHaveAttribute('value', '1');
        expect(screen.getByText('In progress')).toBeVisible();
        expect(screen.getByText('Up next')).toBeVisible();
        expect(screen.getByRole('link', { name: /Handbook/ })).toHaveAttribute(
            'href',
            '/company-hub/read/handbook',
        );
        expect(
            within(
                screen.getByRole('region', { name: 'Campaign owner' }),
            ).getByRole('link', { name: 'Aroha Bennett' }),
        ).toHaveAttribute('href', '/company-hub/people/21');
        expect(
            screen.queryByRole('button', { name: 'Mark complete' }),
        ).not.toBeInTheDocument();
    });
    it('omits unavailable and wrong-kind references from preview while preserving editor selections for correction', () => {
        const content = {
            ...hubContent,
            campaign: {
                ...campaign,
                story_ids: [51, 999, 50],
                resource_ids: [50, 51, 999],
            },
        };
        const preview = campaignPreview(content, 99, data);
        expect(preview.stories.map((item) => item.id)).toEqual([50]);
        expect(preview.resources.map((item) => item.id)).toEqual([51]);
        expect(preview.owner).toBeNull();
        render(
            <CampaignSettings
                value={content.campaign}
                data={data}
                onChange={vi.fn()}
            />,
        );
        expect(
            screen.getAllByText('No longer available — remove this selection'),
        ).toHaveLength(4);
    });
    it('keeps ongoing and future selected events in order and omits ended occurrences', () => {
        const now = Date.now();
        const event = (id: number, start: number, end: number): HubEvent => ({
            id: String(id),
            title: `Learning circle ${id}`,
            start: new Date(start).toISOString(),
            end: new Date(end).toISOString(),
            allDay: false,
            extendedProps: {
                eventId: id,
                location: null,
                description: null,
                createdBy: 'Aroha Bennett',
                myRsvp: null,
                recurring: false,
                site: null,
                recurrenceParentId: null,
                occurrenceDate: null,
                editVersion: 1,
            },
        });
        const content = {
            ...hubContent,
            campaign: { ...campaign, event_keys: ['3', '1', '2'] },
        };
        const preview = campaignPreview(
            content,
            11,
            hubData({
                events: [
                    event(1, now - 60000, now - 1000),
                    event(2, now - 60000, now + 60000),
                    event(3, now + 60000, now + 120000),
                ],
            }),
        );
        expect(preview.events.map((item) => item.id)).toEqual(['3', '2']);
        expect(content.campaign.event_keys).toEqual(['3', '1', '2']);
    });
});

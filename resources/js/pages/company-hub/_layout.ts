import type { HubBlock, HubSection } from './_types';

export const blockLibrary = [
    ['featured', 'Featured stories'],
    ['text', 'Text & story'],
    ['news', 'Company news'],
    ['shoutouts', 'Shout-outs'],
    ['events', 'Events'],
    ['celebrations', 'Celebrations'],
    ['links', 'Useful links'],
    ['resources', 'Resources'],
    ['image', 'Image'],
    ['video', 'Video link'],
    ['notices', 'Important notices'],
    ['notice', 'Custom callout'],
    ['button', 'Call to action'],
    ['people', 'Staff directory'],
    ['teams', 'Team spaces'],
    ['services', 'Staff services'],
    ['poll', 'Quick poll'],
    ['campaign', 'Campaign'],
    ['file', 'Downloadable file'],
] as const;

export function createBlock(type: string): HubBlock {
    return {
        id: crypto.randomUUID(),
        type,
        title: blockLibrary.find((b) => b[0] === type)?.[1] ?? 'Content',
        ...(type === 'featured'
            ? { title: '', story_id: null, layout: 'mosaic' as const }
            : {}),
        ...(type === 'button'
            ? { action_label: 'Explore', body: '', url: '' }
            : {}),
        ...(type === 'notice' ? { body: '' } : {}),
        ...(type === 'text' ? { body: '' } : {}),
        ...(type === 'people' ? { mode: 'finder' as const } : {}),
        ...(type === 'campaign' ? { limit: 1 } : {}),
        ...(type === 'links' ? { links: [] } : {}),
        ...(type === 'poll'
            ? {
                  question: 'What would you like to see next?',
                  options: ['Team activity', 'Learning session'],
              }
            : {}),
        ...([
            'news',
            'events',
            'shoutouts',
            'celebrations',
            'resources',
            'people',
            'teams',
            'services',
        ].includes(type)
            ? { limit: 4 }
            : {}),
    };
}

export function blockSpan(
    section: HubSection,
    block: HubBlock,
    index: number,
): number {
    if (block.width) return Math.max(1, Math.min(12, block.width));
    if (section.columns === 2 && section.layout === 'wide-left')
        return index % 2 === 0 ? 8 : 4;
    if (section.columns === 2 && section.layout === 'wide-right')
        return index % 2 === 0 ? 4 : 8;
    return 12 / section.columns;
}

/** Reorder within a section or move across sections; unknown targets are ignored. */
export function moveBlock(
    sections: HubSection[],
    id: string,
    destinationId: string,
    targetIndex: number,
): HubSection[] {
    const source = sections.find((s) => s.blocks.some((b) => b.id === id));
    const destination = sections.find((s) => s.id === destinationId);
    if (
        !source ||
        !destination ||
        (source.id !== destinationId && destination.blocks.length >= 12)
    )
        return sections;
    const block = source.blocks.find((b) => b.id === id)!;
    return sections.map((section) => {
        if (section.id !== source.id && section.id !== destinationId)
            return section;
        const blocks = section.blocks.filter((b) => b.id !== id);
        if (section.id === destinationId)
            blocks.splice(
                Math.max(0, Math.min(targetIndex, blocks.length)),
                0,
                block,
            );
        return { ...section, blocks };
    });
}

export function duplicateSection(section: HubSection): HubSection {
    return {
        ...structuredClone(section),
        id: crypto.randomUUID(),
        title: section.title ? `${section.title} copy` : undefined,
        blocks: section.blocks.map((block) => ({
            ...structuredClone(block),
            id: crypto.randomUUID(),
        })),
    };
}

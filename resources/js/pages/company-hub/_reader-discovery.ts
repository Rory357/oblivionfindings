import type { HubPageSummary } from './_types';

const text = (value: string) => value.trim().toLocaleLowerCase();
export function pageMatches(page: HubPageSummary, query: string): boolean {
    const haystack = text(
        [
            page.title,
            page.summary,
            page.category,
            page.author,
            page.owner,
            page.collection,
            page.resource_type,
            ...(page.topics ?? []),
            ...(page.team_names ?? []),
        ]
            .filter(Boolean)
            .join(' '),
    );
    return text(query)
        .split(/\s+/)
        .filter(Boolean)
        .every((word) => haystack.includes(word));
}

/** Rank only metadata supplied for the reader's authorised published pages. */
export function pageRelevance(page: HubPageSummary, query: string): number {
    const phrase = text(query);
    if (!phrase) return 0;
    const fields: Array<[string, number]> = [
        [page.title, 60],
        [
            [
                ...(page.topics ?? []),
                ...(page.team_names ?? []),
                page.author,
                page.owner,
            ]
                .filter(Boolean)
                .join(' '),
            20,
        ],
        [page.summary, 10],
        [
            [page.category, page.collection, page.resource_type]
                .filter(Boolean)
                .join(' '),
            5,
        ],
    ];
    return fields.reduce(
        (score, [value, weight]) =>
            score +
            (text(value).includes(phrase) ? weight : 0) +
            phrase.split(/\s+/).filter((word) => text(value).includes(word))
                .length,
        0,
    );
}

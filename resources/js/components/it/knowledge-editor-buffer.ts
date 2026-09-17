type Scope = { audience?: string; site_scope?: number[] | null };
type Entry = {
    data: unknown;
    scopes: Scope[];
    linksChanged: boolean;
    tab: string;
    savedAt: number;
};
// Unsaved text stays in this page session's memory. Never write it to browser storage.
const buffers = new Map<string, Entry>();
let currentActor: number | null = null;
const key = (actorId: number, articleId: number) => `${actorId}:${articleId}`;
const bindActor = (actorId: number) => {
    if (currentActor !== actorId) buffers.clear();
    currentActor = actorId;
};
export function readKnowledgeBuffer<T>(actorId: number, articleId: number) {
    bindActor(actorId);
    const entry = buffers.get(key(actorId, articleId));
    if (!entry) return null;
    if (Date.now() - entry.savedAt > 30 * 60 * 1000) {
        buffers.delete(key(actorId, articleId));
        return null;
    }
    return structuredClone(entry) as Omit<Entry, 'data'> & { data: T };
}
export function saveKnowledgeBuffer<T>(
    actorId: number,
    articleId: number,
    data: T,
    scopes: Scope[],
    linksChanged: boolean,
    tab: string,
) {
    bindActor(actorId);
    buffers.delete(key(actorId, articleId));
    buffers.set(
        key(actorId, articleId),
        structuredClone({
            data,
            scopes,
            linksChanged,
            tab,
            savedAt: Date.now(),
        }),
    );
    while (buffers.size > 3) buffers.delete(buffers.keys().next().value!);
}
export function dropKnowledgeBuffer(actorId: number, articleId: number) {
    buffers.delete(key(actorId, articleId));
}

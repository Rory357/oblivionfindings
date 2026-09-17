/** Native links and normal visits carry the same bounded library context. */
function libraryContext(origin: string) {
    const source = new URL(origin, 'https://local.invalid');
    const query = source.searchParams.has('library')
        ? new URLSearchParams(source.searchParams.get('library') ?? '')
        : source.searchParams;
    const context = new URLSearchParams();
    for (const key of [
        'q',
        'category',
        'status',
        'document_type',
        'tag',
        'review',
        'owner',
        'sort',
        'dir',
        'page',
        'related_type',
        'related_id',
        'view',
        'list_view',
    ]) {
        const value = query.get(key);
        if (value) context.set(key, value);
    }
    return context;
}

export function knowledgeDocumentHref(
    id: number,
    origin: string,
    edit = false,
) {
    const context = libraryContext(origin);
    const params = new URLSearchParams();
    if (context.size) params.set('library', context.toString());
    if (edit) params.set('edit', '1');
    return `/it/knowledge/${id}${params.size ? `?${params}` : ''}`;
}

export function knowledgeFileHref(href: string, origin: string) {
    const file = new URL(href, 'https://local.invalid');
    const context = libraryContext(origin);
    if (context.size) file.searchParams.set('library', context.toString());
    return `${file.pathname}${file.search}`;
}

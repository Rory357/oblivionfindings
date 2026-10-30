export type PdfMatch = {
    page: number;
    offset: number;
    length: number;
    excerpt: string;
};

export function pdfMatches(
    text: string,
    query: string,
    page: number,
): PdfMatch[] {
    const needle = query.trim();
    if (!needle) return [];
    const expression = new RegExp(
        needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
        'giu',
    );
    const found: PdfMatch[] = [];
    for (const match of text.matchAll(expression)) {
        const offset = match.index,
            length = match[0].length;
        found.push({
            page,
            offset,
            length,
            excerpt: text.slice(Math.max(0, offset - 45), offset + length + 85),
        });
    }
    return found;
}

/** DOM text nodes keep PDF text inert; original positioned spans stay intact. */
export function highlightPdfText(
    divs: HTMLElement[],
    strings: string[],
    matches: PdfMatch[],
    selected?: PdfMatch,
) {
    let offset = 0;
    divs.forEach((div, index) => {
        const value = strings[index] ?? '',
            end = offset + value.length;
        const pieces = matches.filter(
            (match) =>
                match.offset < end && match.offset + match.length > offset,
        );
        div.replaceChildren();
        let cursor = 0;
        for (const match of pieces) {
            const start = Math.max(0, match.offset - offset),
                finish = Math.min(
                    value.length,
                    match.offset + match.length - offset,
                );
            div.append(document.createTextNode(value.slice(cursor, start)));
            const mark = document.createElement('mark');
            mark.textContent = value.slice(start, finish);
            mark.className =
                selected?.offset === match.offset
                    ? 'hub-pdf-match-current'
                    : 'hub-pdf-match';
            div.append(mark);
            cursor = finish;
        }
        div.append(document.createTextNode(value.slice(cursor)));
        offset = end + 1;
    });
}

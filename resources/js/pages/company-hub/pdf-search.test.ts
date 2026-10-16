import { expect, it } from 'vitest';
import { highlightPdfText, pdfMatches } from './_pdf-search';

it('finds every occurrence on a page, preserving offsets and case', () => {
    expect(
        pdfMatches('Care matters. CARE matters.', ' care ', 3).map((match) => [
            match.page,
            match.offset,
            match.length,
        ]),
    ).toEqual([
        [3, 0, 4],
        [3, 14, 4],
    ]);
    expect(pdfMatches('Anything', ' ', 1)).toEqual([]);
    expect(pdfMatches('İ Care', 'care', 1)[0]).toMatchObject({
        offset: 2,
        length: 4,
    });
    expect(
        pdfMatches('A [care]. B [CARE].', '[care].', 1).map(
            (match) => match.offset,
        ),
    ).toEqual([2, 12]);
});
it('highlights a phrase across positioned PDF spans without treating text as markup', () => {
    const strings = ['safe <script>', 'care matters', 'care'];
    const spans = strings.map(() => document.createElement('span'));
    const matches = pdfMatches(strings.join(' '), 'care', 1);
    highlightPdfText(spans, strings, matches, matches[1]);
    expect(spans[0].textContent).toBe('safe <script>');
    expect(spans[0].querySelector('script')).toBeNull();
    expect(spans[1].querySelector('mark')?.textContent).toBe('care');
    expect(spans[2].querySelector('.hub-pdf-match-current')?.textContent).toBe(
        'care',
    );
    const phrase = pdfMatches(strings.join(' '), 'matters care', 1);
    highlightPdfText(spans, strings, phrase, phrase[0]);
    expect(spans[1].querySelector('mark')?.textContent).toBe('matters');
    expect(spans[2].querySelector('mark')?.textContent).toBe('care');
    highlightPdfText(spans, strings, []);
    expect(spans.map((span) => span.textContent)).toEqual(strings);
});

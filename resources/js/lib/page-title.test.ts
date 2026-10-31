import { expect, it } from 'vitest';
import { formatPageTitle, ownTitle } from './page-title';

it('suffixes app pages with the app name and leaves the public site’s own titles alone', () => {
    expect(formatPageTitle('Clients', 'Oblivion Care')).toBe(
        'Clients - Oblivion Care',
    );
    expect(formatPageTitle('', 'Oblivion Care')).toBe('Oblivion Care');
    expect(
        formatPageTitle(
            ownTitle('About us · Oblivion Living'),
            'Oblivion Care',
        ),
    ).toBe('About us · Oblivion Living');
});

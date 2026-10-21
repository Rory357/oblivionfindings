import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { LaravelPagination } from './laravel-pagination';

const get = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { get } }));

afterEach(() => {
    cleanup();
    get.mockReset();
});

const links = [
    { url: null, label: '&laquo; Previous', active: false },
    { url: '/emar/mar?site_id=3&page=1', label: '1', active: true },
    { url: '/emar/mar?site_id=3&page=2', label: '2', active: false },
    { url: '/emar/mar?site_id=3&page=2', label: 'Next &raquo;', active: false },
];

describe('LaravelPagination', () => {
    it('visits the link with an Inertia visit by default', () => {
        render(<LaravelPagination links={links} lastPage={2} preserveScroll />);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(get).toHaveBeenCalledWith(
            '/emar/mar?site_id=3&page=2',
            {},
            { preserveState: true, preserveScroll: true },
        );
    });

    it('passes the page number to onPage instead of visiting', () => {
        const onPage = vi.fn();
        render(<LaravelPagination links={links} lastPage={2} onPage={onPage} />);
        fireEvent.click(screen.getByRole('button', { name: '2' }));
        expect(onPage).toHaveBeenCalledWith(2);
        expect(get).not.toHaveBeenCalled();
    });

    it('renders nothing on one page', () => {
        const { container } = render(
            <LaravelPagination links={links} lastPage={1} />,
        );
        expect(container).toBeEmptyDOMElement();
    });
});

import RemoteSupport, {
    type RemoteSupportProps,
} from '@/pages/it/remote-support/index';
import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { scriptIndex } from './_contracts';
import ScriptCatalogue from './index';

const sharedAuth = vi.hoisted(() => ({ actor: null as number | null }));
vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), isAxiosError: () => false },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth:
                sharedAuth.actor === null
                    ? null
                    : { user: { id: sharedAuth.actor } },
        },
    }),
    Head: () => null,
    Link: ({
        children,
        href,
        onClick,
    }: {
        children: ReactNode;
        href: string;
        onClick?: () => void;
    }) => (
        <a href={href} onClick={onClick}>
            {children}
        </a>
    ),
    router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
}));
const corpus = JSON.parse(
    readFileSync(
        process.env.ENDPOINT_SCRIPT_HTTP_CORPUS ??
            'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
beforeEach(() => {
    vi.clearAllMocks();
    sharedAuth.actor = corpus.index_pending.actor_id;
    vi.stubGlobal('crypto', webcrypto);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
function matchingPage(page: ReturnType<typeof scriptIndex>, actor: number) {
    return {
        ...structuredClone(page),
        actor_id: actor,
        identity_confirm_href:
            '/security-devices/scripts/confirm-identity?expected_actor_id=' +
            actor,
        pagination: {
            ...page.pagination,
            reset_href:
                '/security-devices/scripts?expected_actor_id=' +
                actor +
                '&status=all&search=',
        },
    };
}
function startReview() {
    const table = screen.getByRole('table');
    fireEvent.click(within(table).getAllByRole('row')[1]);
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
}
describe('catalogue workspace privacy and draft boundaries', () => {
    it.each(['different', 'missing'])(
        'clears private upload drafts when only shared auth becomes %s',
        (mode) => {
            const page = scriptIndex(corpus.index_pending);
            const rendered = render(<ScriptCatalogue {...page} />);
            fireEvent.click(screen.getByRole('button', { name: 'Add script' }));
            fireEvent.change(screen.getByLabelText('Script name'), {
                target: { value: 'Private actor A draft' },
            });
            sharedAuth.actor = mode === 'missing' ? null : page.actor_id + 10;
            rendered.rerender(<ScriptCatalogue {...page} />);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(screen.queryByRole('table')).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Add script' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText(page.scripts[0].manifest.name),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Load current catalogue' }),
            );
            expect(router.visit).toHaveBeenLastCalledWith(
                '/security-devices/scripts',
            );
            sharedAuth.actor = page.actor_id + 10;
            rendered.rerender(
                <ScriptCatalogue {...matchingPage(page, sharedAuth.actor)} />,
            );
            fireEvent.click(screen.getByRole('button', { name: 'Add script' }));
            expect(screen.getByLabelText('Script name')).toHaveValue('');
        },
    );
    it.each(['different', 'missing'])(
        'aborts pending private material when only shared auth becomes %s',
        async (mode) => {
            const page = scriptIndex(corpus.index_pending);
            let resolve: (value: unknown) => void = () => {};
            vi.mocked(axios.get)
                .mockResolvedValueOnce({
                    data: { actor_id: page.actor_id, script: page.scripts[0] },
                })
                .mockImplementationOnce(
                    () =>
                        new Promise((done) => {
                            resolve = done;
                        }),
                );
            const rendered = render(<ScriptCatalogue {...page} />);
            fireEvent.click(
                within(screen.getByRole('table')).getAllByRole('row')[1],
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Read and verify script' }),
            );
            await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
            const options = vi.mocked(axios.get).mock.calls[1][1];
            sharedAuth.actor = mode === 'missing' ? null : page.actor_id + 10;
            rendered.rerender(<ScriptCatalogue {...page} />);
            expect(options?.signal?.aborted).toBe(true);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            await act(async () => resolve({ data: corpus.material }));
            expect(
                screen.queryByLabelText('Verified script content'),
            ).not.toBeInTheDocument();
            expect(screen.queryByRole('table')).not.toBeInTheDocument();
            sharedAuth.actor = page.actor_id + 10;
            rendered.rerender(
                <ScriptCatalogue {...matchingPage(page, sharedAuth.actor)} />,
            );
            startReview();
            expect(
                screen.getByLabelText('Reason for this decision'),
            ).toHaveValue('');
            expect(
                screen.getByRole('button', { name: 'Approve this version' }),
            ).toBeDisabled();
        },
    );
    it('removes already verified code and review notes on a shared-auth-only change', async () => {
        const page = scriptIndex(corpus.index_pending);
        vi.mocked(axios.get)
            .mockResolvedValueOnce({
                data: { actor_id: page.actor_id, script: page.scripts[0] },
            })
            .mockResolvedValueOnce({ data: corpus.material });
        const rendered = render(<ScriptCatalogue {...page} />);
        fireEvent.click(
            within(screen.getByRole('table')).getAllByRole('row')[1],
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Read and verify script' }),
        );
        await screen.findByLabelText('Verified script content');
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        fireEvent.change(screen.getByLabelText('Reason for this decision'), {
            target: { value: 'Private actor A review notes' },
        });
        sharedAuth.actor = page.actor_id + 10;
        rendered.rerender(<ScriptCatalogue {...page} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Reason for this decision'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Verified script content'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Approve this version' }),
        ).not.toBeInTheDocument();
        rendered.rerender(
            <ScriptCatalogue {...matchingPage(page, sharedAuth.actor)} />,
        );
        startReview();
        expect(screen.getByLabelText('Reason for this decision')).toHaveValue(
            '',
        );
    });
    it('shows a current-actor catalogue link without requiring a selected computer', () => {
        const props: RemoteSupportProps = {
            actor_id: 43,
            can_manage_remote_policy: false,
            can_view_script_catalogue: true,
            computers: { data: [], total: 0, links: [] },
            selected: null,
            ticket: null,
            filters: { q: '' },
            summary: { computers: 0, endpoints: 0, servers: 0 },
            availability: { remote_support: false, obli: false },
        };
        const rendered = render(<RemoteSupport {...props} />);
        expect(
            screen.getByRole('link', { name: 'Script catalogue' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/scripts?expected_actor_id=43',
        );
        rendered.rerender(
            <RemoteSupport
                {...{ ...props, can_view_script_catalogue: false }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'Script catalogue' }),
        ).not.toBeInTheDocument();
    });
    it('removes an upload draft when its current upload permission is withdrawn', () => {
        const page = scriptIndex(corpus.index_pending);
        const rendered = render(<ScriptCatalogue {...page} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add script' }));
        fireEvent.change(screen.getByLabelText('Script name'), {
            target: { value: 'Private draft' },
        });
        rendered.rerender(
            <ScriptCatalogue {...{ ...page, can_upload: false }} />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add script' }),
        ).not.toBeInTheDocument();
        rendered.rerender(<ScriptCatalogue {...page} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add script' }));
        expect(screen.getByLabelText('Script name')).toHaveValue('');
    });
    it('retains notes when closing and reopening the same review', () => {
        render(<ScriptCatalogue {...scriptIndex(corpus.index_pending)} />);
        startReview();
        fireEvent.change(screen.getByLabelText('Reason for this decision'), {
            target: { value: 'Keep my original review notes' },
        });
        fireEvent.click(screen.getAllByRole('button', { name: /^Close$/ })[0]);
        fireEvent.click(
            within(screen.getByRole('table')).getAllByRole('row')[1],
        );
        expect(screen.getByLabelText('Reason for this decision')).toHaveValue(
            'Keep my original review notes',
        );
    });
    it('clears the previous review when the signed-in actor changes', () => {
        const page = scriptIndex(corpus.index_pending);
        const rendered = render(<ScriptCatalogue {...page} />);
        startReview();
        fireEvent.change(screen.getByLabelText('Reason for this decision'), {
            target: { value: 'Private previous actor notes' },
        });
        const changed = {
            ...page,
            actor_id: page.actor_id + 10,
            identity_confirm_href:
                '/security-devices/scripts/confirm-identity?expected_actor_id=' +
                (page.actor_id + 10),
            pagination: {
                ...page.pagination,
                reset_href:
                    '/security-devices/scripts?expected_actor_id=' +
                    (page.actor_id + 10) +
                    '&status=all&search=',
            },
        };
        sharedAuth.actor = changed.actor_id;
        rendered.rerender(<ScriptCatalogue {...changed} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        startReview();
        expect(screen.getByLabelText('Reason for this decision')).toHaveValue(
            '',
        );
    });
    it('removes the review when refreshed scope makes the document invalid', () => {
        const page = scriptIndex(corpus.index_pending);
        const rendered = render(<ScriptCatalogue {...page} />);
        startReview();
        rendered.rerender(
            <ScriptCatalogue
                {...{
                    ...page,
                    identity_confirm_href: 'https://elsewhere.invalid',
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Reload catalogue' }),
        ).toBeVisible();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
});

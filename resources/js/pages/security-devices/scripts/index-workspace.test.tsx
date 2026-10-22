import { router } from '@inertiajs/react';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { scriptIndex } from './_contracts';
import ScriptCatalogue from './index';

// The upload wizard has its own tests; here a stand-in reports which dialog
// instance is mounted, so a fresh upload (new state, new request key) shows.
const uploads = vi.hoisted(() => ({ mounted: 0 }));
const sharedAuth = vi.hoisted(() => ({ actor: null as number | null }));
vi.mock('./_upload', async () => {
    const { useState } = await import('react');
    const { Button } = await import('@/components/ui/button');
    return {
        ScriptUploadDialog: ({
            open,
            onClose,
            onUploaded,
            onAddAnother,
        }: {
            open: boolean;
            onClose: () => void;
            onUploaded: (row: unknown) => void;
            onAddAnother?: () => void;
        }) => {
            const [instance] = useState(() => ++uploads.mounted);
            return open ? (
                <div role="dialog" aria-label="Add script">
                    <p>Upload instance {instance}</p>
                    <Button onClick={() => onUploaded(null)}>
                        Finish upload
                    </Button>
                    <Button onClick={onClose}>Close upload</Button>
                    {onAddAnother && (
                        <Button onClick={onAddAnother}>Add another</Button>
                    )}
                </div>
            ) : null;
        },
    };
});
vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), isAxiosError: () => false },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs = [],
    }: {
        children: ReactNode;
        breadcrumbs?: { title: string }[];
    }) => (
        <>
            <nav aria-label="Breadcrumb">
                {breadcrumbs.map((crumb) => crumb.title).join(' › ')}
            </nav>
            {children}
        </>
    ),
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
        'aria-label': label,
    }: {
        children: ReactNode;
        href: string;
        onClick?: () => void;
        'aria-label'?: string;
    }) => (
        <a href={href} onClick={onClick} aria-label={label}>
            {children}
        </a>
    ),
    router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
}));
const corpus = JSON.parse(
    readFileSync(
        'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
const page = () => scriptIndex(structuredClone(corpus.index_pending));
const trail = 'Home › IT & Support › Remote support › Approved scripts';
beforeEach(() => {
    vi.clearAllMocks();
    uploads.mounted = 0;
    sharedAuth.actor = corpus.index_pending.actor_id;
    vi.stubGlobal('crypto', webcrypto);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const addScript = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Add script' }));
const finish = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Finish upload' }));
describe('catalogue uploads, notices and header counts', () => {
    it('starts a fresh upload after a saved one and clears the saved notice', () => {
        render(<ScriptCatalogue {...page()} />);
        addScript();
        expect(screen.getByText('Upload instance 1')).toBeVisible();
        finish();
        expect(screen.getByText(/Script draft saved/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Close upload' }));
        addScript();
        expect(screen.getByText('Upload instance 2')).toBeVisible();
        expect(screen.queryByText(/Script draft saved/)).toBeNull();
    });
    it('reopens an unfinished upload as it was left', () => {
        render(<ScriptCatalogue {...page()} />);
        addScript();
        fireEvent.click(screen.getByRole('button', { name: 'Close upload' }));
        addScript();
        expect(screen.getByText('Upload instance 1')).toBeVisible();
    });
    it('offers another separate upload from the saved pane', () => {
        render(<ScriptCatalogue {...page()} />);
        addScript();
        finish();
        fireEvent.click(screen.getByRole('button', { name: 'Add another' }));
        expect(screen.getByText('Upload instance 2')).toBeVisible();
    });
    it('dismisses the saved notice, and clears it once a refresh succeeds', () => {
        render(<ScriptCatalogue {...page()} />);
        addScript();
        finish();
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(screen.queryByText(/Script draft saved/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Close upload' }));
        addScript();
        finish();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh catalogue' }),
        );
        const options = vi.mocked(router.reload).mock.calls[0][0] as {
            onSuccess: () => void;
        };
        expect(screen.getByText(/Script draft saved/)).toBeVisible();
        act(() => options.onSuccess());
        expect(screen.queryByText(/Script draft saved/)).toBeNull();
    });
    it('updates header counts from refreshed props even when the visible rows are unchanged', () => {
        const first = page();
        const rendered = render(<ScriptCatalogue {...first} />);
        expect(
            screen.getByRole('link', { name: 'View draft' }),
        ).toHaveTextContent('0');
        addScript();
        rendered.rerender(
            <ScriptCatalogue
                {...{ ...first, summary: { ...first.summary, uploaded: 5 } }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'View draft' }),
        ).toHaveTextContent('5');
        // Same rows: the workspace (and its open upload) stayed mounted.
        expect(screen.getByText('Upload instance 1')).toBeVisible();
    });
    it('keeps the Home-rooted trail back to Remote support, also when sign-in has changed', () => {
        const rendered = render(<ScriptCatalogue {...page()} />);
        expect(
            screen.getByRole('navigation', { name: 'Breadcrumb' }),
        ).toHaveTextContent(trail);
        sharedAuth.actor = corpus.index_pending.actor_id + 10;
        rendered.rerender(<ScriptCatalogue {...page()} />);
        expect(
            screen.getByRole('button', { name: 'Load current catalogue' }),
        ).toBeVisible();
        expect(
            screen.getByRole('navigation', { name: 'Breadcrumb' }),
        ).toHaveTextContent(trail);
    });
});

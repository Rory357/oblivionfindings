/* Mockup-only stand-in for '@inertiajs/react' (aliased in vite.config.mjs).
 *
 * The app's real components import Link / router / usePage / useForm. In this
 * synthetic preview there is no Inertia server, so:
 *   - Link renders a real <a> and turns a click into this preview's hash route;
 *   - router.visit / get navigate the same way; post / put / delete never send
 *     anything (the preview is read-only and has no application API);
 *   - usePage returns synthetic shared props for the signed-in mockup persona.
 * Nothing here changes a shared component's code or behaviour contract. */
import {
    forwardRef,
    useCallback,
    useState,
    type AnchorHTMLAttributes,
    type MouseEvent,
    type ReactNode,
} from 'react';

type Href = string | { url: string };
const hrefOf = (href: Href | undefined) =>
    typeof href === 'string' ? href : (href?.url ?? '#');

/** App URL → preview hash route. Preview routes are the app paths themselves. */
export function toHash(url: string): string {
    if (url.startsWith('#')) return url;
    try {
        const u = new URL(url, 'http://preview.local');
        return `#${u.pathname}${u.search}`;
    } catch {
        return '#/';
    }
}

export function visit(url: string) {
    const next = toHash(url);
    if (window.location.hash !== next) window.location.hash = next;
    else window.dispatchEvent(new HashChangeEvent('hashchange'));
}

export type InertiaLinkProps = Omit<
    AnchorHTMLAttributes<HTMLAnchorElement>,
    'href'
> & {
    href: Href;
    method?: string;
    as?: string;
    data?: unknown;
    preserveState?: boolean;
    preserveScroll?: boolean;
    replace?: boolean;
    only?: string[];
    prefetch?: boolean | string | string[];
    children?: ReactNode;
};

export const Link = forwardRef<HTMLAnchorElement, InertiaLinkProps>(
    function Link(
        {
            href,
            onClick,
            method: _method,
            as: _as,
            data: _data,
            preserveState: _ps,
            preserveScroll: _pscroll,
            replace: _replace,
            only: _only,
            prefetch: _prefetch,
            children,
            ...rest
        },
        ref,
    ) {
        const url = hrefOf(href);
        return (
            <a
                ref={ref}
                href={toHash(url)}
                {...rest}
                onClick={(e: MouseEvent<HTMLAnchorElement>) => {
                    onClick?.(e);
                    if (e.defaultPrevented) return;
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0)
                        return;
                    e.preventDefault();
                    visit(url);
                }}
            >
                {children}
            </a>
        );
    },
);

const noSend = (what: string) => {
    window.dispatchEvent(
        new CustomEvent('preview:toast', {
            detail: `${what} is outside this synthetic preview — nothing was sent.`,
        }),
    );
};

/* P09: the extra (ignored) parameters let the shared app shell, pagination and
 * the report builder type-check against this shim; behaviour is unchanged. */
export const router = {
    visit: (url: string, ..._options: unknown[]) => visit(url),
    get: (url: string, ..._options: unknown[]) => visit(url),
    /** P09: the report builder’s workspace pushes its view into the URL. */
    push: (o: { url: string; [key: string]: unknown }) => visit(o.url),
    reload: (..._options: unknown[]) => window.dispatchEvent(new HashChangeEvent('hashchange')),
    post: (..._args: unknown[]) => noSend('Saving'),
    put: (..._args: unknown[]) => noSend('Saving'),
    patch: (..._args: unknown[]) => noSend('Saving'),
    delete: (..._args: unknown[]) => noSend('Deleting'),
    on: (..._args: unknown[]) => () => undefined,
    flushAll: () => undefined,
};

type SharedProps = Record<string, unknown> & {
    auth: { user: { id: number; name: string; email: string } };
};
let sharedProps: SharedProps = {
    auth: { user: { id: 1, name: 'Priya Shah', email: 'priya@example.test' } },
    calendarFeedUrl: null,
};
/** The preview updates the signed-in persona here when the viewer changes it. */
export function setSharedProps(next: SharedProps) {
    sharedProps = next;
}

export function usePage<T = SharedProps>() {
    return {
        props: sharedProps as unknown as T,
        url: window.location.hash.slice(1) || '/',
        component: 'Preview',
        version: null,
    };
}

export function useForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setDataState] = useState<T>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const setData = useCallback(
        (key: keyof T | T, value?: unknown) =>
            setDataState((d) =>
                typeof key === 'object'
                    ? (key as T)
                    : { ...d, [key as string]: value },
            ),
        [],
    );
    const send = () => noSend('Saving');
    return {
        data,
        setData,
        errors,
        hasErrors: Object.keys(errors).length > 0,
        processing: false,
        progress: null,
        wasSuccessful: false,
        recentlySuccessful: false,
        isDirty: false,
        transform: () => undefined,
        reset: () => setDataState(initial),
        clearErrors: () => setErrors({}),
        setError: (k: string, v: string) =>
            setErrors((e) => ({ ...e, [k]: v })),
        post: send,
        put: send,
        patch: send,
        delete: send,
        submit: send,
        cancel: () => undefined,
    };
}

export function Head(_: { title?: string; children?: ReactNode }) {
    return null;
}

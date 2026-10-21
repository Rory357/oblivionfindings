// Mockup-only stand-in for `@inertiajs/react`. Everything is the real package
// except the pieces that need a running Laravel app:
// - `usePage` serves fictional page props (Settings › Branding, locales,
//   errors), so the real `BrandLockup` and the real sign-in pages render;
// - `Head` sets the tab title (the real one needs the Inertia head manager);
// - `Link` turns app URLs into this preview's hash routes;
// - `useForm` and `router` keep form state locally and hand each submit to
//   the preview (`setMockSubmitHandler`) instead of sending a request.
// No request ever leaves the page.
import {
    createElement,
    forwardRef,
    useRef,
    useState,
    useSyncExternalStore,
    type AnchorHTMLAttributes,
    type MouseEvent,
    type ReactNode,
} from 'react';

export * from '@inertiajs/react/real';

export type MockBranding = {
    name: string | null;
    logoUrl: string | null;
    logoDarkUrl: string | null;
    /** The client's hero photo (mockup: the sign-in brand half shows it). */
    heroUrl?: string | null;
};

type MockProps = {
    branding: MockBranding;
    errors: Record<string, string>;
    flash: Record<string, string | null>;
    locale: string;
    availableLocales: Record<string, { label: string; native?: string }>;
};

let props: MockProps = {
    branding: { name: null, logoUrl: null, logoDarkUrl: null, heroUrl: null },
    errors: {},
    flash: {},
    locale: 'en',
    availableLocales: {
        en: { label: 'English (NZ)', native: 'English' },
        mi: { label: 'Māori', native: 'Te reo Māori' },
    },
};
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((listener) => listener());

export function setMockBranding(next: MockBranding) {
    props = { ...props, branding: next };
    emit();
}

export function setMockProps(next: Partial<MockProps>) {
    props = { ...props, ...next };
    emit();
}

export function usePage() {
    const current = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        () => props,
    );
    return {
        component: 'referrals-mockup-v2',
        url: window.location.hash,
        version: null,
        props: current,
    };
}

/* ── Head ─────────────────────────────────────────────────────────────── */

// The preview sets each tab title from its route (main.tsx), so the real
// pages' <Head> renders nothing (the real one needs the head manager).
export function Head(_props: { title?: string; children?: ReactNode }) {
    return null;
}

/* ── Link ─────────────────────────────────────────────────────────────── */

type Href = string | { url: string; method?: string };

const appRoutes: [RegExp, string][] = [
    [/^\/$/, '#/home'],
    [/^\/login(\?|$)/, '#/sign-in'],
    [/^\/portal\/login(\?|$)/, '#/sign-in/family'],
    [/^\/privacy(-policy)?(\?|$)/, '#/privacy'],
    [/^\/terms(\?|$)/, '#/terms'],
    [/^\/accessibility(\?|$)/, '#/accessibility'],
    [/^\/careers(\?|$)/, '#/careers'],
    [/^\/contact(\?|$)/, '#/contact'],
];

/** The preview route for an app URL, or null when the demo has no page for it. */
export function mockRoute(href: Href): string | null {
    const url = typeof href === 'string' ? href : href.url;
    if (url.startsWith('#')) return url;
    const path = url.replace(/^https?:\/\/[^/]+/, '');
    return appRoutes.find(([pattern]) => pattern.test(path))?.[1] ?? null;
}

let onUnmapped: (url: string) => void = () => undefined;

/** Called when a real component links to an app page this demo doesn't have. */
export function setMockLinkFallback(handler: (url: string) => void) {
    onUnmapped = handler;
}

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: Href;
    [inertiaOption: string]: unknown;
};

const INERTIA_OPTIONS = new Set([
    'method',
    'as',
    'data',
    'preserveScroll',
    'preserveState',
    'replace',
    'only',
    'except',
    'headers',
    'queryStringArrayFormat',
    'async',
    'prefetch',
    'cacheFor',
    'cacheTags',
    'viewTransition',
]);

export const Link = forwardRef<HTMLAnchorElement, LinkProps>(function Link(
    { href, onClick, children, ...rest },
    ref,
) {
    const target = mockRoute(href);
    const anchorProps = Object.fromEntries(
        Object.entries(rest).filter(([key]) => !INERTIA_OPTIONS.has(key)),
    );
    return createElement(
        'a',
        {
            ...anchorProps,
            ref,
            href: target ?? '#',
            onClick: (event: MouseEvent<HTMLAnchorElement>) => {
                onClick?.(event);
                if (target === null) {
                    event.preventDefault();
                    onUnmapped(typeof href === 'string' ? href : href.url);
                }
            },
        },
        children as ReactNode,
    );
});

/* ── Forms and router ─────────────────────────────────────────────────── */

export type MockRequest = {
    method: string;
    url: string;
    data: Record<string, unknown>;
};
export type MockResponse = { errors?: Record<string, string> } | void;

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
let submitHandler: (request: MockRequest) => Promise<MockResponse> = () =>
    wait(900);

/** The preview decides what each submit does (no request is sent). */
export function setMockSubmitHandler(
    handler: (request: MockRequest) => Promise<MockResponse>,
) {
    submitHandler = handler;
}

type VisitOptions = {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    [option: string]: unknown;
};

function visit(
    method: string,
    url: string,
    data: Record<string, unknown> = {},
    options: VisitOptions = {},
) {
    options.onStart?.();
    return submitHandler({ method, url, data }).then((response) => {
        if (response?.errors) options.onError?.(response.errors);
        else options.onSuccess?.();
        options.onFinish?.();
        return response;
    });
}

export const router = {
    visit: (url: string, options: VisitOptions & { method?: string } = {}) =>
        visit(options.method ?? 'get', url, {}, options),
    get: (
        url: string,
        data?: Record<string, unknown>,
        options?: VisitOptions,
    ) => visit('get', url, data, options),
    post: (
        url: string,
        data?: Record<string, unknown>,
        options?: VisitOptions,
    ) => visit('post', url, data, options),
    put: (
        url: string,
        data?: Record<string, unknown>,
        options?: VisitOptions,
    ) => visit('put', url, data, options),
    patch: (
        url: string,
        data?: Record<string, unknown>,
        options?: VisitOptions,
    ) => visit('patch', url, data, options),
    delete: (url: string, options?: VisitOptions) =>
        visit('delete', url, {}, options),
    reload: () => undefined,
    on: () => () => undefined,
};

type FormData = Record<string, unknown>;

export function useForm<T extends FormData>(initial: T) {
    const defaults = useRef(initial);
    const [data, setDataState] = useState<T>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const dataRef = useRef(data);
    dataRef.current = data;
    const transformRef = useRef<(value: T) => FormData>((value) => value);

    const setData = (
        key: keyof T | T | ((previous: T) => T),
        value?: unknown,
    ) => {
        if (typeof key === 'function') setDataState(key);
        else if (typeof key === 'object') setDataState(key as T);
        else setDataState((previous) => ({ ...previous, [key]: value }));
    };

    const submit =
        (method: string) =>
        (url: string, options: VisitOptions = {}) => {
            setProcessing(true);
            visit(method, url, transformRef.current(dataRef.current), {
                ...options,
                onSuccess: () => {
                    setErrors({});
                    options.onSuccess?.();
                },
                onError: (next) => {
                    setErrors(next);
                    options.onError?.(next);
                },
                onFinish: () => {
                    setProcessing(false);
                    options.onFinish?.();
                },
            });
        };

    return {
        data,
        setData,
        errors: errors as Partial<Record<keyof T, string>> &
            Record<string, string>,
        hasErrors: Object.keys(errors).length > 0,
        processing,
        progress: null,
        isDirty: JSON.stringify(data) !== JSON.stringify(defaults.current),
        wasSuccessful: false,
        recentlySuccessful: false,
        setError: (key: string | Record<string, string>, message?: string) =>
            setErrors((previous) =>
                typeof key === 'object'
                    ? { ...previous, ...key }
                    : { ...previous, [key]: message ?? '' },
            ),
        clearErrors: (...keys: string[]) =>
            setErrors((previous) =>
                keys.length === 0
                    ? {}
                    : Object.fromEntries(
                          Object.entries(previous).filter(
                              ([key]) => !keys.includes(key),
                          ),
                      ),
            ),
        reset: (...keys: (keyof T)[]) =>
            setDataState((previous) =>
                keys.length === 0
                    ? defaults.current
                    : {
                          ...previous,
                          ...Object.fromEntries(
                              keys.map((key) => [key, defaults.current[key]]),
                          ),
                      },
            ),
        transform: (callback: (value: T) => FormData) => {
            transformRef.current = callback;
        },
        defaults: () => undefined,
        cancel: () => undefined,
        get: submit('get'),
        post: submit('post'),
        put: submit('put'),
        patch: submit('patch'),
        delete: submit('delete'),
        submit: (method: string, url: string, options?: VisitOptions) =>
            submit(method)(url, options),
    };
}

import { InertiaLinkProps } from '@inertiajs/react';
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function isSameUrl(
    url1: NonNullable<InertiaLinkProps['href']> | null | undefined,
    url2: NonNullable<InertiaLinkProps['href']> | null | undefined,
) {
    return resolveUrl(url1) === resolveUrl(url2);
}

export function resolveUrl(
    url: NonNullable<InertiaLinkProps['href']> | null | undefined,
): string {
    if (!url) {
        return '';
    }

    return typeof url === 'string' ? url : url.url;
}

import { formatDateTimeLong } from '@/lib/datetime';

export const REGISTER = '/fleet-assets/asset-register';

export class RegisterError extends Error {
    constructor(
        message: string,
        public status: number,
    ) {
        super(message);
    }
}

export async function api<T>(
    path: string,
    method = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const csrf = document.cookie
        .split('; ')
        .find((v) => v.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');
    const form = body instanceof FormData;
    const response = await fetch(
        path.startsWith('/fleet-assets/')
            ? path
            : `${REGISTER}/${path.replace(/^\/+/, '')}`,
        {
            method,
            credentials: 'same-origin',
            signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
                ...(!form && body
                    ? { 'Content-Type': 'application/json' }
                    : {}),
            },
            body: form ? body : body ? JSON.stringify(body) : undefined,
        },
    );
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const errors = data.errors
            ? Object.values(data.errors).flat().join(' ')
            : '';
        throw new RegisterError(
            errors ||
                data.message ||
                (response.status === 419
                    ? 'Your session expired. Reload and sign in again.'
                    : 'Could not save. Your last saved work is safe; try again.'),
            response.status,
        );
    }
    if (response.headers.get('Content-Type')?.includes('text/html')) {
        throw new RegisterError(
            'Your session expired. Reload and sign in again.',
            419,
        );
    }
    return response.json() as Promise<T>;
}

export async function download(
    path: string,
    filename: string,
    signal?: AbortSignal,
) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
    });
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new RegisterError(
            data.message || 'The export could not be generated. Try again.',
            response.status,
        );
    }
    const expected = filename.endsWith('.pdf')
        ? 'application/pdf'
        : filename.endsWith('.xlsx')
          ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          : 'application/zip';
    if (!response.headers.get('Content-Type')?.includes(expected)) {
        throw new RegisterError(
            'The export did not return a valid file. Reload and try again.',
            502,
        );
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 10000);
}

export type Option = { id: number; name: string };
export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
export const stamp = (value?: string | null) =>
    formatDateTimeLong(value, 'Not recorded');

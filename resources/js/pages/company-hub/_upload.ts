import { HubRequestError } from './_api';

export const hubDocumentAccept =
    '.pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.webp,.gif';
export const hubDocumentHint =
    'PDF, Word, Excel, PowerPoint or images · up to 10 MB each';
export function fileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
export function documentFileError(file: File): string | null {
    if (!file.size) return 'This file is empty. Choose a file with content.';
    if (file.size > 10 * 1024 * 1024)
        return 'Choose a file no larger than 10 MB.';
    if (!/\.(pdf|docx|xlsx|pptx|jpe?g|png|webp|gif)$/i.test(file.name))
        return 'Choose a PDF, Word, Excel, PowerPoint or supported image file.';
    return null;
}

/** Upload progress covers transfer only; completion waits for the server receipt. */
export function uploadHubFile<T>(
    url: string,
    form: FormData,
    signal: AbortSignal,
    onProgress: (progress: number) => void,
): Promise<T> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const abort = () => request.abort();
        const finish = () => signal.removeEventListener('abort', abort);
        request.open('POST', url);
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader(
            'X-CSRF-TOKEN',
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '',
        );
        request.upload.onprogress = (event) => {
            if (event.lengthComputable)
                onProgress(
                    Math.min(
                        100,
                        Math.round((event.loaded / event.total) * 100),
                    ),
                );
        };
        request.onload = () => {
            finish();
            let result: Record<string, unknown>;
            try {
                result = JSON.parse(request.responseText);
            } catch {
                reject(
                    new Error(
                        'The upload could not be confirmed. Check your library before retrying.',
                    ),
                );
                return;
            }
            if (request.status < 200 || request.status >= 300) {
                const errors =
                    result.errors && typeof result.errors === 'object'
                        ? Object.values(result.errors)
                              .flat()
                              .filter((item) => typeof item === 'string')
                              .join(' ')
                        : '';
                reject(
                    new HubRequestError(
                        errors ||
                            (typeof result.message === 'string'
                                ? result.message
                                : 'The upload could not be completed.'),
                        request.status,
                    ),
                );
            } else resolve(result as T);
        };
        request.onerror = () => {
            finish();
            reject(
                new Error(
                    'The connection was interrupted. The server may have received the file.',
                ),
            );
        };
        request.onabort = () => {
            finish();
            reject(
                new DOMException(
                    'Upload stopped. The server may already have received the file.',
                    'AbortError',
                ),
            );
        };
        signal.addEventListener('abort', abort, { once: true });
        if (signal.aborted) {
            finish();
            reject(new DOMException('Upload cancelled.', 'AbortError'));
            return;
        }
        request.send(form);
    });
}

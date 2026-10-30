import { isJsonObject } from './record-command';

const hasId = (
    value: unknown,
): value is Record<string, unknown> & { id: number } =>
    isJsonObject(value) &&
    Number.isSafeInteger(value.id) &&
    Number(value.id) > 0;

type SavedFile = { id: number; name: string; state: string; archived: boolean };
const isFile = (value: unknown): value is SavedFile =>
    hasId(value) &&
    typeof value.name === 'string' &&
    typeof value.state === 'string' &&
    !!value.state &&
    typeof value.archived === 'boolean';

export const isReviewFileResponse = (
    value: unknown,
): value is { file: SavedFile } => isJsonObject(value) && isFile(value.file);

export const isReviewFilesResponse = (
    value: unknown,
): value is { files: SavedFile[] } =>
    isJsonObject(value) &&
    Array.isArray(value.files) &&
    value.files.length > 0 &&
    value.files.every(isFile);

export const isFinanceLinkResponse = (
    value: unknown,
): value is { link: { id: number } } =>
    isJsonObject(value) && hasId(value.link);

export const isFinanceLinksResponse = (
    value: unknown,
): value is { links: Array<{ id: number }> } =>
    isJsonObject(value) &&
    Array.isArray(value.links) &&
    value.links.every(hasId);

type ReviewRequestResult = {
    request: {
        id: number;
        reference: string;
        type_label: string;
        status: string;
        lock_version: number;
    };
};

/** Retain the command identity until the server returns the saved review record. */
export const isReviewRequestResponse = (
    value: unknown,
): value is ReviewRequestResult => {
    if (!isJsonObject(value) || !isJsonObject(value.request)) return false;
    const request = value.request;
    return (
        Number.isSafeInteger(request.id) &&
        Number(request.id) > 0 &&
        typeof request.reference === 'string' &&
        !!request.reference &&
        typeof request.type_label === 'string' &&
        typeof request.status === 'string' &&
        [
            'preparing',
            'submitted',
            'changes_requested',
            'resolved',
            'declined',
        ].includes(request.status) &&
        Number.isSafeInteger(request.lock_version) &&
        Number(request.lock_version) > 0
    );
};

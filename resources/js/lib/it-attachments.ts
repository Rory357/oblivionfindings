const allowedExtensions = [
    'jpg',
    'jpeg',
    'png',
    'webp',
    'gif',
    'heic',
    'pdf',
    'txt',
    'csv',
    'doc',
    'docx',
    'xls',
    'xlsx',
] as const;

export const IT_ATTACHMENT_ACCEPT = allowedExtensions
    .map((extension) => `.${extension}`)
    .join(',');

/** Early feedback only; the server also validates detected file content. */
export function isAllowedItAttachmentName(name: string): boolean {
    const extension = name.match(/\.([^.]+)$/)?.[1]?.toLowerCase();
    return allowedExtensions.some((allowed) => allowed === extension);
}

/** Reject a selection as a whole; callers keep the current File objects. */
export function itAttachmentSelectionError(
    files: readonly Pick<File, 'name' | 'size'>[],
    existingCount = 0,
): string | null {
    if (existingCount + files.length > 5) {
        return 'Choose up to 5 files in total. No files from this selection were added; your existing files have been kept.';
    }
    if (files.some((file) => file.size > 10 * 1024 * 1024)) {
        return 'Each file must be 10 MB or smaller. Choose smaller files; your existing files have been kept.';
    }
    if (files.some((file) => !isAllowedItAttachmentName(file.name))) {
        return 'Choose an image, PDF, text, CSV, Word or Excel file. No files from this selection were added.';
    }
    return null;
}

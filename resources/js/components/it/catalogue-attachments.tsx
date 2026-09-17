import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Paperclip, X } from 'lucide-react';
import { useRef, useState } from 'react';

// Mirrors the canonical ItAttachment allowlist. The server verifies content too.
export const CATALOGUE_FILE_EXTENSIONS =
    '.jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx';
export const CATALOGUE_MAX_FILES = 5;
export const CATALOGUE_MAX_FILE_BYTES = 10 * 1024 * 1024;

export function catalogueFiles(value: unknown): File[] {
    return Array.isArray(value) && typeof File !== 'undefined'
        ? value.filter((entry): entry is File => entry instanceof File)
        : [];
}

export function catalogueFileError(
    files: File[],
    max = CATALOGUE_MAX_FILES,
): string | null {
    if (files.length > Math.min(max, CATALOGUE_MAX_FILES))
        return `Choose no more than ${Math.min(max, CATALOGUE_MAX_FILES)} files for this field.`;
    if (files.some((file) => file.size > CATALOGUE_MAX_FILE_BYTES))
        return 'Each file must be 10 MB or smaller. Remove or replace the larger file.';
    if (
        files.some(
            (file) =>
                !CATALOGUE_FILE_EXTENSIONS.split(',').includes(
                    `.${file.name.split('.').pop()?.toLowerCase()}`,
                ),
        )
    )
        return 'Choose an image, PDF, text, CSV, Word or Excel file. Script and web files are not accepted.';
    return null;
}

export function CatalogueAttachmentField({
    id,
    label,
    files,
    max,
    disabled,
    required,
    describedBy,
    error,
    onChange,
}: {
    id: string;
    label: string;
    files: File[];
    max?: number;
    disabled: boolean;
    required?: boolean;
    describedBy?: string;
    error?: string;
    onChange: (files: File[]) => void;
}) {
    const picker = useRef<HTMLInputElement>(null);
    const [selectionError, setSelectionError] = useState<string | null>(null);
    return (
        <div className="space-y-2">
            <Input
                ref={picker}
                id={id}
                type="file"
                multiple
                accept={CATALOGUE_FILE_EXTENSIONS}
                disabled={disabled}
                aria-required={required}
                aria-invalid={Boolean(error || selectionError)}
                aria-describedby={[
                    describedBy,
                    `${id}-file-help`,
                    selectionError ? `${id}-selection-error` : null,
                ]
                    .filter(Boolean)
                    .join(' ')}
                onChange={(event) => {
                    const next = [
                        ...files,
                        ...Array.from(event.target.files ?? []),
                    ];
                    event.target.value = '';
                    const problem = catalogueFileError(next, max);
                    setSelectionError(problem);
                    if (!problem) onChange(next);
                }}
            />
            <p id={`${id}-file-help`} className="text-caption">
                Up to{' '}
                {Math.min(max ?? CATALOGUE_MAX_FILES, CATALOGUE_MAX_FILES)}{' '}
                files here, five across the request. Maximum 10 MB each. Files
                upload when you submit. Keep the originals until the request is
                saved.
            </p>
            {selectionError && (
                <p
                    id={`${id}-selection-error`}
                    role="alert"
                    className="text-sm text-destructive"
                >
                    {selectionError} Your earlier selections are retained.
                </p>
            )}
            {files.length > 0 && (
                <ul
                    aria-label={`${label} selected files`}
                    className="space-y-2"
                >
                    {files.map((file, index) => (
                        <li
                            key={`${index}-${file.name}`}
                            className="flex min-w-0 items-center gap-2 rounded-lg border border-border p-2"
                        >
                            <Paperclip
                                aria-hidden="true"
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                            <span className="min-w-0 flex-1 text-sm break-words">
                                {file.name}{' '}
                                <span className="text-muted-foreground">
                                    ({Math.max(1, Math.ceil(file.size / 1024))}{' '}
                                    KB)
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={disabled}
                                aria-label={`Remove ${file.name}`}
                                onClick={() => {
                                    onChange(
                                        files.filter(
                                            (_, position) => position !== index,
                                        ),
                                    );
                                    setSelectionError(null);
                                    picker.current?.focus();
                                }}
                            >
                                <X aria-hidden="true" className="size-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

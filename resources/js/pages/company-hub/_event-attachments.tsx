import type { EventAttachment } from '@/components/hr/calendar/event-wizard-dialog';
import { Button } from '@/components/ui/button';
import { ArrowLeft, Download, FileText } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubPdfReader } from './_pdf-reader';
import { fileSize } from './_upload';

export function EventAttachments({ files }: { files: EventAttachment[] }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const openers = useRef(new Map<number, HTMLButtonElement>());
    const heading = useRef<HTMLHeadingElement>(null);
    const selected = files.find(
        (file) => file.id === selectedId && file.preview_url,
    );
    const selectedKey = selected?.id;
    const selectedPreview = selected?.preview_url;
    useEffect(() => {
        if (selectedKey === undefined || !selectedPreview) return;
        heading.current?.focus({ preventScroll: true });
        heading.current?.scrollIntoView?.({ block: 'nearest' });
    }, [selectedKey, selectedPreview]);
    if (!files.length) return null;
    return (
        <section className="space-y-3" aria-label="Event attachments">
            <h3 className="text-sm font-semibold">Useful for this event</h3>
            {selected ? (
                <div className="space-y-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setSelectedId(null);
                            requestAnimationFrame(() =>
                                openers.current.get(selected.id)?.focus(),
                            );
                        }}
                    >
                        <ArrowLeft className="size-4" /> Back to event files
                    </Button>
                    <h4
                        ref={heading}
                        tabIndex={-1}
                        className="scroll-mt-32 text-sm font-medium break-words outline-none"
                    >
                        {selected.name}
                    </h4>
                    <HubPdfReader
                        key={`${selected.id}:${selected.preview_url}`}
                        url={selected.preview_url ?? null}
                        download={selected.download_url ?? selected.url}
                        name={selected.name}
                        mime={selected.mime ?? ''}
                    />
                </div>
            ) : (
                <ul className="divide-y rounded-xl border bg-card px-4">
                    {files.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-3 py-3"
                        >
                            <FileText className="size-5 shrink-0 text-primary" />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium break-words">
                                    {file.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {fileSize(file.size)}
                                    {!file.preview_url && ' · Download to open'}
                                </p>
                            </div>
                            {file.preview_url && (
                                <Button
                                    ref={(element) => {
                                        if (element)
                                            openers.current.set(
                                                file.id,
                                                element,
                                            );
                                        else openers.current.delete(file.id);
                                    }}
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Preview ${file.name}`}
                                    onClick={() => setSelectedId(file.id)}
                                >
                                    Preview
                                </Button>
                            )}
                            <Button size="icon" variant="ghost" asChild>
                                <a
                                    href={file.download_url ?? file.url}
                                    aria-label={`Download ${file.name}`}
                                >
                                    <Download className="size-4" />
                                </a>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

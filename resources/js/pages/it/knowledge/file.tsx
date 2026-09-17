import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';

export default function KnowledgeFile({
    document,
    file,
}: {
    document: {
        id: number;
        title: string;
        href: string;
        files_href: string;
        library_href: string;
    };
    file: {
        name: string;
        version: number;
        size: number;
        original_href: string;
        pdf_href: string | null;
        raster_href?: string | null;
        text: string | null;
    };
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Knowledge', href: document.library_href },
                { title: document.title, href: document.href },
                { title: file.name, href: '#' },
            ]}
        >
            <Head title={file.name} />
            <div className="space-y-5">
                <PageHeader
                    variant="profile"
                    wrapTitle
                    className="overflow-clip!"
                    icon={FileText}
                    title={file.name}
                    backHref={document.files_href}
                    subline={`${document.title} · File version ${file.version}`}
                    actions={
                        <PageHeaderPrimaryButton
                            onClick={() =>
                                window.location.assign(file.original_href)
                            }
                        >
                            Download original
                        </PageHeaderPrimaryButton>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Version"
                                href={document.files_href}
                            >
                                <PageHeaderMeterBig>
                                    {file.version}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Document file history
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Preview"
                                href="#file-preview"
                            >
                                <PageHeaderMeterBig>
                                    {file.pdf_href
                                        ? 'PDF'
                                        : file.raster_href
                                          ? 'Image'
                                          : file.text
                                            ? 'Word text'
                                            : 'Original file'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {file.pdf_href ||
                                    file.raster_href ||
                                    file.text
                                        ? 'Read below'
                                        : 'Preview unavailable'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="File size"
                                href={file.original_href}
                            >
                                <PageHeaderMeterBig>
                                    {Math.ceil(file.size / 1024)} KB
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Download original
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Document"
                                href={document.href}
                            >
                                <PageHeaderMeterBig>
                                    {document.id}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Return to document
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                />
                <section className="space-y-5 rounded-xl border border-border bg-card p-5">
                    <h2 id="file-preview" className="text-section-title">
                        File preview
                    </h2>
                    {file.raster_href ? (
                        <img
                            src={file.raster_href}
                            alt={file.name}
                            className="max-h-[70vh] max-w-full rounded-lg border border-border object-contain"
                        />
                    ) : file.pdf_href ? (
                        <>
                            <p className="text-subtle">
                                If your browser cannot display this PDF, use
                                Download original to open it with your PDF
                                reader.
                            </p>
                            <object
                                data={file.pdf_href}
                                type="application/pdf"
                                aria-label={`PDF preview: ${file.name}`}
                                className="h-[70vh] w-full rounded-lg border border-border"
                            >
                                <p>
                                    PDF preview is unavailable in this browser.{' '}
                                    <a
                                        className="text-primary underline"
                                        href={file.original_href}
                                    >
                                        Download the original PDF
                                    </a>
                                    .
                                </p>
                            </object>
                        </>
                    ) : file.text ? (
                        <>
                            <p className="text-subtle">
                                Word text preview. Download the original for the
                                full layout, images and embedded objects.
                            </p>
                            <div className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                                {file.text}
                            </div>
                        </>
                    ) : (
                        <div
                            role="status"
                            className="rounded-lg border border-border bg-muted p-5"
                        >
                            <h2 className="text-section-title">
                                Preview unavailable
                            </h2>
                            <p className="text-subtle mt-2">
                                This file cannot be previewed here. Download the
                                original and open it in Microsoft Word or
                                another compatible reader.
                            </p>
                        </div>
                    )}
                    <div className="flex gap-3">
                        <Button variant="outline" asChild>
                            <Link href={document.files_href}>
                                Back to document
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={file.original_href}>Download original</a>
                        </Button>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

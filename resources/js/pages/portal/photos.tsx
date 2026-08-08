import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Camera, Image as ImageIcon, Upload, X } from 'lucide-react';
import { FormEvent, useCallback, useRef, useState } from 'react';

type Photo = {
    id: number;
    url: string;
    thumbnail_url?: string | null;
    caption?: string | null;
    tags?: string[] | null;
    created_at: string;
    uploaded_by_name?: string | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    photos: {
        data: Photo[];
        links: any;
    };
    canUpload: boolean;
    requiresApproval: boolean;
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function Photos({
    client,
    photos,
    canUpload,
    requiresApproval,
}: Props) {
    const clientName = `${client.first_name} ${client.last_name}`;
    const [lightboxPhoto, setLightboxPhoto] = useState<Photo | null>(null);
    const [uploadOpen, setUploadOpen] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [dragActive, setDragActive] = useState(false);

    const form = useForm<{
        photo: File | null;
        caption: string;
        tags: string;
    }>({
        photo: null,
        caption: '',
        tags: '',
    });

    const handleDrag = useCallback((e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === 'dragenter' || e.type === 'dragover') {
            setDragActive(true);
        } else if (e.type === 'dragleave') {
            setDragActive(false);
        }
    }, []);

    const handleDrop = useCallback(
        (e: React.DragEvent) => {
            e.preventDefault();
            e.stopPropagation();
            setDragActive(false);
            if (e.dataTransfer.files?.[0]) {
                form.setData('photo', e.dataTransfer.files[0]);
            }
        },
        [form],
    );

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files?.[0]) {
            form.setData('photo', e.target.files[0]);
        }
    };

    const handleUpload = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/portal/clients/${client.id}/photos`, {
            forceFormData: true,
            onSuccess: () => {
                setUploadOpen(false);
                form.reset();
            },
        });
    };

    const loadMore = () => {
        if (photos.links?.next) {
            router.get(
                photos.links.next,
                {},
                { preserveState: true, preserveScroll: true },
            );
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Photos',
                    href: `/portal/clients/${client.id}/photos`,
                },
            ]}
        >
            <Head title={`Photos - ${clientName}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={ImageIcon}
                        title="Photos"
                        description={`Photos shared with and by ${clientName}'s care team.`}
                        stats={[{ label: 'Total', value: photos.data.length }]}
                        actions={
                            canUpload ? (
                                <Dialog
                                    open={uploadOpen}
                                    onOpenChange={setUploadOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button>
                                            <Upload className="mr-2 h-4 w-4" />
                                            Upload Photo
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Upload Photo
                                            </DialogTitle>
                                            {requiresApproval && (
                                                <DialogDescription>
                                                    Photos will be reviewed by
                                                    the care team before
                                                    appearing.
                                                </DialogDescription>
                                            )}
                                        </DialogHeader>
                                        <form
                                            onSubmit={handleUpload}
                                            className="space-y-4"
                                        >
                                            <div
                                                className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors ${
                                                    dragActive
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-muted-foreground/25 hover:border-primary/50'
                                                }`}
                                                onDragEnter={handleDrag}
                                                onDragLeave={handleDrag}
                                                onDragOver={handleDrag}
                                                onDrop={handleDrop}
                                                onClick={() =>
                                                    fileInputRef.current?.click()
                                                }
                                            >
                                                {form.data.photo ? (
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-medium">
                                                            {
                                                                form.data.photo
                                                                    .name
                                                            }
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 text-muted-foreground hover:text-foreground"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                form.setData(
                                                                    'photo',
                                                                    null,
                                                                );
                                                            }}
                                                        >
                                                            <X className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <>
                                                        <Camera className="mb-2 h-8 w-8 text-muted-foreground" />
                                                        <p className="text-sm text-muted-foreground">
                                                            Drag and drop or
                                                            click to select
                                                        </p>
                                                    </>
                                                )}
                                                <input
                                                    ref={fileInputRef}
                                                    type="file"
                                                    accept="image/*"
                                                    className="hidden"
                                                    onChange={handleFileChange}
                                                />
                                            </div>
                                            {form.errors.photo && (
                                                <p className="text-sm text-destructive">
                                                    {form.errors.photo}
                                                </p>
                                            )}

                                            <div className="space-y-2">
                                                <Label htmlFor="caption">
                                                    Caption
                                                </Label>
                                                <Input
                                                    id="caption"
                                                    value={form.data.caption}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'caption',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Add a caption..."
                                                />
                                                {form.errors.caption && (
                                                    <p className="text-sm text-destructive">
                                                        {form.errors.caption}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="tags">
                                                    Tags (comma separated)
                                                </Label>
                                                <Input
                                                    id="tags"
                                                    value={form.data.tags}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'tags',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. outing, birthday, activity"
                                                />
                                                {form.errors.tags && (
                                                    <p className="text-sm text-destructive">
                                                        {form.errors.tags}
                                                    </p>
                                                )}
                                            </div>

                                            <DialogFooter>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        !form.data.photo ||
                                                        form.processing
                                                    }
                                                >
                                                    {form.processing
                                                        ? 'Uploading...'
                                                        : 'Upload'}
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            ) : undefined
                        }
                    />
                }
            >
                {photos.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <Camera className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">
                                No photos shared yet
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {photos.data.map((photo) => (
                                <Card
                                    key={photo.id}
                                    className="cursor-pointer overflow-hidden transition-shadow hover:shadow-md"
                                    onClick={() => setLightboxPhoto(photo)}
                                >
                                    <div className="aspect-square overflow-hidden">
                                        <img
                                            src={
                                                photo.thumbnail_url || photo.url
                                            }
                                            alt={photo.caption || 'Photo'}
                                            className="h-full w-full object-cover"
                                        />
                                    </div>
                                    <CardContent className="space-y-2 p-3">
                                        {photo.caption && (
                                            <p className="text-sm leading-snug font-medium">
                                                {photo.caption}
                                            </p>
                                        )}
                                        {photo.tags &&
                                            photo.tags.length > 0 && (
                                                <div className="flex flex-wrap gap-1">
                                                    {photo.tags.map((tag) => (
                                                        <Badge
                                                            key={tag}
                                                            variant="secondary"
                                                            className="text-xs"
                                                        >
                                                            {tag}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            )}
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(photo.created_at)}
                                            {photo.uploaded_by_name &&
                                                ` · ${photo.uploaded_by_name}`}
                                        </p>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        {photos.links?.next && (
                            <div className="flex justify-center">
                                <Button variant="outline" onClick={loadMore}>
                                    Load more
                                </Button>
                            </div>
                        )}
                    </>
                )}

                {/* Lightbox */}
                <Dialog
                    open={!!lightboxPhoto}
                    onOpenChange={() => setLightboxPhoto(null)}
                >
                    <DialogContent className="max-w-3xl">
                        {lightboxPhoto && (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        {lightboxPhoto.caption || 'Photo'}
                                    </DialogTitle>
                                </DialogHeader>
                                <div className="overflow-hidden rounded-lg">
                                    <img
                                        src={lightboxPhoto.url}
                                        alt={lightboxPhoto.caption || 'Photo'}
                                        className="h-auto max-h-[70vh] w-full object-contain"
                                    />
                                </div>
                                <div className="flex items-center justify-between text-sm text-muted-foreground">
                                    <span>
                                        {formatDate(lightboxPhoto.created_at)}
                                    </span>
                                    {lightboxPhoto.uploaded_by_name && (
                                        <span>
                                            Uploaded by{' '}
                                            {lightboxPhoto.uploaded_by_name}
                                        </span>
                                    )}
                                </div>
                                {lightboxPhoto.tags &&
                                    lightboxPhoto.tags.length > 0 && (
                                        <div className="flex flex-wrap gap-1">
                                            {lightboxPhoto.tags.map((tag) => (
                                                <Badge
                                                    key={tag}
                                                    variant="secondary"
                                                    className="text-xs"
                                                >
                                                    {tag}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                            </>
                        )}
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}

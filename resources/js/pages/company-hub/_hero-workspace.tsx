import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Check, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { defaultHero, HeroSettings } from './_hero-settings';
import { MediaUpload } from './_media-picker';
import type { HubContent } from './_types';

export type HeroPreview = Pick<
    HubContent,
    'title' | 'summary' | 'hero_label' | 'cover_id' | 'hero'
>;

export function heroPreview(content: HubContent): HeroPreview {
    return {
        title: content.title,
        summary: content.summary,
        hero_label: content.hero_label,
        cover_id: content.cover_id,
        hero: { ...defaultHero, ...content.hero },
    };
}

export function HeroWorkspace({
    pageId,
    isHome,
    value,
    original,
    onChange,
    onApply,
    onCancel,
}: {
    pageId: number;
    isHome: boolean;
    value: HeroPreview;
    original: HeroPreview;
    onChange: (value: HeroPreview) => void;
    onApply: () => void;
    onCancel: () => void;
}) {
    const [confirmCancel, setConfirmCancel] = useState(false);
    const discarded = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
    }, []);
    const dirty = JSON.stringify(value) !== JSON.stringify(original);
    const cancel = () => (dirty ? setConfirmCancel(true) : onCancel());
    return (
        <section
            className="hub-hero-workspace rounded-xl border border-border bg-card"
            aria-label="Hero designer"
        >
            <header className="flex flex-wrap items-center justify-between gap-5 border-b p-5">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Back to page designer"
                        onClick={cancel}
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <div>
                        <h2
                            className="text-lg font-semibold"
                            tabIndex={-1}
                            ref={heading}
                        >
                            A welcome that feels like your company.
                        </h2>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Try your changes in the banner above. Apply them
                            when you’re happy.
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        disabled={!dirty}
                        onClick={() => onChange(original)}
                    >
                        <RotateCcw className="size-4" />
                        Reset preview
                    </Button>
                    <Button variant="outline" onClick={cancel}>
                        Cancel
                    </Button>
                    <Button
                        disabled={!dirty || !value.title.trim()}
                        onClick={onApply}
                    >
                        <Check className="size-4" />
                        Apply to draft
                    </Button>
                </div>
            </header>
            <div className="grid grid-cols-[minmax(280px,0.8fr)_minmax(360px,1.2fr)] gap-8 p-6">
                <div className="space-y-5">
                    <div>
                        <h3 className="font-semibold">Your welcome</h3>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            Keep it clear, warm and recognisably yours.
                        </p>
                    </div>
                    <Label className="grid gap-2">
                        Title
                        <Input
                            value={value.title}
                            maxLength={160}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    title: event.target.value,
                                })
                            }
                        />
                    </Label>
                    <Label className="grid gap-2">
                        Introduction
                        <Textarea
                            rows={3}
                            maxLength={600}
                            value={value.summary}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    summary: event.target.value,
                                })
                            }
                        />
                    </Label>
                    <Label className="grid gap-2">
                        Hero label
                        <Input
                            maxLength={60}
                            value={value.hero_label}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    hero_label: event.target.value,
                                })
                            }
                        />
                    </Label>
                    <div className="border-t pt-5">
                        <MediaUpload
                            pageId={pageId}
                            mediaId={value.cover_id}
                            label="Hero image"
                            onChange={(id, media) =>
                                onChange({
                                    ...value,
                                    cover_id: id,
                                    hero: {
                                        ...defaultHero,
                                        ...value.hero,
                                        ...(media && id !== value.cover_id
                                            ? {
                                                  focal_x: media.focal_x ?? 50,
                                                  focal_y: media.focal_y ?? 50,
                                              }
                                            : {}),
                                        style: id ? 'image' : 'brand',
                                    },
                                })
                            }
                        />
                    </div>
                </div>
                <div className="border-l pl-8">
                    <HeroSettings
                        isHome={isHome}
                        value={value.hero}
                        onChange={(hero) => onChange({ ...value, hero })}
                    />
                </div>
            </div>
            <footer
                className="border-t px-6 py-4 text-xs text-muted-foreground"
                role="status"
            >
                {dirty
                    ? 'Preview changes have not been applied to your draft.'
                    : 'Preview matches your current draft.'}{' '}
                Applying saves a private draft; staff see changes only after
                publication.
            </footer>
            <Dialog open={confirmCancel} onOpenChange={setConfirmCancel}>
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        if (discarded.current) event.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Discard this hero preview?</DialogTitle>
                        <DialogDescription>
                            Your current draft stays as it was before these
                            preview changes. Uploaded images stay in the media
                            library.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmCancel(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            onClick={() => {
                                discarded.current = true;
                                onCancel();
                            }}
                        >
                            Discard preview
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

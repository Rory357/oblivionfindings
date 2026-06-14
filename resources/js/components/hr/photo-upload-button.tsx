import { router } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Uploads a profile photo via the existing directory.uploadPhoto endpoint
 * (POST /hr/directory/{profile}/photo). Self-contained file picker + button;
 * gate rendering on manage permission at the call site.
 */
export function PhotoUploadButton({
    profileId,
    onDark = false,
    className,
}: {
    profileId: number;
    /** Style for placement on a dark hero gradient. */
    onDark?: boolean;
    className?: string;
}) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setUploading(true);
        router.post(
            `/hr/directory/${profileId}/photo`,
            { photo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setUploading(false);
                    if (inputRef.current) inputRef.current.value = '';
                },
            },
        );
    };

    return (
        <>
            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/png"
                className="hidden"
                onChange={handleFile}
            />
            <Button
                size="sm"
                variant="outline"
                disabled={uploading}
                onClick={() => inputRef.current?.click()}
                className={cn(
                    onDark &&
                        'border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground',
                    className,
                )}
            >
                <Camera className="mr-1.5 h-3.5 w-3.5" />
                {uploading ? 'Uploading…' : 'Photo'}
            </Button>
        </>
    );
}

export default PhotoUploadButton;

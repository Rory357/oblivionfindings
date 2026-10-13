import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Bookmark, Camera, Settings2 } from 'lucide-react';

export type CctvNavigation = {
    live_href: string | null;
    setup_href: string | null;
    setup_kind?: 'recorder';
    reference_href?: string | null;
};

/** Navigation is supplied by current server authority; observations cannot grant it. */
export function CctvNavigationActions({
    cameraId,
    navigation,
}: {
    cameraId: number;
    navigation?: CctvNavigation | null;
}) {
    if (!Number.isSafeInteger(cameraId) || cameraId < 1) return null;
    const base = `/security-devices/devices/${cameraId}/live`;
    return (
        <>
            {navigation?.live_href === base && (
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="frontline-hit"
                >
                    <Link href={base}>
                        <Camera className="size-4" aria-hidden="true" />
                        Live view
                    </Link>
                </Button>
            )}
            {navigation?.setup_href === `${base}/source` && (
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="frontline-hit"
                >
                    <Link href={`${base}/source`}>
                        <Settings2 className="size-4" aria-hidden="true" />
                        {navigation.setup_kind === 'recorder'
                            ? 'Recorder information'
                            : 'Camera setup'}
                    </Link>
                </Button>
            )}
            {navigation?.reference_href ===
                `/security-devices/devices/${cameraId}/recording-references` && (
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="frontline-hit"
                >
                    <Link href={navigation.reference_href}>
                        <Bookmark className="size-4" aria-hidden="true" />
                        Recording references
                    </Link>
                </Button>
            )}
        </>
    );
}

import { PageHeaderGlassButton } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Bookmark, Camera, Settings2, type LucideIcon } from 'lucide-react';

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
    onDark = false,
}: {
    cameraId: number;
    navigation?: CctvNavigation | null;
    /** Inside the PageHeader band the links use the header's glass buttons. */
    onDark?: boolean;
}) {
    if (!Number.isSafeInteger(cameraId) || cameraId < 1) return null;
    const base = `/security-devices/devices/${cameraId}/live`;
    const action = (href: string, Icon: LucideIcon, label: string) =>
        onDark ? (
            <PageHeaderGlassButton asChild>
                <Link href={href}>
                    <Icon className="size-4" aria-hidden="true" />
                    {label}
                </Link>
            </PageHeaderGlassButton>
        ) : (
            <Button
                asChild
                variant="outline"
                size="sm"
                className="frontline-hit"
            >
                <Link href={href}>
                    <Icon className="size-4" aria-hidden="true" />
                    {label}
                </Link>
            </Button>
        );
    return (
        <>
            {navigation?.live_href === base &&
                action(base, Camera, 'Live view')}
            {navigation?.setup_href === `${base}/source` &&
                action(
                    `${base}/source`,
                    Settings2,
                    navigation.setup_kind === 'recorder'
                        ? 'Recorder information'
                        : 'Camera setup',
                )}
            {navigation?.reference_href ===
                `/security-devices/devices/${cameraId}/recording-references` &&
                action(
                    navigation.reference_href,
                    Bookmark,
                    'Recording references',
                )}
        </>
    );
}

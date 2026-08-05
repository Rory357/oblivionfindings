import { SiteHardwareSurface, type SiteHardwareProps } from '../hardware';

export type SiteHardwareData = SiteHardwareProps & {
    locked?: boolean;
    href: string;
};

export function SiteProfileHardware({ data }: { data: SiteHardwareData }) {
    return <SiteHardwareSurface {...data} embedded />;
}

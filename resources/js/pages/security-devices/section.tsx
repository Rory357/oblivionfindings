import type { SecurityDevicesSecondarySectionKey } from './config';
import SecurityDevicesShell from './security-devices-shell';

interface Props {
    section: SecurityDevicesSecondarySectionKey;
}

export default function SecurityDevicesSectionPage({ section }: Props) {
    return <SecurityDevicesShell sectionKey={section} />;
}

import type { DeviceKind } from '@/lib/security-devices/device-words';
import {
    BatteryCharging,
    Box,
    Car,
    Cctv,
    DoorClosed,
    HardDrive,
    MapPin,
    Monitor,
    Network,
    Printer,
    RadioTower,
    Router,
    Server,
    Shield,
    Thermometer,
    Wifi,
    type LucideIcon,
} from 'lucide-react';

/** Icons tell kinds apart (page kit block rule 6); state is always said in words beside them. */
export const deviceKindIcon: Record<DeviceKind, LucideIcon> = {
    switch: Network,
    router: Router,
    wifi_point: Wifi,
    network_device: Network,
    camera: Cctv,
    recorder: HardDrive,
    alarm_panel: Shield,
    door: DoorClosed,
    sensor: Thermometer,
    sensor_gateway: RadioTower,
    care_tracker: MapPin,
    vehicle_tracker: Car,
    computer: Monitor,
    ups: BatteryCharging,
    printer: Printer,
    hub: Server,
    other: Box,
};

export function iconForKind(kind: string): LucideIcon {
    return deviceKindIcon[kind as DeviceKind] ?? Box;
}

/** The kind's icon, looked up from the map (no component made during render). */
export function KindIcon({ kind, className }: { kind: string; className?: string }) {
    const Icon = deviceKindIcon[kind as DeviceKind] ?? Box;
    return <Icon aria-hidden="true" className={className} />;
}

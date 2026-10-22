import {
    DEVICE_DOMAIN_LABELS,
    deviceCategoryLabel,
    domainLabel,
    sentenceCaseLabel,
} from '../devices/shared';

/*
 * The values an automatic membership rule can match. Rules compare the
 * stored value exactly (DeviceGroupAutoRuleService), so the builder offers
 * these stored values and shows them in words.
 *
 * DEVICE_TAXONOMY mirrors DeviceTaxonomy::all() and the status lists mirror
 * the DeviceStatus and HealthStatus enums; auto-rule-values.test.ts fails
 * when either side changes without the other.
 */

export const DEVICE_TAXONOMY: Record<
    string,
    Record<string, Record<string, string>>
> = {
    security: {
        alarm: {
            panel: 'Alarm Panel',
            keypad: 'Keypad',
            pir_motion: 'PIR Motion Sensor',
            door_sensor: 'Door Sensor',
            glass_break: 'Glass Break Sensor',
            smoke_heat: 'Smoke & Heat Detector',
            panic_button: 'Panic Button',
            siren_sounder: 'Siren / Sounder',
            beam_perimeter: 'Beam / Perimeter Sensor',
            water_leak: 'Water Leak Sensor',
            gas_detector: 'Gas Detector',
            duress: 'Duress Alarm',
            tamper: 'Tamper Sensor',
            vibration_shock: 'Vibration / Shock Sensor',
            zone_partition: 'Zone / Partition Module',
        },
        cctv: {
            dome_camera: 'Dome Camera',
            bullet_camera: 'Bullet Camera',
            ptz_camera: 'PTZ Camera',
            fisheye_camera: 'Fisheye Camera',
            thermal_camera: 'Thermal Camera',
            body_camera: 'Body Camera',
            dashcam: 'Dashcam',
            nvr: 'NVR',
            dvr: 'DVR',
            video_encoder: 'Video Encoder',
            video_analytics_unit: 'Video Analytics Unit',
        },
        access_control: {
            card_reader: 'Card Reader',
            biometric_reader: 'Biometric Reader',
            electric_lock: 'Electric Lock',
            magnetic_lock: 'Magnetic Lock',
            turnstile: 'Turnstile',
            barrier_gate: 'Barrier / Gate',
            intercom: 'Intercom',
            video_intercom: 'Video Intercom',
            access_panel: 'Access Control Panel',
            badge_printer: 'Badge Printer',
        },
        perimeter: {
            beam_sensor: 'Beam Sensor',
            fence_sensor: 'Fence Sensor',
            ground_sensor: 'Ground Sensor',
            radar: 'Radar',
            thermal_perimeter: 'Thermal Perimeter',
        },
    },
    tracking: {
        vehicle_tracker: {
            hardwired_gps: 'Hardwired GPS',
            obd_tracker: 'OBD Tracker',
            asset_tracker: 'Asset Tracker',
            dashcam_gps: 'Dashcam GPS',
        },
        personal_tracker: {
            wearable_gps: 'Wearable GPS',
            pendant: 'Pendant',
            sos_device: 'SOS Device',
            lone_worker: 'Lone Worker Device',
            ankle_monitor: 'Ankle Monitor',
        },
        asset_tracker: {
            bluetooth_tag: 'Bluetooth Tag',
            lora_tag: 'LoRa Tag',
            cellular_tag: 'Cellular Tag',
        },
        telematics: {
            can_bus_reader: 'CAN Bus Reader',
            fuel_sensor: 'Fuel Sensor',
            temperature_probe: 'Temperature Probe',
        },
    },
    iot_healthcare: {
        fall_detection: {
            wearable_fall: 'Wearable Fall Sensor',
            room_fall_sensor: 'Room Fall Sensor',
            bed_exit: 'Bed Exit Sensor',
        },
        occupancy: {
            pir_occupancy: 'PIR Occupancy',
            radar_occupancy: 'Radar Occupancy',
            door_contact: 'Door Contact',
            chair_sensor: 'Chair Sensor',
        },
        wandering: {
            wandering_door_sensor: 'Wandering Door Sensor',
            geofence_wearable: 'Geofence Wearable',
            mat_sensor: 'Mat Sensor',
        },
        bed_sensor: {
            pressure_mat: 'Pressure Mat',
            smart_mattress: 'Smart Mattress',
            bed_rail_sensor: 'Bed Rail Sensor',
        },
        medication: {
            fridge_temp_sensor: 'Fridge Temperature Sensor',
            dispenser_monitor: 'Dispenser Monitor',
        },
        nurse_call: {
            pull_cord: 'Pull Cord',
            nurse_pendant: 'Nurse Call Pendant',
            wall_unit: 'Wall Unit',
            bedside_unit: 'Bedside Unit',
        },
        wellness: {
            vitals_wearable: 'Vitals Wearable',
            weight_scale: 'Weight Scale',
            blood_pressure: 'Blood Pressure Monitor',
            pulse_oximeter: 'Pulse Oximeter',
        },
        environmental: {
            temperature: 'Temperature Sensor',
            humidity: 'Humidity Sensor',
            air_quality: 'Air Quality Sensor',
            noise_level: 'Noise Level Sensor',
            light_level: 'Light Level Sensor',
        },
        appliance: {
            smart_plug: 'Smart Plug',
            cooker_monitor: 'Cooker Monitor',
            water_heater_monitor: 'Water Heater Monitor',
        },
    },
    it_infrastructure: {
        server: {
            physical_server: 'Physical Server',
            virtual_host: 'Virtual Host',
            hypervisor: 'Hypervisor',
        },
        storage: {
            nas: 'NAS',
            san: 'SAN',
            backup_appliance: 'Backup Appliance',
        },
        network: {
            router: 'Router',
            firewall: 'Firewall',
            switch: 'Switch',
            wireless_ap: 'Wireless Access Point',
            lte_gateway: 'LTE Gateway',
            five_g_gateway: '5G Gateway',
        },
        power: {
            ups: 'UPS',
            pdu: 'PDU',
            generator_monitor: 'Generator Monitor',
        },
        endpoint: {
            tablet: 'Tablet',
            shared_device: 'Shared Device',
            kiosk: 'Kiosk',
            thin_client: 'Thin Client',
        },
        voice: {
            pbx: 'PBX',
            sip_phone: 'SIP Phone',
            intercom_server: 'Intercom Server',
        },
        recording: {
            nvr_infrastructure: 'NVR (Infrastructure)',
            dvr_infrastructure: 'DVR (Infrastructure)',
            video_server: 'Video Server',
        },
        rack: {
            rack_unit: 'Rack Unit',
            patch_panel: 'Patch Panel',
            cable_management: 'Cable Management',
        },
        it_environmental: {
            rack_temp_sensor: 'Rack Temperature Sensor',
            rack_humidity: 'Rack Humidity Sensor',
            water_leak_sensor: 'Water Leak Sensor',
        },
        printer: {
            printer: 'Printer',
            mfp: 'MFP',
            label_printer: 'Label Printer',
        },
    },
    facilities: {
        leak_detection: {
            water_sensor: 'Water Sensor',
            pipe_sensor: 'Pipe Sensor',
        },
        gas_detection: {
            natural_gas: 'Natural Gas Detector',
            co_detector: 'CO Detector',
            lpg_detector: 'LPG Detector',
        },
        cold_chain: {
            freezer_sensor: 'Freezer Sensor',
            fridge_sensor: 'Fridge Sensor',
            cool_room_sensor: 'Cool Room Sensor',
        },
        mechanical: {
            generator_monitor: 'Generator Monitor',
            pump_monitor: 'Pump Monitor',
            hvac_sensor: 'HVAC Sensor',
        },
        facility_access: {
            gate_controller: 'Gate Controller',
            barrier_controller: 'Barrier Controller',
            smart_relay: 'Smart Relay',
        },
        building_safety: {
            fire_panel: 'Fire Panel',
            sprinkler_monitor: 'Sprinkler Monitor',
            emergency_lighting: 'Emergency Lighting',
        },
    },
};

export const DEVICE_STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    offline: 'Offline',
    degraded: 'Degraded',
    maintenance: 'Maintenance',
    decommissioned: 'Decommissioned',
    in_stock: 'In stock',
    quarantined: 'Quarantined',
    lost: 'Lost',
};

export const HEALTH_STATUS_LABELS: Record<string, string> = {
    healthy: 'Healthy',
    warning: 'Warning',
    critical: 'Critical',
    unknown: 'Unknown',
};

export type AutoRuleValueOption = {
    value: string;
    label: string;
    group?: string;
};

const fromLabels = (labels: Record<string, string>) =>
    Object.entries(labels).map(([value, label]) => ({ value, label }));

function categoryOptions(): AutoRuleValueOption[] {
    return Object.entries(DEVICE_TAXONOMY).flatMap(([domain, categories]) =>
        Object.keys(categories).map((category) => ({
            value: category,
            label: deviceCategoryLabel(category),
            group: domainLabel(domain),
        })),
    );
}

function subcategoryOptions(): AutoRuleValueOption[] {
    const seen = new Set<string>();
    const options: AutoRuleValueOption[] = [];
    for (const categories of Object.values(DEVICE_TAXONOMY))
        for (const [category, subcategories] of Object.entries(categories))
            for (const [value, label] of Object.entries(subcategories)) {
                // A subtype key used by two categories is one stored value.
                if (seen.has(value)) continue;
                seen.add(value);
                options.push({
                    value,
                    label: sentenceCaseLabel(label),
                    group: deviceCategoryLabel(category),
                });
            }
    return options;
}

/** The stored values for a field, in words; null when the field is free text. */
export function autoRuleValueOptions(
    field: string,
): AutoRuleValueOption[] | null {
    switch (field) {
        case 'domain':
            return fromLabels(DEVICE_DOMAIN_LABELS);
        case 'category':
            return categoryOptions();
        case 'subcategory':
            return subcategoryOptions();
        case 'status':
            return fromLabels(DEVICE_STATUS_LABELS);
        case 'health_status':
            return fromLabels(HEALTH_STATUS_LABELS);
        default:
            return null;
    }
}

/** A stored rule value in words, falling back to the value itself. */
export function autoRuleValueLabel(field: string, value: string) {
    return (
        autoRuleValueOptions(field)?.find((option) => option.value === value)
            ?.label ?? value
    );
}

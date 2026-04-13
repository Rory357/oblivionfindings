<?php

namespace App\Domain\SecurityDevices\Config;

use App\Domain\SecurityDevices\Enums\DeviceDomain;

/**
 * Device category and subcategory taxonomy.
 *
 * Categories and subcategories are strings (not enums) because they evolve
 * with vendor integrations and operational needs. Adding a new subcategory
 * should not require a migration or enum case — just a config entry here.
 *
 * The taxonomy is validated at the service layer, not the database layer.
 */
class DeviceTaxonomy
{
    /**
     * Full taxonomy: domain → categories → subcategories.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function all(): array
    {
        return [
            DeviceDomain::Security->value => [
                'alarm' => [
                    'panel' => 'Alarm Panel',
                    'keypad' => 'Keypad',
                    'pir_motion' => 'PIR Motion Sensor',
                    'door_sensor' => 'Door Sensor',
                    'glass_break' => 'Glass Break Sensor',
                    'smoke_heat' => 'Smoke & Heat Detector',
                    'panic_button' => 'Panic Button',
                    'siren_sounder' => 'Siren / Sounder',
                    'beam_perimeter' => 'Beam / Perimeter Sensor',
                    'water_leak' => 'Water Leak Sensor',
                    'gas_detector' => 'Gas Detector',
                    'duress' => 'Duress Alarm',
                    'tamper' => 'Tamper Sensor',
                    'vibration_shock' => 'Vibration / Shock Sensor',
                    'zone_partition' => 'Zone / Partition Module',
                ],
                'cctv' => [
                    'dome_camera' => 'Dome Camera',
                    'bullet_camera' => 'Bullet Camera',
                    'ptz_camera' => 'PTZ Camera',
                    'fisheye_camera' => 'Fisheye Camera',
                    'thermal_camera' => 'Thermal Camera',
                    'body_camera' => 'Body Camera',
                    'dashcam' => 'Dashcam',
                    'nvr' => 'NVR',
                    'dvr' => 'DVR',
                    'video_encoder' => 'Video Encoder',
                    'video_analytics_unit' => 'Video Analytics Unit',
                ],
                'access_control' => [
                    'card_reader' => 'Card Reader',
                    'biometric_reader' => 'Biometric Reader',
                    'electric_lock' => 'Electric Lock',
                    'magnetic_lock' => 'Magnetic Lock',
                    'turnstile' => 'Turnstile',
                    'barrier_gate' => 'Barrier / Gate',
                    'intercom' => 'Intercom',
                    'video_intercom' => 'Video Intercom',
                    'access_panel' => 'Access Control Panel',
                    'badge_printer' => 'Badge Printer',
                ],
                'perimeter' => [
                    'beam_sensor' => 'Beam Sensor',
                    'fence_sensor' => 'Fence Sensor',
                    'ground_sensor' => 'Ground Sensor',
                    'radar' => 'Radar',
                    'thermal_perimeter' => 'Thermal Perimeter',
                ],
            ],

            DeviceDomain::Tracking->value => [
                'vehicle_tracker' => [
                    'hardwired_gps' => 'Hardwired GPS',
                    'obd_tracker' => 'OBD Tracker',
                    'asset_tracker' => 'Asset Tracker',
                    'dashcam_gps' => 'Dashcam GPS',
                ],
                'personal_tracker' => [
                    'wearable_gps' => 'Wearable GPS',
                    'pendant' => 'Pendant',
                    'sos_device' => 'SOS Device',
                    'lone_worker' => 'Lone Worker Device',
                    'ankle_monitor' => 'Ankle Monitor',
                ],
                'asset_tracker' => [
                    'bluetooth_tag' => 'Bluetooth Tag',
                    'lora_tag' => 'LoRa Tag',
                    'cellular_tag' => 'Cellular Tag',
                ],
                'telematics' => [
                    'can_bus_reader' => 'CAN Bus Reader',
                    'fuel_sensor' => 'Fuel Sensor',
                    'temperature_probe' => 'Temperature Probe',
                ],
            ],

            DeviceDomain::IotHealthcare->value => [
                'fall_detection' => [
                    'wearable_fall' => 'Wearable Fall Sensor',
                    'room_fall_sensor' => 'Room Fall Sensor',
                    'bed_exit' => 'Bed Exit Sensor',
                ],
                'occupancy' => [
                    'pir_occupancy' => 'PIR Occupancy',
                    'radar_occupancy' => 'Radar Occupancy',
                    'door_contact' => 'Door Contact',
                    'chair_sensor' => 'Chair Sensor',
                ],
                'wandering' => [
                    'wandering_door_sensor' => 'Wandering Door Sensor',
                    'geofence_wearable' => 'Geofence Wearable',
                    'mat_sensor' => 'Mat Sensor',
                ],
                'bed_sensor' => [
                    'pressure_mat' => 'Pressure Mat',
                    'smart_mattress' => 'Smart Mattress',
                    'bed_rail_sensor' => 'Bed Rail Sensor',
                ],
                'medication' => [
                    'fridge_temp_sensor' => 'Fridge Temperature Sensor',
                    'dispenser_monitor' => 'Dispenser Monitor',
                ],
                'nurse_call' => [
                    'pull_cord' => 'Pull Cord',
                    'nurse_pendant' => 'Nurse Call Pendant',
                    'wall_unit' => 'Wall Unit',
                    'bedside_unit' => 'Bedside Unit',
                ],
                'wellness' => [
                    'vitals_wearable' => 'Vitals Wearable',
                    'weight_scale' => 'Weight Scale',
                    'blood_pressure' => 'Blood Pressure Monitor',
                    'pulse_oximeter' => 'Pulse Oximeter',
                ],
                'environmental' => [
                    'temperature' => 'Temperature Sensor',
                    'humidity' => 'Humidity Sensor',
                    'air_quality' => 'Air Quality Sensor',
                    'noise_level' => 'Noise Level Sensor',
                    'light_level' => 'Light Level Sensor',
                ],
                'appliance' => [
                    'smart_plug' => 'Smart Plug',
                    'cooker_monitor' => 'Cooker Monitor',
                    'water_heater_monitor' => 'Water Heater Monitor',
                ],
            ],

            DeviceDomain::ItInfrastructure->value => [
                'server' => [
                    'physical_server' => 'Physical Server',
                    'virtual_host' => 'Virtual Host',
                    'hypervisor' => 'Hypervisor',
                ],
                'storage' => [
                    'nas' => 'NAS',
                    'san' => 'SAN',
                    'backup_appliance' => 'Backup Appliance',
                ],
                'network' => [
                    'router' => 'Router',
                    'firewall' => 'Firewall',
                    'switch' => 'Switch',
                    'wireless_ap' => 'Wireless Access Point',
                    'lte_gateway' => 'LTE Gateway',
                    'five_g_gateway' => '5G Gateway',
                ],
                'power' => [
                    'ups' => 'UPS',
                    'pdu' => 'PDU',
                    'generator_monitor' => 'Generator Monitor',
                ],
                'endpoint' => [
                    'tablet' => 'Tablet',
                    'shared_device' => 'Shared Device',
                    'kiosk' => 'Kiosk',
                    'thin_client' => 'Thin Client',
                ],
                'voice' => [
                    'pbx' => 'PBX',
                    'sip_phone' => 'SIP Phone',
                    'intercom_server' => 'Intercom Server',
                ],
                'recording' => [
                    'nvr_infrastructure' => 'NVR (Infrastructure)',
                    'dvr_infrastructure' => 'DVR (Infrastructure)',
                    'video_server' => 'Video Server',
                ],
                'rack' => [
                    'rack_unit' => 'Rack Unit',
                    'patch_panel' => 'Patch Panel',
                    'cable_management' => 'Cable Management',
                ],
                'it_environmental' => [
                    'rack_temp_sensor' => 'Rack Temperature Sensor',
                    'rack_humidity' => 'Rack Humidity Sensor',
                    'water_leak_sensor' => 'Water Leak Sensor',
                ],
                'printer' => [
                    'printer' => 'Printer',
                    'mfp' => 'MFP',
                    'label_printer' => 'Label Printer',
                ],
            ],

            DeviceDomain::Facilities->value => [
                'leak_detection' => [
                    'water_sensor' => 'Water Sensor',
                    'pipe_sensor' => 'Pipe Sensor',
                ],
                'gas_detection' => [
                    'natural_gas' => 'Natural Gas Detector',
                    'co_detector' => 'CO Detector',
                    'lpg_detector' => 'LPG Detector',
                ],
                'cold_chain' => [
                    'freezer_sensor' => 'Freezer Sensor',
                    'fridge_sensor' => 'Fridge Sensor',
                    'cool_room_sensor' => 'Cool Room Sensor',
                ],
                'mechanical' => [
                    'generator_monitor' => 'Generator Monitor',
                    'pump_monitor' => 'Pump Monitor',
                    'hvac_sensor' => 'HVAC Sensor',
                ],
                'facility_access' => [
                    'gate_controller' => 'Gate Controller',
                    'barrier_controller' => 'Barrier Controller',
                    'smart_relay' => 'Smart Relay',
                ],
                'building_safety' => [
                    'fire_panel' => 'Fire Panel',
                    'sprinkler_monitor' => 'Sprinkler Monitor',
                    'emergency_lighting' => 'Emergency Lighting',
                ],
            ],
        ];
    }

    /**
     * Get all valid categories for a domain.
     *
     * @return array<string, string>  slug => label
     */
    public static function categoriesFor(string $domain): array
    {
        $tree = self::all()[$domain] ?? [];

        return array_map(
            fn(string $slug) => str_replace('_', ' ', ucfirst($slug)),
            array_combine(array_keys($tree), array_keys($tree))
        );
    }

    /**
     * Get all valid subcategories for a domain + category.
     *
     * @return array<string, string>  slug => label
     */
    public static function subcategoriesFor(string $domain, string $category): array
    {
        return self::all()[$domain][$category] ?? [];
    }

    /**
     * Validate that a domain/category/subcategory triple is known.
     * Returns true even if subcategory is null (categories don't require one).
     */
    public static function isValid(string $domain, string $category, ?string $subcategory = null): bool
    {
        $tree = self::all();

        if (!isset($tree[$domain][$category])) {
            return false;
        }

        if ($subcategory === null) {
            return true;
        }

        return isset($tree[$domain][$category][$subcategory]);
    }

    /**
     * All valid domain values.
     *
     * @return string[]
     */
    public static function domains(): array
    {
        return array_column(DeviceDomain::cases(), 'value');
    }
}

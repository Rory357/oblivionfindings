<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Data\DeviceIdentification;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Support\MacVendorLookup;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;

/**
 * Combines passive & active LAN discovery evidence (ONVIF WS-Discovery, SSDP/UPnP,
 * SNMP v2c/v3 system MIB, mDNS/DNS-SD, HTTP & TLS banners, open TCP ports, hostnames,
 * and IEEE OUI/MA-M MAC vendor assignments) into a validated device classification,
 * brand, model, confidence score, and plain-English explanation.
 */
final class DeviceFingerprintService
{
    /**
     * Official IANA Private Enterprise Numbers (1.3.6.1.4.1.<PEN>).
     *
     * @var array<int, string>
     */
    private const array SNMP_ENTERPRISE_VENDORS = [
        9 => 'Cisco',
        11 => 'HP',
        42 => 'Oracle / Sun',
        171 => 'D-Link',
        311 => 'Microsoft',
        318 => 'APC',
        368 => 'Axis',
        534 => 'Eaton',
        674 => 'Dell',
        1248 => 'Epson',
        1602 => 'Canon',
        2435 => 'Brother',
        2636 => 'Juniper',
        3375 => 'F5',
        4526 => 'Netgear',
        6574 => 'Synology',
        8072 => 'Linux (Net-SNMP)',
        11863 => 'TP-Link',
        12356 => 'Fortinet',
        14823 => 'HPE / Aruba',
        14988 => 'MikroTik',
        24681 => 'QNAP',
        25053 => 'Ruckus',
        25461 => 'Palo Alto',
        39165 => 'Hikvision',
        41112 => 'Ubiquiti',
        47196 => 'HPE / Aruba',
    ];

    public function __construct(
        private readonly MacVendorLookup $macVendors = new MacVendorLookup,
    ) {}

    public function identify(DiscoveredIdentity $identity): DeviceIdentification
    {
        return $this->identifyFromParts(
            snapshot: $identity->snapshot(),
            signals: $identity->sanitizedSignals(),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public function identifyFromSnapshot(array $snapshot): DeviceIdentification
    {
        $stored = $snapshot['identification'] ?? null;
        if (is_array($stored)
            && is_string($stored['domain'] ?? null)
            && is_string($stored['category'] ?? null)
            && is_string($stored['type_label'] ?? null)
            && is_string($stored['reason'] ?? null)
            && DeviceTaxonomy::isValid(
                $stored['domain'],
                $stored['category'],
                is_string($stored['subcategory'] ?? null) ? $stored['subcategory'] : null,
            )) {
            $confidence = is_int($stored['confidence'] ?? null)
                ? max(0, min(100, $stored['confidence']))
                : 50;

            return new DeviceIdentification(
                domain: $stored['domain'],
                category: $stored['category'],
                subcategory: is_string($stored['subcategory'] ?? null) ? $stored['subcategory'] : null,
                typeLabel: $stored['type_label'],
                manufacturer: is_string($stored['manufacturer'] ?? null) && $stored['manufacturer'] !== ''
                    ? $stored['manufacturer']
                    : null,
                model: is_string($stored['model'] ?? null) && $stored['model'] !== ''
                    ? $stored['model']
                    : null,
                macVendor: is_string($stored['mac_vendor'] ?? null) && $stored['mac_vendor'] !== ''
                    ? $stored['mac_vendor']
                    : null,
                suggestedName: is_string($stored['suggested_name'] ?? null) && $stored['suggested_name'] !== ''
                    ? $stored['suggested_name']
                    : 'Discovered device',
                confidence: $confidence,
                confidenceBand: $this->confidenceBand($confidence),
                reason: $stored['reason'],
            );
        }

        $signals = is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : [];

        return $this->identifyFromParts($snapshot, $signals);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $signals
     */
    private function identifyFromParts(array $snapshot, array $signals): DeviceIdentification
    {
        $firstMac = is_string(($snapshot['mac_addresses'] ?? [])[0] ?? null)
            ? MacVendorLookup::normaliseUnicastMac($snapshot['mac_addresses'][0])
            : null;
        $firstIp = is_string(($snapshot['addresses'] ?? [])[0] ?? null)
            ? trim($snapshot['addresses'][0])
            : null;
        $hostname = $this->cleanDisplayHostname(
            $signals['snmp_sys_name']
                ?? $signals['mdns_hostname']
                ?? $signals['ptr_hostname']
                ?? $snapshot['hostname']
                ?? null,
        );

        $macVendor = is_string($signals['mac_vendor'] ?? null) && trim($signals['mac_vendor']) !== ''
            ? trim($signals['mac_vendor'])
            : ($firstMac !== null ? $this->macVendors->lookup($firstMac) : null);
        $locallyAdministered = is_bool($signals['locally_administered_mac'] ?? null)
            ? $signals['locally_administered_mac']
            : ($firstMac !== null && MacVendorLookup::isLocallyAdministered($firstMac));

        $openPorts = $this->extractOpenPorts($snapshot, $signals);
        $mdnsServices = $this->stringList($signals['mdns_services'] ?? []);
        $onvifScopes = $this->stringList($signals['onvif_scopes'] ?? []);
        $onvifParsed = $this->parseOnvifScopes($onvifScopes);

        $snmpPenVendor = $this->vendorFromSnmpObjectId(
            is_string($signals['snmp_sys_object_id'] ?? null)
                ? $signals['snmp_sys_object_id']
                : $this->extractSnmpOidFromFingerprint($snapshot['fingerprint'] ?? null),
        );

        $manufacturer = $this->resolveManufacturer(
            signals: $signals,
            onvifParsed: $onvifParsed,
            snmpPenVendor: $snmpPenVendor,
            macVendor: $macVendor,
            hostname: $hostname,
        );

        $model = $this->resolveModel(
            signals: $signals,
            onvifParsed: $onvifParsed,
            snapshot: $snapshot,
        );
        if ($manufacturer !== null && $model !== null) {
            if (strcasecmp($model, $manufacturer) === 0) {
                $model = null;
            } elseif (stripos($model, $manufacturer.' ') === 0) {
                $stripped = trim(substr($model, strlen($manufacturer) + 1));
                $model = $stripped !== '' ? $stripped : null;
            }
        }
        if ($model === null
            && $manufacturer === 'TP-Link'
            && $hostname !== null
            && preg_match('/^(?:tapo[-_ ]?)?(c[1-5]\d{2}[a-z0-9]*)(?:\.|$)/i', $hostname, $tapoMatch) === 1) {
            $model = 'Tapo '.strtoupper($tapoMatch[1]);
        }

        [
            'domain' => $domain,
            'category' => $category,
            'subcategory' => $subcategory,
            'type_label' => $typeLabel,
            'confidence' => $confidence,
            'evidence_notes' => $evidenceNotes,
        ] = $this->classify(
            signals: $signals,
            onvifParsed: $onvifParsed,
            mdnsServices: $mdnsServices,
            openPorts: $openPorts,
            manufacturer: $manufacturer,
            model: $model,
            macVendor: $macVendor,
            locallyAdministered: $locallyAdministered,
            hostname: $hostname,
            firstIp: $firstIp,
            firstMac: $firstMac,
        );

        if (! DeviceTaxonomy::isValid($domain, $category, $subcategory)) {
            if (DeviceTaxonomy::isValid($domain, $category, null)) {
                $subcategory = null;
            } else {
                $domain = 'it_infrastructure';
                $category = 'network';
                $subcategory = 'switch';
            }
        }

        $confidence = max(20, min(99, $confidence));
        $reason = $this->formatReason(
            typeLabel: $typeLabel,
            manufacturer: $manufacturer,
            model: $model,
            evidenceNotes: $evidenceNotes,
        );
        $suggestedName = $this->buildSuggestedName(
            typeLabel: $typeLabel,
            manufacturer: $manufacturer,
            model: $model,
            upnpFriendlyName: is_string($signals['upnp_friendly_name'] ?? null) ? $signals['upnp_friendly_name'] : null,
            onvifName: $onvifParsed['name'] ?? (is_string($signals['onvif_name'] ?? null) ? $signals['onvif_name'] : null),
            hostname: $hostname,
            firstIp: $firstIp,
        );

        return new DeviceIdentification(
            domain: $domain,
            category: $category,
            subcategory: $subcategory,
            typeLabel: $typeLabel,
            manufacturer: $manufacturer,
            model: $model,
            macVendor: $macVendor,
            suggestedName: $suggestedName,
            confidence: $confidence,
            confidenceBand: $this->confidenceBand($confidence),
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array{manufacturer: ?string, hardware: ?string, name: ?string, is_ptz: bool, is_nvr: bool}  $onvifParsed
     * @param  list<string>  $mdnsServices
     * @param  list<int>  $openPorts
     * @return array{domain: string, category: string, subcategory: ?string, type_label: string, confidence: int, evidence_notes: list<string>}
     */
    private function classify(
        array $signals,
        array $onvifParsed,
        array $mdnsServices,
        array $openPorts,
        ?string $manufacturer,
        ?string $model,
        ?string $macVendor,
        bool $locallyAdministered,
        ?string $hostname,
        ?string $firstIp,
        ?string $firstMac,
    ): array {
        $notes = [];
        $onvifTypes = strtolower((string) ($signals['onvif_types'] ?? ''));
        $hasOnvif = $onvifTypes !== '' || ($signals['onvif_scopes'] ?? []) !== [] || isset($signals['onvif_hardware']);
        $upnpType = strtolower((string) ($signals['upnp_device_type'] ?? $signals['ssdp_st'] ?? ''));
        $upnpFriendly = (string) ($signals['upnp_friendly_name'] ?? '');
        $httpCombined = strtolower(implode(' ', array_filter([
            $signals['http_server'] ?? null,
            $signals['http_title'] ?? null,
            $signals['http_auth_realm'] ?? null,
            $signals['tls_common_name'] ?? null,
            $signals['tls_organization'] ?? null,
        ], 'is_string')));
        $snmpDescr = strtolower((string) ($signals['snmp_sys_descr'] ?? ''));
        $modelLower = strtolower((string) ($model ?? ''));
        $hostLower = strtolower((string) ($hostname ?? ''));

        // 1. ONVIF WS-Discovery (highest confidence camera / NVR identification)
        if ($hasOnvif || in_array('_onvif._tcp', $mdnsServices, true)) {
            $notes[] = $hasOnvif ? 'ONVIF WS-Discovery' : 'mDNS ONVIF service';
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }
            if ($onvifParsed['is_nvr'] || str_contains($onvifTypes, 'networkvideostorage') || str_contains($modelLower, 'nvr')) {
                return [
                    'domain' => 'security',
                    'category' => 'cctv',
                    'subcategory' => 'nvr',
                    'type_label' => 'ONVIF recorder (NVR)',
                    'confidence' => $manufacturer !== null ? 95 : 90,
                    'evidence_notes' => $notes,
                ];
            }

            $isPtz = $onvifParsed['is_ptz'] || str_contains($modelLower, 'ptz');
            $sub = $isPtz
                ? 'ptz_camera'
                : (preg_match('/\b(?:dome|turret)\b/', $modelLower) === 1
                    ? 'dome_camera'
                    : (str_contains($modelLower, 'bullet') ? 'bullet_camera' : 'ip_camera'));

            return [
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => $sub,
                'type_label' => $isPtz ? 'ONVIF PTZ camera' : 'ONVIF camera',
                'confidence' => $manufacturer !== null ? 95 : 89,
                'evidence_notes' => $notes,
            ];
        }

        // 2. Camera / NVR via RTSP, UniFi Protect, Hikvision / Dahua / Axis banners or ports
        $isCameraBrand = in_array($manufacturer, [
            'Hikvision', 'Dahua', 'Axis', 'Reolink', 'Uniview', 'Hanwha Vision', 'Vivotek', 'Avigilon',
        ], true);
        if (in_array(7442, $openPorts, true) || in_array(7443, $openPorts, true) || str_contains($httpCombined, 'unifi protect')) {
            $notes[] = 'UniFi Protect port/banner';
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'nvr',
                'type_label' => 'UniFi Protect recorder',
                'confidence' => 91,
                'evidence_notes' => $notes,
            ];
        }

        if (in_array(554, $openPorts, true)
            || in_array('_rtsp._tcp', $mdnsServices, true)
            || ($isCameraBrand && (in_array(8000, $openPorts, true) || in_array(37777, $openPorts, true) || str_contains($httpCombined, 'dnvrs') || str_contains($httpCombined, 'webcamera') || str_contains($httpCombined, 'app-webs')))) {
            if (in_array(554, $openPorts, true) || in_array('_rtsp._tcp', $mdnsServices, true)) {
                $notes[] = 'RTSP video port';
            }
            if (in_array(8000, $openPorts, true)) {
                $notes[] = 'Hikvision SDK port 8000';
            }
            if (in_array(37777, $openPorts, true)) {
                $notes[] = 'Dahua media port 37777';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            $isNvr = str_contains($httpCombined, 'nvr') || str_contains($modelLower, 'nvr') || str_contains($hostLower, 'nvr');

            return [
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => $isNvr ? 'nvr' : 'ip_camera',
                'type_label' => $isNvr ? 'Network video recorder' : 'IP camera',
                'confidence' => $isCameraBrand ? 90 : 80,
                'evidence_notes' => $notes ?: ['video service banner'],
            ];
        }

        if (($manufacturer === 'TP-Link' && str_starts_with($modelLower, 'tapo c'))
            || preg_match('/^(?:cubo-ai|reolink|wyze-cam|eufy-cam|arlo)[-_.]/', $hostLower) === 1) {
            $notes[] = 'camera hostname';
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'ip_camera',
                'type_label' => 'IP camera',
                'confidence' => 82,
                'evidence_notes' => $notes,
            ];
        }

        // 3. Network printers (IPP, JetDirect 9100, LPD 515, UPnP Printer, SNMP printer)
        $hasPrinterService = count(array_intersect($mdnsServices, [
            '_ipp._tcp', '_ipps._tcp', '_pdl-datastream._tcp', '_printer._tcp',
        ])) > 0;
        $hasPrinterPort = in_array(9100, $openPorts, true) || in_array(631, $openPorts, true) || in_array(515, $openPorts, true);
        if ($hasPrinterService
            || str_contains($upnpType, 'printer')
            || ($hasPrinterPort && (in_array($manufacturer, ['Brother', 'HP', 'Epson', 'Canon', 'Ricoh', 'Kyocera', 'Konica Minolta'], true) || in_array(9100, $openPorts, true)))
            || preg_match('/\b(?:jetdirect|laserjet|officejet|brother|epson|pixma|imageclass)\b/', $snmpDescr.' '.$httpCombined) === 1) {
            if ($hasPrinterService) {
                $notes[] = 'mDNS print service';
            }
            if ($hasPrinterPort) {
                $notes[] = 'print port';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'printer',
                'subcategory' => 'printer',
                'type_label' => 'Network printer',
                'confidence' => ($hasPrinterService || $manufacturer !== null) ? 90 : 82,
                'evidence_notes' => $notes ?: ['print banner'],
            ];
        }

        // 4. NAS Storage (Synology / QNAP / TrueNAS)
        if (in_array($manufacturer, ['Synology', 'QNAP'], true)
            || str_contains($httpCombined, 'synology')
            || str_contains($httpCombined, 'diskstation')
            || str_contains($httpCombined, 'qnap')
            || str_contains($snmpDescr, 'synology')
            || (in_array(5000, $openPorts, true) && in_array(5001, $openPorts, true))) {
            if (in_array(5000, $openPorts, true) || in_array(5001, $openPorts, true)) {
                $notes[] = 'NAS management port';
            }
            if ($snmpDescr !== '') {
                $notes[] = 'SNMP system description';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'storage',
                'subcategory' => 'nas',
                'type_label' => 'NAS storage',
                'confidence' => $manufacturer !== null ? 91 : 80,
                'evidence_notes' => $notes ?: ['NAS banner'],
            ];
        }

        // 5. UPS / Power infrastructure
        if (in_array($manufacturer, ['APC', 'Eaton'], true) || preg_match('/\b(?:smart-ups|network management card|eaton ups)\b/', $snmpDescr.' '.$httpCombined) === 1) {
            if ($snmpDescr !== '') {
                $notes[] = 'SNMP system description';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'power',
                'subcategory' => 'ups',
                'type_label' => 'UPS power backup',
                'confidence' => 88,
                'evidence_notes' => $notes ?: ['UPS signature'],
            ];
        }

        // 6. Access control & alarm panels (Gallagher, ICT, Paradox, Texecom, Bosch, UniFi Access)
        if (in_array($manufacturer, ['Gallagher', 'ICT', 'Paradox', 'Texecom'], true)) {
            $isAlarm = in_array($manufacturer, ['Paradox', 'Texecom'], true);
            $notes[] = 'IEEE MAC vendor';

            return [
                'domain' => 'security',
                'category' => $isAlarm ? 'alarm' : 'access_control',
                'subcategory' => $isAlarm ? 'panel' : 'access_panel',
                'type_label' => $isAlarm ? 'Alarm panel' : 'Access controller',
                'confidence' => 82,
                'evidence_notes' => $notes,
            ];
        }

        // 7. Network Gateways, Firewalls, Switches & Wireless APs
        $isGatewayIp = is_string($firstIp) && preg_match('/\.(?:1|254)$/', $firstIp) === 1;
        $isNetworkVendor = in_array($manufacturer, [
            'Ubiquiti', 'Cisco', 'MikroTik', 'HPE / Aruba', 'Fortinet', 'Juniper',
            'TP-Link', 'Netgear', 'D-Link', 'ASUS', 'Ruckus', 'Palo Alto',
        ], true);

        if (str_contains($upnpType, 'internetgatewaydevice')
            || str_contains($upnpType, 'wandevice')
            || preg_match('/\b(?:routeros|edgeos|unifi os|unifi express|dream machine|udm|ux7|fortigate|pfsense|opnsense)\b/', $snmpDescr.' '.$httpCombined.' '.strtolower($upnpFriendly).' '.$modelLower) === 1
            || ($isGatewayIp && ($isNetworkVendor || in_array(53, $openPorts, true)))) {
            if (str_contains($upnpType, 'internetgatewaydevice') || str_contains($upnpType, 'wandevice')) {
                $notes[] = 'UPnP InternetGatewayDevice';
            }
            if ($snmpDescr !== '') {
                $notes[] = 'SNMP system description';
            }
            if ($httpCombined !== '') {
                $notes[] = 'HTTPS/HTTP banner';
            }
            if (in_array(53, $openPorts, true)) {
                $notes[] = 'DNS service port 53';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            $isFirewall = $manufacturer === 'Fortinet' || $manufacturer === 'Palo Alto'
                || preg_match('/\b(?:fortigate|pfsense|opnsense|firewall)\b/', $snmpDescr.' '.$httpCombined) === 1;

            return [
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => $isFirewall ? 'firewall' : 'router',
                'type_label' => $isFirewall ? 'Firewall / gateway' : 'Network gateway',
                'confidence' => $manufacturer !== null ? 91 : 83,
                'evidence_notes' => $notes ?: ['gateway address and network ports'],
            ];
        }

        if (preg_match('/\b(?:u6-|u7-|uap-|wireless|access point|aruba ap|ruckus)\b/', $snmpDescr.' '.$httpCombined.' '.$modelLower.' '.$hostLower) === 1) {
            if ($snmpDescr !== '') {
                $notes[] = 'SNMP system description';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'wireless_ap',
                'type_label' => 'Wireless access point',
                'confidence' => 89,
                'evidence_notes' => $notes ?: ['wireless AP signature'],
            ];
        }

        if (preg_match('/\b(?:usw-|catalyst|procurve|edgeswitch|crs\d|css\d|switch)\b/', $snmpDescr.' '.$httpCombined.' '.$modelLower.' '.$hostLower) === 1) {
            if ($snmpDescr !== '') {
                $notes[] = 'SNMP system description';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'switch',
                'type_label' => 'Network switch',
                'confidence' => 88,
                'evidence_notes' => $notes ?: ['switch signature'],
            ];
        }

        // 8. Hypervisors & Servers (Proxmox, VMware, Home Assistant)
        if (str_contains($httpCombined, 'proxmox') || in_array(8006, $openPorts, true) || $manufacturer === 'VMware') {
            $notes[] = str_contains($httpCombined, 'proxmox') ? 'Proxmox web banner' : 'hypervisor signature';

            return [
                'domain' => 'it_infrastructure',
                'category' => 'server',
                'subcategory' => 'hypervisor',
                'type_label' => 'Virtualisation host',
                'confidence' => 90,
                'evidence_notes' => $notes,
            ];
        }

        // 9. VoIP / SIP phones
        if (in_array(5060, $openPorts, true) || in_array(5061, $openPorts, true)
            || in_array($manufacturer, ['Grandstream', 'Yealink', 'Snom', 'Poly'], true)) {
            if (in_array(5060, $openPorts, true) || in_array(5061, $openPorts, true)) {
                $notes[] = 'SIP voice port';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'voice',
                'subcategory' => 'sip_phone',
                'type_label' => 'VoIP / SIP phone',
                'confidence' => 85,
                'evidence_notes' => $notes ?: ['VoIP signature'],
            ];
        }

        // 10. Smart TVs, Speakers & Cast / AirPlay media displays
        $hasMediaMdns = count(array_intersect($mdnsServices, [
            '_googlecast._tcp', '_airplay._tcp', '_raop._tcp', '_sonos._tcp', '_spotify-connect._tcp',
        ])) > 0;
        if ($hasMediaMdns
            || str_contains($upnpType, 'mediarenderer')
            || str_contains($upnpType, 'dial-multiscreen')
            || in_array($manufacturer, ['Sonos', 'LG', 'Sony'], true)) {
            if ($hasMediaMdns) {
                $notes[] = 'mDNS Cast/AirPlay service';
            } elseif ($upnpType !== '') {
                $notes[] = 'SSDP UPnP media profile';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => 'media_display',
                'type_label' => 'Smart TV / media device',
                'confidence' => 84,
                'evidence_notes' => $notes ?: ['media device vendor'],
            ];
        }

        // 11. IoT / Environmental sensors & LoRaWAN / Smart plugs (Milesight, Shelly, Hue, ESPHome, HomeKit, MQTT)
        $hasIotMdns = count(array_intersect($mdnsServices, [
            '_hap._tcp', '_homekit._tcp', '_matter._tcp', '_esphomelib._tcp', '_shelly._tcp', '_hue._tcp', '_mqtt._tcp',
        ])) > 0;
        if ($manufacturer === 'Milesight'
            || $hasIotMdns
            || in_array($manufacturer, ['Shelly', 'Philips Hue', 'Espressif'], true)
            || in_array(1883, $openPorts, true)) {
            if ($hasIotMdns) {
                $notes[] = 'mDNS IoT service';
            }
            if (in_array(1883, $openPorts, true)) {
                $notes[] = 'MQTT port 1883';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            $isPlug = in_array($manufacturer, ['Shelly', 'Philips Hue'], true);

            return [
                'domain' => 'iot_healthcare',
                'category' => $isPlug ? 'appliance' : 'environmental',
                'subcategory' => $isPlug ? 'smart_plug' : 'temperature',
                'type_label' => $manufacturer === 'Milesight' ? 'IoT sensor / gateway' : 'Smart IoT device',
                'confidence' => $manufacturer !== null ? 82 : 76,
                'evidence_notes' => $notes ?: ['IoT signature'],
            ];
        }

        if ($manufacturer === 'Queclink') {
            return [
                'domain' => 'tracking',
                'category' => 'vehicle_tracker',
                'subcategory' => 'hardwired_gps',
                'type_label' => 'GPS tracker',
                'confidence' => 80,
                'evidence_notes' => ['IEEE MAC vendor'],
            ];
        }

        // 12. Windows & Linux Computers / Workstations / Laptops
        if (in_array(3389, $openPorts, true)
            || in_array(5985, $openPorts, true)
            || in_array(5986, $openPorts, true)
            || in_array(445, $openPorts, true)
            || in_array(135, $openPorts, true)
            || str_contains($snmpDescr, 'windows')) {
            if (in_array(3389, $openPorts, true)) {
                $notes[] = 'Remote Desktop port 3389';
            } elseif (in_array(5985, $openPorts, true) || in_array(5986, $openPorts, true)) {
                $notes[] = 'WinRM management port';
            } elseif (in_array(445, $openPorts, true) || in_array(135, $openPorts, true)) {
                $notes[] = 'Windows SMB/RPC port';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => 'workstation',
                'type_label' => 'Windows computer',
                'confidence' => 84,
                'evidence_notes' => $notes ?: ['Windows system profile'],
            ];
        }

        if (in_array('_workstation._tcp', $mdnsServices, true)
            || in_array('_smb._tcp', $mdnsServices, true)
            || str_contains($snmpDescr, 'linux')
            || $manufacturer === 'Raspberry Pi'
            || (in_array(22, $openPorts, true) && ! $isNetworkVendor)) {
            if (in_array(22, $openPorts, true)) {
                $notes[] = 'SSH port 22';
            }
            if (in_array('_workstation._tcp', $mdnsServices, true) || in_array('_smb._tcp', $mdnsServices, true)) {
                $notes[] = 'mDNS workstation service';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            $isMacBook = $manufacturer === 'Apple' && preg_match('/\b(?:macbook|book)\b/', $modelLower.' '.$hostLower) === 1;

            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => $isMacBook ? 'laptop' : 'workstation',
                'type_label' => $manufacturer === 'Raspberry Pi'
                    ? 'Linux mini-PC'
                    : ($manufacturer === 'Apple' ? 'Mac computer' : 'Linux / workstation host'),
                'confidence' => 76,
                'evidence_notes' => $notes ?: ['host signature'],
            ];
        }

        // 13. Vendor OUI fallbacks when ports are closed / filtered
        if ($isCameraBrand) {
            return [
                'domain' => 'security',
                'category' => 'cctv',
                'subcategory' => 'ip_camera',
                'type_label' => 'Security camera / recorder',
                'confidence' => 68,
                'evidence_notes' => ['IEEE MAC vendor'],
            ];
        }

        if ($isNetworkVendor) {
            return [
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'subcategory' => 'wireless_ap',
                'type_label' => 'Network device',
                'confidence' => 70,
                'evidence_notes' => ['IEEE MAC vendor'],
            ];
        }

        if (in_array($manufacturer, ['Intel', 'Realtek', 'Dell', 'Lenovo', 'Microsoft'], true)
            || preg_match('/^(?:desktop|laptop|win|pc)-/i', (string) $hostname) === 1) {
            if ($hostname !== null) {
                $notes[] = 'hostname';
            }
            if ($macVendor !== null) {
                $notes[] = 'IEEE MAC vendor';
            }

            $isLaptop = str_starts_with(strtolower((string) $hostname), 'laptop-');

            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => $isLaptop ? 'laptop' : 'workstation',
                'type_label' => $isLaptop ? 'Laptop computer' : 'Computer / workstation',
                'confidence' => 66,
                'evidence_notes' => $notes ?: ['NIC vendor'],
            ];
        }

        if (in_array($manufacturer, ['Apple', 'Samsung', 'Google'], true)) {
            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => 'mobile_device',
                'type_label' => 'Phone / connected device',
                'confidence' => 60,
                'evidence_notes' => ['IEEE MAC vendor'],
            ];
        }

        if ($locallyAdministered && $openPorts === []) {
            return [
                'domain' => 'it_infrastructure',
                'category' => 'endpoint',
                'subcategory' => 'mobile_device',
                'type_label' => 'Wi-Fi client (private MAC)',
                'confidence' => 45,
                'evidence_notes' => ['locally administered MAC on ARP/ping'],
            ];
        }

        if ($macVendor !== null) {
            $notes[] = 'IEEE MAC vendor';
        }
        if ($openPorts !== []) {
            $notes[] = 'open TCP '.implode(', ', array_slice($openPorts, 0, 4));
        } elseif (($signals['arp_seen'] ?? false) === true && $firstMac !== null) {
            $notes[] = 'local ARP table';
        } else {
            $notes[] = 'ping response';
        }

        return [
            'domain' => 'it_infrastructure',
            'category' => $openPorts !== [] ? 'network' : 'endpoint',
            'subcategory' => $openPorts !== [] ? 'switch' : 'workstation',
            'type_label' => $openPorts !== [] ? 'Network appliance' : 'Network host',
            'confidence' => $macVendor !== null ? 55 : 35,
            'evidence_notes' => $notes,
        ];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{manufacturer: ?string, hardware: ?string, name: ?string, is_ptz: bool, is_nvr: bool}
     */
    private function parseOnvifScopes(array $scopes): array
    {
        $manufacturer = null;
        $hardware = null;
        $name = null;
        $isPtz = false;
        $isNvr = false;

        foreach ($scopes as $scope) {
            $decoded = rawurldecode($scope);
            $lower = strtolower($decoded);
            if (str_contains($lower, 'ptz')) {
                $isPtz = true;
            }
            if (str_contains($lower, 'networkvideostorage') || str_contains($lower, 'recording')) {
                $isNvr = true;
            }
            if (preg_match('~onvif://www\.onvif\.org/hardware/(.+)$~i', $decoded, $m) === 1) {
                $hardware = trim($m[1]);
            } elseif (preg_match('~onvif://www\.onvif\.org/name/(.+)$~i', $decoded, $m) === 1) {
                $name = trim($m[1]);
            } elseif (preg_match('~onvif://www\.onvif\.org/(?:manufacturer|mfr)/(.+)$~i', $decoded, $m) === 1) {
                $manufacturer = trim($m[1]);
            }
        }

        return [
            'manufacturer' => $manufacturer,
            'hardware' => $hardware,
            'name' => $name,
            'is_ptz' => $isPtz,
            'is_nvr' => $isNvr,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array{manufacturer: ?string, hardware: ?string, name: ?string, is_ptz: bool, is_nvr: bool}  $onvifParsed
     */
    private function resolveManufacturer(
        array $signals,
        array $onvifParsed,
        ?string $snmpPenVendor,
        ?string $macVendor,
        ?string $hostname,
    ): ?string {
        $candidates = [
            $onvifParsed['manufacturer'],
            $signals['snmp_manufacturer'] ?? null,
            $signals['upnp_manufacturer'] ?? null,
            $snmpPenVendor !== 'Linux (Net-SNMP)' ? $snmpPenVendor : null,
            $signals['tls_organization'] ?? null,
            $onvifParsed['name'],
            $signals['http_server'] ?? null,
            $signals['http_title'] ?? null,
            $signals['http_auth_realm'] ?? null,
            $signals['ssdp_server'] ?? null,
            $signals['snmp_sys_descr'] ?? null,
            $macVendor,
        ];

        // First prefer explicit vendor / OUI matches that resolve to a known brand.
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $brand = MacVendorLookup::canonicalBrand($candidate);
            if ($brand !== null && $this->isKnownBrand($brand)) {
                return $brand;
            }
        }

        // Next fall back to explicit UPnP / SNMP / ONVIF / IEEE OUI vendor name.
        foreach ([
            $onvifParsed['manufacturer'],
            $signals['snmp_manufacturer'] ?? null,
            $signals['upnp_manufacturer'] ?? null,
            $macVendor,
        ] as $explicit) {
            if (is_string($explicit) && trim($explicit) !== '') {
                return MacVendorLookup::canonicalBrand($explicit);
            }
        }

        if ($hostname !== null) {
            $hostBrand = MacVendorLookup::canonicalBrand($hostname);
            if ($hostBrand !== null && $this->isKnownBrand($hostBrand)) {
                return $hostBrand;
            }
        }

        return null;
    }

    private function isKnownBrand(string $brand): bool
    {
        return in_array($brand, [
            'Ubiquiti', 'Hikvision', 'Dahua', 'Axis', 'Milesight', 'Queclink', 'MikroTik',
            'HPE / Aruba', 'HP', 'Cisco', 'Fortinet', 'Juniper', 'Synology', 'QNAP',
            'Brother', 'Epson', 'Canon', 'Ricoh', 'Kyocera', 'Konica Minolta',
            'Reolink', 'Uniview', 'Hanwha Vision', 'Vivotek', 'Avigilon',
            'Gallagher', 'ICT', 'Paradox', 'Texecom', 'Bosch', 'APC', 'Eaton',
            'Grandstream', 'Yealink', 'Snom', 'Poly', 'Raspberry Pi', 'Espressif',
            'Shelly', 'Philips Hue', 'Sonos', 'TP-Link', 'Netgear', 'D-Link', 'ASUS',
            'Apple', 'Samsung', 'Intel', 'Realtek', 'Dell', 'Lenovo', 'Microsoft',
            'Google', 'Amazon', 'LG', 'Sony', 'Proxmox', 'VMware', 'QEMU / KVM',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array{manufacturer: ?string, hardware: ?string, name: ?string, is_ptz: bool, is_nvr: bool}  $onvifParsed
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveModel(array $signals, array $onvifParsed, array $snapshot): ?string
    {
        $candidates = [
            $signals['onvif_hardware'] ?? null,
            $onvifParsed['hardware'],
            $signals['snmp_model'] ?? null,
            $signals['upnp_model_name'] ?? null,
            $signals['mdns_model'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $clean = trim($candidate);
                if ($clean !== '' && strtolower($clean) !== 'unknown' && strlen($clean) <= 100) {
                    return $clean;
                }
            }
        }

        // Check fingerprint for SNMP model suffix: snmp:<oid>:<model>
        $fingerprint = is_string($snapshot['fingerprint'] ?? null) ? $snapshot['fingerprint'] : '';
        if (preg_match('/^snmp:[0-9.]+:(.+)$/i', $fingerprint, $m) === 1) {
            $fpModel = strtoupper(trim($m[1]));
            if ($fpModel !== '' && strlen($fpModel) <= 80) {
                return $fpModel;
            }
        }

        // Extract Ubiquiti / Hikvision / MikroTik model tokens from sysDescr or HTTP title if present.
        $banner = implode(' ', array_filter([
            $signals['snmp_sys_descr'] ?? null,
            $signals['http_title'] ?? null,
            $onvifParsed['name'],
        ], 'is_string'));
        if (preg_match('/\b(DS-[A-Z0-9-]{4,24}|IPC-[A-Z0-9-]{4,24}|DH-[A-Z0-9-]{4,24}|UX7|UDM(?:-Pro|-SE)?|U6-[A-Za-z0-9-]+|U7-[A-Za-z0-9-]+|USW-[A-Za-z0-9-]+|CCR\d+[A-Za-z0-9-]*|RB\d+[A-Za-z0-9-]*)\b/i', $banner, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function vendorFromSnmpObjectId(?string $oid): ?string
    {
        if ($oid === null) {
            return null;
        }
        $clean = ltrim(trim($oid), '.');
        if (preg_match('/^1\.3\.6\.1\.4\.1\.(\d+)(?:\.|$)/', $clean, $m) !== 1) {
            return null;
        }

        $pen = (int) $m[1];

        return self::SNMP_ENTERPRISE_VENDORS[$pen] ?? null;
    }

    private function extractSnmpOidFromFingerprint(mixed $fingerprint): ?string
    {
        if (! is_string($fingerprint)) {
            return null;
        }
        if (preg_match('/^snmp:(\d+(?:\.\d+)+)(?::|$)/', $fingerprint, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $signals
     * @return list<int>
     */
    private function extractOpenPorts(array $snapshot, array $signals): array
    {
        $ports = [];
        if (is_array($signals['open_ports'] ?? null)) {
            foreach ($signals['open_ports'] as $port) {
                if (is_int($port) && $port >= 1 && $port <= 65535) {
                    $ports[] = $port;
                }
            }
        }

        $fingerprint = is_string($snapshot['fingerprint'] ?? null) ? $snapshot['fingerprint'] : '';
        if (preg_match('/:([0-9,]+)$/', $fingerprint, $m) === 1) {
            foreach (explode(',', $m[1]) as $part) {
                if (ctype_digit($part)) {
                    $port = (int) $part;
                    if ($port >= 1 && $port <= 65535) {
                        $ports[] = $port;
                    }
                }
            }
        }

        $ports = array_values(array_unique($ports));
        sort($ports);

        return $ports;
    }

    /** @param list<string> $evidenceNotes */
    private function formatReason(
        string $typeLabel,
        ?string $manufacturer,
        ?string $model,
        array $evidenceNotes,
    ): string {
        $headline = $typeLabel;
        $brandModel = trim(implode(' ', array_filter([
            $manufacturer,
            ($model !== null && ($manufacturer === null || ! str_starts_with(strtolower($model), strtolower($manufacturer))))
                ? $model
                : null,
        ])));
        if ($brandModel !== '') {
            $headline .= ', '.$brandModel;
        }

        $uniqueNotes = array_values(array_unique(array_filter($evidenceNotes)));
        if ($uniqueNotes === []) {
            return $headline;
        }

        return $headline.' — matched '.implode(' + ', array_slice($uniqueNotes, 0, 3));
    }

    private function buildSuggestedName(
        string $typeLabel,
        ?string $manufacturer,
        ?string $model,
        ?string $upnpFriendlyName,
        ?string $onvifName,
        ?string $hostname,
        ?string $firstIp,
    ): string {
        foreach ([$upnpFriendlyName, $onvifName] as $friendly) {
            if (is_string($friendly)) {
                $clean = trim($friendly);
                if ($clean !== '' && strlen($clean) <= 80 && filter_var($clean, FILTER_VALIDATE_IP) === false) {
                    return $clean;
                }
            }
        }

        if ($hostname !== null) {
            return $hostname;
        }

        $brandModel = trim(implode(' ', array_filter([
            $manufacturer,
            ($model !== null && ($manufacturer === null || ! str_starts_with(strtolower($model), strtolower($manufacturer))))
                ? $model
                : null,
        ])));

        if ($brandModel !== '' && $firstIp !== null) {
            return "{$brandModel} ({$firstIp})";
        }
        if ($brandModel !== '') {
            return $brandModel;
        }
        if ($firstIp !== null) {
            return "{$typeLabel} ({$firstIp})";
        }

        return 'Discovered device';
    }

    private function cleanDisplayHostname(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw, " \t\n\r\0\x0B.");
        if (str_ends_with(strtolower($trimmed), '.local')) {
            $trimmed = substr($trimmed, 0, -6);
        } elseif (str_ends_with(strtolower($trimmed), '.lan')) {
            $trimmed = substr($trimmed, 0, -4);
        } elseif (str_ends_with(strtolower($trimmed), '.home.arpa')) {
            $trimmed = substr($trimmed, 0, -10);
        }
        if ($trimmed === '' || strlen($trimmed) > 253 || filter_var($trimmed, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/i', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    private function confidenceBand(int $confidence): string
    {
        return match (true) {
            $confidence >= 80 => 'high',
            $confidence >= 55 => 'medium',
            default => 'low',
        };
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        ));
    }
}

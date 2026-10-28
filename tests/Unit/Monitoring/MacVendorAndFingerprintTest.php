<?php

namespace Tests\Unit\Monitoring;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Services\DeviceFingerprintService;
use App\Domain\Monitoring\Discovery\Support\MacVendorLookup;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use PHPUnit\Framework\TestCase;

final class MacVendorAndFingerprintTest extends TestCase
{
    /**
     * @param  list<string>  $addresses
     * @param  list<string>  $macAddresses
     * @param  array<string, mixed>  $signals
     */
    private function makeIdentity(
        ?string $provider = null,
        ?string $hostname = null,
        array $addresses = [],
        array $macAddresses = [],
        ?string $hardwareId = null,
        ?string $fingerprint = null,
        array $signals = [],
    ): DiscoveredIdentity {
        return new DiscoveredIdentity(
            provider: $provider,
            providerId: null,
            serialNumber: null,
            hardwareId: $hardwareId,
            macAddresses: $macAddresses,
            certificateFingerprint: null,
            hostname: $hostname,
            addresses: $addresses,
            fingerprint: $fingerprint,
            signals: $signals,
        );
    }

    public function test_mac_vendor_lookup_resolves_ieee_oui_and_mam_and_skips_randomised_macs(): void
    {
        $lookup = new MacVendorLookup;

        $this->assertStringContainsString('Ubiquiti', (string) $lookup->lookup('24:5A:4C:01:02:03'));
        $this->assertStringContainsString('Hikvision', (string) $lookup->lookup('44:19:B6:11:22:33'));
        $this->assertStringContainsString('Axis', (string) $lookup->lookup('AC:CC:8E:AA:BB:CC'));
        $this->assertNotNull($lookup->lookup('00-90-C2-12-34-56'));

        $this->assertTrue($lookup->isLocallyAdministered('DA:A1:19:00:11:22'));
        $this->assertNull($lookup->lookup('DA:A1:19:00:11:22'));
    }

    public function test_fingerprints_onvif_hikvision_camera_with_high_confidence_and_plain_english_reason(): void
    {
        $service = new DeviceFingerprintService;

        $identity = $this->makeIdentity(
            provider: 'onvif',
            hostname: 'driveway-cam-01.local',
            addresses: ['172.16.0.55'],
            macAddresses: ['44:19:B6:11:22:33'],
            signals: [
                'onvif_types' => 'dn:NetworkVideoTransmitter tds:Device',
                'onvif_hardware' => 'DS-2CD2387G2-LU',
                'onvif_scopes' => [
                    'onvif://www.onvif.org/name/Hikvision',
                    'onvif://www.onvif.org/hardware/DS-2CD2387G2-LU',
                ],
                'open_ports' => [80, 554, 8000],
            ],
        );

        $id = $service->identify($identity);

        $this->assertSame('security', $id->domain);
        $this->assertSame('cctv', $id->category);
        $this->assertSame('ip_camera', $id->subcategory);
        $this->assertSame('ONVIF camera', $id->typeLabel);
        $this->assertSame('Hikvision', $id->manufacturer);
        $this->assertSame('DS-2CD2387G2-LU', $id->model);
        $this->assertGreaterThanOrEqual(85, $id->confidence);
        $this->assertSame('high', $id->confidenceBand);
        $this->assertStringContainsString('ONVIF', $id->reason);
        $this->assertStringContainsString('Hikvision', $id->reason);
        $this->assertTrue(DeviceTaxonomy::isValid($id->domain, $id->category, $id->subcategory));
    }

    public function test_fingerprints_ubiquiti_gateway_from_snmp_and_oui(): void
    {
        $service = new DeviceFingerprintService;

        $identity = $this->makeIdentity(
            provider: 'snmp',
            hostname: 'ux7-gateway',
            addresses: ['172.16.0.1'],
            macAddresses: ['24:5A:4C:99:88:77'],
            hardwareId: '1.3.6.1.4.1.41112.1.4',
            fingerprint: 'snmp:1.3.6.1.4.1.41112.1.4:5',
            signals: [
                'snmp_sys_descr' => 'Ubiquiti UniFi Express 7 4.0.6',
                'snmp_sys_object_id' => '1.3.6.1.4.1.41112.1.4',
                'snmp_sys_name' => 'ux7-gateway',
                'open_ports' => [22, 53, 80, 443],
            ],
        );

        $id = $service->identify($identity);

        $this->assertSame('it_infrastructure', $id->domain);
        $this->assertSame('network', $id->category);
        $this->assertSame('Ubiquiti', $id->manufacturer);
        $this->assertGreaterThanOrEqual(80, $id->confidence);
        $this->assertTrue(DeviceTaxonomy::isValid($id->domain, $id->category, $id->subcategory));
    }

    public function test_fingerprints_brother_printer_synology_nas_and_windows_workstation(): void
    {
        $service = new DeviceFingerprintService;

        $printer = $service->identify($this->makeIdentity(
            provider: 'mdns',
            hostname: 'BRW008077112233.local',
            addresses: ['172.16.0.40'],
            signals: [
                'mdns_services' => ['_ipp._tcp.local', '_pdl-datastream._tcp.local'],
                'upnp_manufacturer' => 'Brother Industries, Ltd.',
                'upnp_model_name' => 'MFC-L8900CDW',
                'open_ports' => [80, 515, 631, 9100],
            ],
        ));

        $this->assertSame('it_infrastructure', $printer->domain);
        $this->assertSame('printer', $printer->category);
        $this->assertSame('Brother', $printer->manufacturer);
        $this->assertSame('MFC-L8900CDW', $printer->model);

        $nas = $service->identify($this->makeIdentity(
            provider: 'http',
            hostname: 'office-nas.local',
            addresses: ['172.16.0.41'],
            signals: [
                'http_title' => 'Synology DiskStation - DS923+',
                'open_ports' => [80, 443, 5000, 5001],
            ],
        ));

        $this->assertSame('it_infrastructure', $nas->domain);
        $this->assertSame('storage', $nas->category);
        $this->assertSame('Synology', $nas->manufacturer);

        $workstation = $service->identify($this->makeIdentity(
            provider: 'tcp',
            hostname: 'DESKTOP-STEPHAN',
            addresses: ['172.16.0.93'],
            signals: [
                'open_ports' => [3389, 5985],
            ],
        ));

        $this->assertSame('it_infrastructure', $workstation->domain);
        $this->assertSame('endpoint', $workstation->category);
        $this->assertSame('workstation', $workstation->subcategory);
        $this->assertTrue(DeviceTaxonomy::isValid($workstation->domain, $workstation->category, $workstation->subcategory));
    }

    public function test_discovered_identity_evidence_hash_remains_stable_when_signals_added(): void
    {
        $base = $this->makeIdentity(
            provider: 'icmp',
            hostname: 'harbour-cam',
            addresses: ['172.16.0.55'],
            macAddresses: ['44:19:B6:11:22:33'],
        );

        $withSignals = $this->makeIdentity(
            provider: 'icmp',
            hostname: 'harbour-cam',
            addresses: ['172.16.0.55'],
            macAddresses: ['44:19:B6:11:22:33'],
            signals: [
                'onvif_hardware' => 'DS-2CD2387G2-LU',
                'open_ports' => [80, 554],
            ],
        );

        $this->assertSame($base->evidenceHash(), $withSignals->evidenceHash());
    }
}

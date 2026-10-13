# Supported-living equipment market and target catalogue

Researched 7 October 2026 from manufacturer, developer, product-support and NZ distributor sources. This is a proposed coverage catalogue for Oblivion's single complete release, not a purchase order or a claim that the integrations already work.

The user's instruction is to plan broadly for equipment a supported-living provider may use, with customisable behaviour and different equipment/endpoint mixes in separately deployed organisations. Each Oblivion installation remains single-organisation. The original audit's source findings remain in [AUDIT.md](AUDIT.md).

## Recommended product approach

Make every equipment family discoverable or registrable in one device/asset catalogue, with common ownership, site/room, warranty, service, software/firmware, assignment, history and incident references. Add monitoring and remote actions only through a declared, tested provider/model capability. An unconnected hoist can still be fully managed as an asset, inspection and maintenance record; the UI must not invent a remote-control capability.

Use separate support levels: **registered asset**, **health monitored**, **events integrated**, **remote actions verified**, **media supported**, and **hardware witnessed**. These are independent capabilities, not a single vague “integrated” badge. New vendors should extend the same contracts rather than create another dashboard or duplicate person/site/device records.

Cover the following families in the final product scope. The named vendors are representative integration candidates; the final device matrix must record exact model, firmware, region, API/protocol, license, supported actions and test evidence. No finite research exercise establishes compatibility with every existing or future device.

## Equipment families

### 01. Network infrastructure

Routers, firewalls, switches, PoE ports, wireless access points/controllers, WAN links, VPN/SD-WAN and cellular backup. UniFi is the user's preferred starting ecosystem; heterogeneous networks should use supported SNMP, flow, syslog, traps and vendor interfaces. Required journeys include discovery, performance/history, interface health, dependency-aware alerts, configuration versions, supported restore/change actions and a connected topology map.

PRTG documents protocol-based monitoring and local probes. Auvik describes topology derived from ARP/forwarding/VLAN and LLDP/CDP information plus CLI/SNMP, and separately describes configuration backup/version comparison. These are useful acceptance benchmarks, not evidence that every provider action is universal. Sources: [PRTG monitoring technologies](https://www.paessler.com/monitoring/technology), [Auvik topology](https://support.auvik.com/hc/en-us/articles/202956414-How-does-Auvik-discover-network-topology-and-device-information), [Auvik configuration management](https://www.auvik.com/network-management-software/configuration-management-software/).

### 02. IT infrastructure and services

Servers, virtual machines, NAS/storage, printers, databases, backup jobs, DNS/DHCP, websites, certificates and selected cloud services. Keep service checks related to the supporting device and site. Discovery does not authorize remote shell or destructive administration. PRTG's documented hardware coverage includes SNMP/WMI/SSH/WBEM-based information; the exact transport depends on the system. Source: [PRTG hardware monitoring](https://www.paessler.com/monitoring/hardware/hardware-scanner).

### 03. Power and infrastructure resilience

UPS units, managed power outlets/PDUs, network cabinet temperature, mains/battery status and communications backup. Display remaining-runtime information only if provided by a supported device; link outages to affected sensors/cameras. Outlet switching and shutdown require explicit device/action support and safeguards for care equipment. APC documents SNMP integration through its network-management cards; this is a concrete monitoring route, not universal outlet-control support. Source: [APC network-management integration](https://www.apc.com/us/en/faqs/FA159739/).

### 04. Site collectors and gateways

Downloadable Windows and Linux collectors; LoRaWAN gateways; approved bridges for isolated networks and vendor systems. Include install, enrollment, scope, version, heartbeat, clock, local buffer, certificates, upgrade/rollback and revocation. A collector monitors approved nearby systems; it is distinct from the endpoint agent/enrollment that manages an individual computer or phone. Source: [PRTG probe model](https://www.paessler.com/monitoring/network/agentless-monitoring). Oblivion already has its own collector core, so this is a product-delivery comparison rather than a recommendation to replace it.

### 05. CCTV, NVRs and video-management systems

Include camera/recorder health, storage state, authorized live viewing, camera groups/walls, supported PTZ/audio, event metadata, vendor playback references and preservation status. Primary named targets are UniFi Protect and Hikvision. Axis and Hanwha should be additional NZ-relevant candidates; Milestone is a VMS candidate where deployments already use it.

Axis exposes VAPIX media/configuration/event APIs but expressly notes that individual products do not implement all APIs. Hikvision's integration centre documents ISAPI and its developer materials require model-specific capability checks. Hanwha documents SUNAPI/Open Platform. Channel Ten lists Axis, Hanwha and Milestone as NZ distribution partners; that supports local relevance, not a market-share ranking. Sources: [Axis API coverage](https://developer.axis.com/vapix/network-video/), [Hikvision integration centre](https://tpp.hikvision.com/tpp/IntegrationCenter), [Hanwha developer platform](https://step.hanwhavision.com/kor_EN/OpenPlatform/OpenPlatform.aspx?2=), [NZ distribution](https://www.channelten.co.nz/about-us).

### 06. Intercoms, doorbells and communication endpoints

Video doorbells, entry intercoms, SIP/VoIP or care communication endpoints, authorised talkback and call-event history. Model these as their own capabilities: a camera stream does not guarantee two-way audio or door release. Nurse-call/communications integrations below provide another route for staff alerts and call handling. Exact model/codec/session support must be demonstrated before the in-app action is offered.

### 07. Intrusion alarms and duress

Ajax and UniFi alarm/sensor products are named targets; include panels/hubs, contacts, motion, glass-break, tamper, sirens, panic buttons, zones, arming state and communication/battery faults. Arm/disarm/bypass, user rights and remote configuration are separate capabilities from receiving an alarm.

Ajax documents direct SIA DC-09 and Translator/Cloud routes to monitoring systems. Direct SIA transmits event codes and panic coordinates, not photo verification or arbitrary management. UniFi's current SuperLink page describes its sensor/Alarm Hub ecosystem; some related products are labelled forthcoming, so NZ availability, controller versions and usable API actions need confirmation. Do not advertise future items as delivered. Sources: [Ajax direct monitoring](https://support.ajax.systems/en/how-to-use-sia-for-cms-connection/), [Ajax monitoring compatibility](https://support.ajax.systems/en/hub-cms-support/), [UniFi SuperLink family](https://ui.com/physical-security/special-devices/super-link), [UniFi developer entry](https://developer.ui.com/protect).

### 08. Fire, smoke, gas and other life-safety status

Register panels/detectors, supervised connections, test/inspection dates, battery/fault state and supported alarm events. Obtain events through the actual approved panel/gateway/monitoring-system contract. The unified dashboard supplements the installed safety system; it must not become an assumed replacement for its local alarm, certified control logic or response arrangements. Do not provide generic reset/silence controls merely because the command catalogue has an action name. This is a proposed domain boundary, not a claim of regulatory certification.

### 09. Access control, gates and visitor entry

UniFi Access is the primary target. Add an extensible path for existing Gallagher and ICT Protege deployments, including controller/door state, credential lifecycle, schedules, visitor expiry, forced/held-door events and reconciled unlock actions.

Gallagher publishes separate API capabilities for cardholders, events, status and overrides and states that integration developers must onboard as technology partners. ICT publishes Protege API documentation. These are viable integration families subject to edition, partner and license requirements; they do not imply drop-in Oblivion compatibility. Sources: [Gallagher interfaces](https://products.security.gallagher.com/security/nz/en_NZ/products/software/interfaces/c/system_interfaces), [Gallagher developer requirements](https://gallaghersecurity.github.io/), [ICT API documentation](https://my.ict.co/wxdllapidocs/Topics/Software%20Manuals/Appliance-Client%20Application/AC%20API%20Spec/Controllers.htm).

### 10. Contactless fall and room/bed sensing

Milesight VS373 is a concrete candidate for radar fall/occupancy-related use. Retain sensor-specific capability/version information, room geometry/configuration, health, calibration/commissioning notes, false-positive review and exact alarm timing. The manufacturer documents configurable confirmation and reporting delays, so an immediate webhook is not necessarily an immediate physical-event timestamp. Certain uplink/downlink properties differ by firmware. Treat accuracy claims as manufacturer claims requiring representative-room testing, not a guarantee.

Sources: [VS373 introduction](https://www.milesight.com/products/docs/en/vs373/steps/vs373/product-introduction.html), [configurable properties](https://www.milesight.com/development-platform/docs/en/device-reference/vs373-configurable-properties.html), [firmware-dependent uplinks](https://www.milesight.com/products/docs/en/vs373/protocol/vs3xx/vs373-uplink.html), [downlinks](https://www.milesight.com/products/docs/en/vs373/protocol/vs3xx/vs373-downlink.html).

### 11. Bed/chair exit, movement, inactivity and assisted-living sensors

Include pressure/occupancy devices, motion/inactivity, room/exit sensors and care-plan-specific alerts. Do not automatically infer a person's identity from occupancy. Specialist seizure/epilepsy devices and other care sensors should have named supported contracts and clinical ownership, not generic fall-threshold assumptions. Tunstall's connected-care catalogue includes fall pendants and bed occupancy; its NZ site includes epilepsy, duress and personal-alarm categories. Sources: [Tunstall connected care](https://www.tunstall.com/our-solutions/connected-care/), [Tunstall NZ](https://tunstallhealthcare.co.nz/home-care/).

### 12. Personal alarms, wearable help buttons and lone-worker welfare

Pendant/watch/portable GPS devices, panic buttons, welfare check-ins, missed check-ins, man-down where supported, battery/connectivity and response outcomes. Support internal, external and combined monitoring. Tunstall NZ explicitly describes both monitored-centre and on-site-carer models; its commercial API/contract still needs confirmation. Queclink's catalogue includes personal/asset trackers and emergency-button devices; existing Oblivion native Queclink work provides a foundation, not a guarantee for every listed model. Sources: [Tunstall NZ response options](https://tunstallhealthcare.co.nz/home-care/), [Queclink portfolio](https://www.queclink.com/asset-trackers/).

### 13. Nurse call and care communications

Call points, pull cords, bedside buttons, corridor annunciators, staff handsets, escalation and cancellation/response history. Austco Tacera is a concrete API-capable candidate; Ascom Unite is another event/communications integration platform. Retain the nurse-call system's identity and response state and reconcile acknowledgements between systems.

Austco documents API integration of call systems with RTLS, smart beds and other systems, including alarm input/output in its integration material. Ascom documents orchestration of source-system events into alerts/tasks across endpoints. Contract, version, local supply and supported operation discovery remain required. Sources: [Austco Tacera](https://austco.com/products/tacera/), [Austco API description](https://www.austco.com/documents/resources/Tacera-ip-nurse-call_letter.pdf), [Ascom Unite](https://www.ascom.com/north-america/products-and-services/software/unite-communications-software/).

### 14. Indoor environment and wellbeing

Temperature, humidity, air quality, CO2, leak detection, door/window state and approved noise/light measures. Milesight's healthcare range includes AM-series air-quality sensors, EM-series environmental/contact/leak devices, WS101 buttons and temperature products. Keep health readings, missing data and configurable thresholds separate from clinical conclusions. Source: [Milesight healthcare portfolio](https://www.milesight.com/iot/solution/smart-iot-healthcare-solution).

### 15. Medication/food storage and cold chain

Fridge/freezer temperatures, door-open duration, power failure, medication-cabinet access and excursion response. Link evidence to Facilities and medication-stock workflows where appropriate; a temperature alarm must not silently change an eMAR administration record or release quarantined stock. Exact probes, calibration and alarm rules depend on the use. Milesight lists temperature and contact sensors applicable to these environments. Source: [Milesight healthcare portfolio](https://www.milesight.com/iot/solution/smart-iot-healthcare-solution).

### 16. Building utilities and environmental control

Water leaks, meters, HVAC/thermostats, lighting, energy usage and supported relays. Keep monitoring distinct from actuating a safety-affecting output. Milesight lists thermostats, current transformers, leak detectors and controllers; protocol/command support requires per-model testing. This same equipment can serve care comfort, facilities maintenance and IT continuity without duplicating its asset record. Source: [Milesight IoT range](https://www.milesight.com/iot/).

### 17. Vehicles, transport and dashcam references

Vehicle position/trips, driver assignment, geofences, speed/idling, mileage, maintenance inputs and linked dashcam evidence. Queclink remains a primary target; Teltonika is an additional protocol-family candidate. Keep vehicle management in Fleet and source telemetry/device health in Devices. Carrier bands, coverage and supported radio generation must be checked per deployment; do not select an old 2G model from a historical brochure as the default. Sources: [Teltonika platform and accessories](https://www.teltonika-gps.com/), [Queclink portfolio](https://www.queclink.com/asset-trackers/).

### 18. Indoor person and equipment location

BLE/beacons, RTLS gateways/tags, permitted geofences, exit events and location history for people, staff or assets. Location resolution/confidence must be explicit: proximity to a beacon is not exact room presence or proof of work. Teltonika documents beacon-based indoor tracking; specialised RTLS can also integrate through a nurse-call system. Sources: [Teltonika indoor tracking](https://www.teltonika-gps.com/use-cases/assets-and-workforce/indoor-tracking-solutions), [Austco integration scope](https://austco.com/products/tacera/).

### 19. Clinical measurement devices

Scales, blood-pressure monitors, thermometers and other selected observation devices. Register equipment/calibration and import readings only with an explicit person binding, units, timestamp, consent/access and provenance. Clinical review and escalation remain in the care domain. Withings is an example of a consent-based API ecosystem for measurements; it does not establish suitability, NZ clearance or full control of arbitrary medical devices. Source: [Withings developer resources](https://support.withings.com/hc/en-us/articles/13364594339217-Withings-Developer-Resources), [available data](https://oauth.withings.com/developer-guide/v3/data-api/all-available-health-data/).

### 20. Care equipment and assistive technology assets

Hoists, slings, adjustable beds, mattresses, wheelchairs, shower chairs, mobility aids, communication aids, feeding/oxygen equipment and other assigned care assets need ownership, suitability records, instructions, inspection/service, cleaning, faults, recall/quarantine and replacement history even when they expose no network API. Arjo's regional material illustrates patient-handling, bed and therapeutic-surface categories; local availability varies. Connectivity or remote adjustment must be separately established. Sources: [Arjo patient-handling range](https://www.arjo.com/en-au/products/patient-handling/), [Arjo ANZ equipment services](https://www.arjo.com/globalassets/localisation/au-site/pdf/arjo-rental-brochure-70s_bro-201909-0100-anz_web.pdf/).

### 21. Medication devices and specialist clinical equipment

Include medication dispensers, locked storage and relevant clinical equipment in the extensible register. Where a supported provider publishes dispensing/device events, link them as evidence to the correct care/eMAR workflow. A dispenser-opening or device-command receipt is not proof that medicine was administered. No generic treatment adjustment, pump control or automatic clinical decision-making is included merely by registering equipment. Exact integration candidates need a provider-specific clinical and technical contract; no verified universal integration was found in this research.

### 22. Computer remote monitoring and management

Windows/macOS/Linux workstations and servers: inventory, health, software, patch state, supported deployment/scripts/service actions, remote assistance and command/session history. **The user subsequently chose to build a focused native Oblivion RMM, superseding the earlier NinjaOne selection.** Kaseya VSA/Datto RMM remain reference capability categories, not additional committed integrations. Keep the execution boundary extensible and record actual available actions per OS/agent version/license. Sources: [Kaseya RMM](https://www.kaseya.com/products/rmm-software/), [Kaseya remote control](https://www.kaseya.com/products/rmm-software/remote-control-software/), [NinjaOne public API](https://www.ninjaone.com/docs/application-programming-interface-api/public-api-operations/).

### 23. Mobile, shared and dedicated endpoint management

**The latest working direction is read-only Microsoft Intune visibility alongside native Oblivion computer management.** Intune retains its MDM actions and policies; supported OS/ownership/status fields still require acceptance. See the [selected-provider contract](TARGET-SCOPE.md#endpoint-management-across-deployments).

Android phones/tablets, iOS/iPadOS, organisation-owned mixed-use devices, BYOD work profiles, shared devices and dedicated kiosks. Also reserve provider-specific support for ChromeOS and other specialist endpoints; do not promise feature parity across platforms. Model enrollment, ownership, app policies, compliance, certificates/Wi-Fi, restrictions, updates, lock/retirement/wipe, re-assignment and actual result state.

Intune exposes managed-device resources/actions through Microsoft Graph. Google documents different Android ownership/enrollment modes. Its direct Android Management API also has commercial-provider eligibility rules and excludes exclusively first-party in-house solutions; it is not an unrestricted shortcut to an internal Intune replacement. Using an established EMM through its supported interfaces is the recommended default for this single-organisation application. A direct commercial EMM product path would require separate eligibility and lifecycle work. Sources: [Microsoft managed devices](https://learn.microsoft.com/en-us/graph/api/resources/intune-devices-manageddevice?view=graph-rest-1.0), [Android enrollment modes](https://developers.google.com/android/management/provision-device), [Android API permissible usage](https://developers.google.com/android/management/permissible-usage?hl=en).

### 24. External monitoring and response providers

Treat a monitoring company as a contracted response integration for selected sites/event types, with delivery, human acknowledgement, acceptance, escalation and outcome references. It is not another Oblivion tenant. Patriot is a relevant CMS integration candidate: its recommended REST API requires the ICA module and supports scoped permissions, while older JSON access is discouraged. Receiving Ajax SIA alarms does not by itself implement two-way human-response status with the monitoring company. Sources: [Patriot integration options](https://www.patriotsystems.com/p67library/ThirdPartyIntegrations.html), [Ajax CMS transport](https://support.ajax.systems/en/how-to-use-sia-for-cms-connection/).

## Configurability learned from the market

Milestone separates live/playback/export and evidence-lock permissions by device and role. Intune separates action permissions from the scope of visible/manageable objects. Oblivion should apply the same useful distinction to site/device/person/event scope, keeping its own single-organisation architecture and source privacy boundaries. These are proposed design principles derived from the sources, not an assertion that their administrative UIs can simply be embedded. Sources: [Milestone device permissions](https://doc.milestonesys.com/xprotect/xprotect-management-client/2026r1/en/device-tab--roles-), [Intune roles and scope](https://learn.microsoft.com/en-us/intune/intune-service/fundamentals/scope-tags).

For attendance, Microsoft Shifts documents worker confirmation/correction; Deputy separates review, correction and approval; ShiftCare links actual clock events to assigned-shift workflows. The recommended Oblivion approach is an evidence-assisted HR time record with configurable manual/assisted/automatic-reader modes and worker review through My Day. See [configuration and attendance design](TARGET-SCOPE.md). Sources: [Shifts confirmation](https://support.microsoft.com/en-us/teams/shifts/clock-in-and-out-with-shifts), [Deputy timesheet review](https://help.deputy.com/hc/en-au/articles/17528765586319-Review-edit-and-approve-timesheets), [ShiftCare mobile clocking](https://help.shiftcare.com/en/articles/3022277-clock-in-and-clock-out-of-shifts-from-the-mobile-app).

## Effect on the final release

The final release should include the complete agreed family coverage and connected workflows, with configured supported-provider capability matrices and no inert management buttons. Development can proceed in dependency order, but there is one release acceptance decision. Synthetic tests are necessary development evidence; supported hardware, installation and provider-result tests are additional evidence for capabilities claimed as operational.

The 21-record [development pilot](DEVELOPMENT-PILOT.md) is a compact base scenario, not the release scope. Extend it with the family-specific and deployment-policy variations required by this catalogue, including endpoint ownership modes, external monitoring and care-equipment maintenance. Outstanding partner/API contracts remain explicit delivery dependencies; they must not be silently relabelled as completed integrations.

## NZ CCTV catalogue refresh — 11 October 2026

The user has no clients or live test cameras yet and explicitly requested current NZ market research plus as much implementation and synthetic verification as possible. Live camera/recorder acceptance is deferred until client equipment is available. This changes the work sequence; it does not make synthetic results a hardware witness. No equipment purchase, supplier contact or firmware update is requested.

### Evidence-based local coverage

[Dicker Data NZ](https://www.dickerdata.co.nz/security-surveillance-distributor) advertises Hikvision, Axis, IDIS and Milestone in its security distribution portfolio. [Central Communications](https://www.centralcomms.nz/cctv.html) advertises Hikvision, Dahua, Uniview, Mobotix and UniFi installation. [CCTV Solutions](https://www.cctvnz.co.nz/about-us) names Hikvision, Dahua, Axis, Avigilon, ProVision and Milestone. [Wai-Secure](https://www.wai-secure.nz/services/cctv) names Uniview, Axis, Hanwha, Milesight, Vivotek, Provision-ISR and Nx Witness. These primary supplier/installer statements establish NZ availability/service relevance, not national installed-base share or use specifically by supported-living providers. No measured ranking was found. Hikvision and UniFi remain the accepted implementation targets; the other locally evidenced families remain extensible catalogue candidates with their own contracts and licensing.

### Concrete Hikvision development candidates

| Candidate and role | NZ primary listing | Vendor protocol evidence and development consequence |
| --- | --- | --- |
| DS-2CD2347G3-LIS2UY/SL, 4 MP ColorVu 3.0/AcuSense turret | [ITPLUS exact 2.8 mm model](https://www.itplus.co.nz/products/hikvision-ds-2cd2347g3-lis2uy-sl-2-8mm/); [Security Cameras NZ black suffix](https://www.securitycameras.co.nz/products/network-ip-cameras/hikvision/hikvision-4mp-black-active-deterrence-camera-with-2-8mm-lens/) | [Official 14 April 2025 datasheet](https://assets.hikvision.com/prd/public/all/doc/m000144450/DS-2CD2347G3-LIS2UY_SLRB_Datasheet_20250414.pdf) explicitly lists RTP/RTSP over HTTPS, HTTP/HTTPS Digest, ISAPI and H264 substreams. Prioritise this exact family for documented encrypted-transport development. The tunnel route, POST acknowledgement, regional firmware and installed certificate are still unverified. |
| DS-2CD2387G3-LIS2UY/SL, 8 MP ColorVu 3.0 turret | [ITPLUS kit listing](https://www.itplus.co.nz/products/page/21/) identifies this camera with an M2 recorder | [Official G3 8 MP specification](https://pro-av.hikvision.com/mena-en/products/IP-Products/Network-Cameras/Pro-Series-EasyIP-/ds-2cd2387g3-lis2uy-s-l--rb-/) also explicitly advertises RTP/RTSP over HTTPS. Keep a separate exact-model row, not implicit equivalence to the 4 MP variant. |
| DS-2CD2386G2-ISU/SL, established 8 MP AcuSense kit camera | [PB Tech kit 70025](https://www.pbtech.co.nz/product/CCTHIK70025/HIKVISION-8-CH-4TB-NVR---4x-4K-Acu-Turret-Camera-K) explicitly pairs it with DS-7608NI-M2/8P | [Official Australia/NZ product page](https://pro-av.hikvision.com/au-en/products/IP-Products/Network-Cameras/Pro-Series-EasyIP-/ds-2cd2386g2-isu-sl/) identifies the regional family. RTSP plus HTTPS capability alone does not prove RTSP-over-HTTPS on its installed firmware. Preserve older equipment coverage as an explicit compatibility investigation. |
| DS-2CD2347G2-LSU/SL, earlier 4 MP ColorVu turret | [Security Cameras NZ listing](https://www.securitycameras.co.nz/products/network-ip-cameras/hikvision/hikvision-4mp-ip-colourvu-turret-camera-with-2-8mm-lens/) | [Official 5 September 2025 datasheet](https://assets.hikvision.com/prd/normal/all/doc/sm000058372/DS-2CD2347G2-LSU_SL-C_Datasheet_V5.5.114_20250905.pdf) lists RTSP, HTTPS and HTTP Digest, without explicitly documenting the same secure tunnel. Do not infer G3 transport from its shared ColorVu branding. |
| DS-7608NI-M2/8P, 8-channel PoE NVR; DS-7616NI-M2/16P is the separately listed 16-channel family | [ITPLUS M2/8P](https://www.itplus.co.nz/products/hikvision-ds-7608ni-m2-8p/); [Security Cameras NZ M2-8P](https://www.securitycameras.co.nz/products/nvr-ip-video-recorders/hikvision/hikvision-ultra-8-channel-8mp-nvr-with-8-poe-ports/); the ITPLUS page also lists the 16-channel variant | [Official M2 specification](https://hsrc.hikvision.com/en/products/IP-Products/Network-Video-Recorders/Ultra-Series/ds-7608ni-m2/) is already reconciled in HIKVISION-CONTRACT-DRAFT.md. Prioritise actual NVR identity and read-only channel/proxy mapping. Published RTSP/HTTPS and proprietary encryption do not prove compatible secure tunnel/playback. |

Listings were reviewed on 11 October 2026. Several ITPLUS pages explicitly show unavailable and the PB Tech kit shows out of stock; do not interpret listed products as immediate stock or a purchase recommendation. Supplier descriptions contain suffix and specification differences, so vendor schema/model identity controls the implementation. Historical Atlas Gentech 2018/SP-series PDFs appeared freshly crawled in search results; their document dates remain historical and they were not treated as current inventory or model priority.

Development priority is an inference from the combination of NZ listings and explicit vendor protocol documentation: implement the shared Hikvision read-only identity/channel foundations, document the G3 secure camera transport profile, retain M2 recorder coverage as a separate mapping/transport package, and preserve older G2/legacy compatibility work. No model or firmware allowlist is activated solely from a market listing. Continue synthetic positive, denial, retry, current-authority and browser/native checks now; retain model/firmware/licence/installed field witnesses as clearly pending for first-client acceptance.
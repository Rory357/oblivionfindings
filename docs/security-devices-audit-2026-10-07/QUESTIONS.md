# Product decisions after the Security & Devices audit

Already agreed: hybrid approach; one organisation/multiple sites; live CCTV in Oblivion with vendor recordings; vehicle/staff/lone-worker/person tracking; dedicated HR attendance with possible My Day confirmation; shared incident reconstruction with H&S. **The user subsequently clarified that this is development-only and authorized a synthetic pilot.** These do not need to be answered again.

## Answers received after the audit

- Development only: create a synthetic pilot; nothing is in production.
- Equipment: research the broad supported-living market instead of requiring a current model list.
- Response: configurable internal, external or combined arrangements.
- Evidence: configurable collection trigger, before/after window and sources within Oblivion.
- Access: market-informed, customisable permissions.
- Attendance: recommend the best approach, with customisable rules. Proposed default is evidence-assisted timekeeping and My Day confirmation.
- Endpoints: plan across OS, ownership modes and management-provider choices; these vary per separate organisation deployment.
- Latest endpoint decision: **Oblivion-native RMM + read-only Intune visibility**, superseding NinjaOne. Native computer actions belong to Oblivion; Intune retains MDM actions/policy.
- Delivery: one complete release covering all agreed workflows, not a staged feature release.
- Design: follow Rory's rules.

These answers are recorded in [TARGET-SCOPE.md](TARGET-SCOPE.md). The questions below are retained as a configuration/acceptance checklist; they are not a renewed request to answer the same eight questions. Delegated research and proposed defaults are recorded in [MARKET-RESEARCH.md](MARKET-RESEARCH.md). Do not supply passwords, keys or personal client information.

## First decisions

1. **Answered for development:** create a synthetic pilot; nothing is in production. The proposed [pilot](DEVELOPMENT-PILOT.md) has two test sites and 21 representative logical device/collector records. Real rollout size and hardware witnessing remain future decisions.
2. Which exact UniFi consoles/Protect recorders/Access readers, Hikvision models, Ajax hubs, Milesight care models and trackers do you own or intend to deploy? Which are already installed?
3. Who responds to falls, panic and alarms: a staffed Control Room, site staff, an on-call person or an external monitoring company? Is cover 24/7?
4. For a fall, should evidence collection begin immediately on detection, while a person decides whether to confirm an incident? What before/after window should be suggested, for example 5 minutes before and 15 minutes after?
5. Who may view live cameras, historical recording links, person locations and staff/lone-worker locations? Do site managers see only their sites? Who can export evidence?
6. May a maintenance window mute genuine fall/SOS/panic signals, or should only an explicit time-limited sensor-test mode do that? Who can enable test mode and who must be notified?

## Monitoring and management

7. What is the expected alert speed for safety events versus an offline printer, camera or switch? What monitoring-history period do you need?
8. Which network actions matter first: reboot devices, cycle PoE, change ports/VLANs/Wi-Fi, firmware updates, configuration backup/restore or other tasks? Which require a second approver?
9. Should topology show switches/ports/APs/clients, physical rooms/floors, or both? Do you have floorplans and room-to-device mapping?
10. When a site loses internet, should a local service notify staff or sound an alarm, and how long must it retain observations until reconnection?
11. Will collectors run on existing always-on Windows/Linux machines, a VM or a dedicated site appliance? Who installs and updates them?

## Physical security and care

12. For Ajax/UniFi alarms, do you need only event visibility or also arm/disarm, zone bypass, user/PIN management, schedules and monitoring-company acknowledgement?
13. For cameras, do you need a live wall, single-camera view, PTZ/talkback, snapshots or all of these? How many concurrent streams should the Control Room display?
14. How long do vendors retain recordings, and who can request preservation/export for a serious incident? Should Oblivion track expiry warnings and missing clips?
15. For access control, which people need rights: staff, contractors, visitors or people supported? Should rights follow site assignment, shifts, training/clearance or manual approval?
16. Which Milesight functions matter: falls, bed/chair exit, occupancy, prolonged bathroom stays, inactivity, environmental alerts, call buttons or other care observations? Who sets thresholds and reviews false alarms?

## Attendance and tracking

17. Should door events only suggest start/end times for confirmation, or may a specific reader automatically clock someone in/out? How should offsite work, breaks, missed swipes and overnight/split shifts work?
18. At timesheet submission, should the worker confirm each shift's suggested times, review only differences, or receive a whole-week summary? Who approves corrections after submission?
19. For lone workers, do you need scheduled check-ins, automatic missed-check-in escalation, panic, fall/man-down, welfare callbacks or live tracking only during a session?
20. For people supported, what governs consent, approved viewers, indoor location, geofence/exit alerts and location-history retention? What should happen when consent changes?
21. For vehicles, which functions matter beyond location: trips, driver assignment, geofences, speed/idling, mileage, maintenance triggers, dashcam links or vehicle commands?

## Computer and Android management

22. Roughly how many Windows, Linux, macOS, Android and Apple mobile devices are in scope? Which are organisation-owned, personally owned or shared?
23. **Latest direction answered:** native Oblivion computer management and read-only Intune visibility. Establish authorised read access, native-agent deployment and ownership of overlapping update/application policies; Intune write operations stay in Intune.
24. Which endpoint functions are essential first: remote assistance, unattended remote control, patches, software installation, scripts, security/compliance, kiosk mode, app restrictions, lost-device lock/wipe or device retirement?
25. Should remote support require the user to accept the session? Who can run scripts, install software, unlock doors or wipe devices, and which actions need another approver?

## Delivery priorities

26. **Release scope answered:** all agreed journeys in one complete final release. Internal development starts with the shared safety prerequisites and fall detection → live camera → response → H&S reconstruction; no final release date was specified.
27. What ongoing provider/subscription and support budget range should the hybrid design respect? Approximate bands are enough; no purchase is implied.

Answers should become specific acceptance cases and a supported hardware/action matrix. They should not be treated as permission to implement every listed example.

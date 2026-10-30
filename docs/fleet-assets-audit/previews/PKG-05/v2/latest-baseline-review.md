# Late published-baseline observation

Design checkout and reused runtime inputs remain at `4ea64c547ed85a5b7504e59599db351f6eba7deb`. The current read-only Main page register (revision 99) reports publication of `f7d517359da6ffdf90de2f259111fe5e8a1133f2` while PKG-05 design work was underway. The latter is an available local Git object and was inspected read-only; no checkout, branch, fetch, application file, sibling process or guide was changed.

A targeted Git comparison found no change to ClientTransportBooking and its controller; FleetVehicleBooking and VehicleBookingController; ResidentTransportController; journey/booking access/readiness services; the fleet navigation helper; or reused page/UI/wizard/maintenance date-time primitives and app CSS.

Adjacent relevant changes inspected:

- MaintenanceTransitionService accepts a source check linked through its canonical maintenance report as well as a direct work-order link. The design’s original-check/report handoff remains compatible; it does not implement work-order decisions.
- VehicleCalendarService and FleetMaintenanceProvider now scope reminders through VehicleReminderAccess. This reinforces the design’s permitted-source handoff and does not authorise a duplicated calendar or task store.
- Other changed files concern vehicle workspace documents/appointments/reminders/trip history/export and map/report support. Those are outside this Transport mockup’s implementation scope.

The decisive current source limitation remains: vehicle checkout and return use management authority. The synthetic driver flow is visibly proposed, has a blocked-authority scenario and needs separately approved narrow integration. No current remote-head, CI-green, deployed-server or programme acceptance claim is made by this observation.

# Shifts Frontend Route Map

The frontend should generate shift, rostering, and timesheet URLs from the generated route helpers under `resources/js/routes`.
Manager and scheduler pages use the `operations.*` namespace. Frontline clocking remains in the `attendance.*`, `my-day.*`, `my-roster.*`, and `my-calendar.*` namespaces.
HR period timekeeping remains under `hr.time.*`, but the HR page links to the operations shift-timesheet queue so managers experience timekeeping as one workflow.

## Canonical Inertia Pages

| Surface                 | Page/component                                        | Canonical route name                                      | Helper                                     | URL                                       |
| ----------------------- | ----------------------------------------------------- | --------------------------------------------------------- | ------------------------------------------ | ----------------------------------------- |
| Shift list              | `resources/js/pages/operations/shifts/index.tsx`      | `operations.shifts.index`                                 | `@/routes/operations/shifts#index`         | `/operations/shifts`                      |
| Shift create            | `resources/js/pages/operations/shifts/create.tsx`     | `operations.shifts.create`                                | `@/routes/operations/shifts#create`        | `/operations/shifts/create`               |
| Shift store             | `resources/js/pages/operations/shifts/create.tsx`     | `operations.shifts.store`                                 | `@/routes/operations/shifts#store`         | `/operations/shifts`                      |
| Shift detail            | `resources/js/pages/operations/shifts/show.tsx`       | `operations.shifts.show`                                  | `@/routes/operations/shifts#show`          | `/operations/shifts/{shift}`              |
| Shift edit              | `resources/js/pages/operations/shifts/edit.tsx`       | `operations.shifts.edit`                                  | `@/routes/operations/shifts#edit`          | `/operations/shifts/{shift}/edit`         |
| Shift update            | `resources/js/pages/operations/shifts/edit.tsx`       | `operations.shifts.update`                                | `@/routes/operations/shifts#update`        | `/operations/shifts/{shift}`              |
| Shift series store      | `resources/js/pages/operations/shifts/create.tsx`     | `operations.shifts.series.store`                          | `@/routes/operations/shifts/series#store`  | `/operations/shifts/series`               |
| Rostering               | `resources/js/pages/operations/rostering/index.tsx`   | `operations.rostering.index`                              | `@/routes/operations/rostering#index`      | `/operations/rostering`                   |
| Timesheet list          | `resources/js/pages/operations/timesheets/index.tsx`  | `operations.timesheets.index`                             | `@/routes/operations/timesheets#index`     | `/operations/timesheets`                  |
| Timesheet approvals     | `resources/js/pages/operations/timesheets/index.tsx`  | `operations.timesheets.approvals`                         | `@/routes/operations/timesheets#approvals` | `/operations/timesheets/approvals`        |
| Timesheet create        | `resources/js/pages/operations/timesheets/create.tsx` | `operations.timesheets.create`                            | `@/routes/operations/timesheets#create`    | `/operations/timesheets/create`           |
| Timesheet edit          | `resources/js/pages/operations/timesheets/edit.tsx`   | `operations.timesheets.edit`                              | `@/routes/operations/timesheets#edit`      | `/operations/timesheets/{timesheet}/edit` |
| Timesheet workflow      | `resources/js/pages/operations/timesheets/edit.tsx`   | `operations.timesheets.submit/approve/return/reject`      | `@/routes/operations/timesheets`           | `/operations/timesheets/{timesheet}/...`  |
| Timesheet bulk workflow | `resources/js/pages/operations/timesheets/index.tsx`  | `operations.timesheets.bulkApprove/bulkReturn/bulkReject` | `@/routes/operations/timesheets`           | `/operations/timesheets/bulk-*`           |
| HR timekeeping          | `resources/js/pages/hr/time/index.tsx`                | `hr.time.index`                                           | direct HR time route                       | `/hr/time`                                |
| HR period approvals     | `resources/js/pages/hr/time/index.tsx`                | `hr.time.timesheets.*`                                    | direct HR time route                       | `/hr/time/timesheets/*`                   |

## Frontline Routes To Preserve

| Surface                 | Canonical route name          | Helper                             | URL                                                |
| ----------------------- | ----------------------------- | ---------------------------------- | -------------------------------------------------- |
| My Day                  | `my-day.index`                | `@/routes/my-day`                  | `/my-day`                                          |
| My roster               | `my-roster.index`             | `@/routes/my-roster`               | `/my-roster`                                       |
| My calendar             | `my-calendar.index`           | `@/routes/my-calendar`             | `/my-calendar`                                     |
| Attendance dashboard    | `attendance.index`            | `@/routes/attendance#index`        | `/attendance`                                      |
| Clock in/out            | `attendance.clockIn/clockOut` | `@/routes/attendance`              | `/attendance/clock-in`, `/attendance/clock-out`    |
| Break start/end         | `attendance.break.start/end`  | `@/routes/attendance/break`        | `/attendance/break/start`, `/attendance/break/end` |
| My Day timesheet submit | `my-day.timesheet.submit`     | `@/routes/my-day/timesheet#submit` | `/my-tasks/timesheet/{timesheet}/submit`           |

## Intentional Exceptions

- `resources/js/routes/shifts/clinical/*` still points at `/shifts/{shift}/clinical/*`. Those clinical endpoints are outside the duplicated operations migration map and should be treated separately.
- Staff time-off writes use `@/routes/rostering/time_off` (`/rostering/time-off`) because those endpoints are currently registered by `routes/staff.php`, not `routes/operations.php`.
- Deprecated page files under `resources/js/pages/shifts`, `resources/js/pages/timesheets`, and `resources/js/pages/rostering` are reference-only. Active controllers render the `operations/*` pages above.

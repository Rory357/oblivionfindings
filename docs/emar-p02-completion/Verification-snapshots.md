# Verification snapshots

The first focused PHP command reached the FIFO queue but failed before any tests ran: Bash could not find `php`. The corrected command uses `/c/Users/steph/.config/herd/bin/php84/php.exe`. Its configured database is the synthetic `oblivion_findings_codex_test` MySQL database in phpunit.xml; it is not an operational database or SQLite.

The corrected job covers ClientMedicationDayTest, PersonMedicationRecordTest, PersonMedicationCommandsTest, OneChartAdministrationSafetyTest and ClientMedicationReportExportTest. Backend inputs were frozen at enqueue, except the INR test assertion was corrected to the model's existing decimal:1 representation while the log still showed it queued. Subsequent hub/prerequisite/correction-event work will be recorded separately and rerun as affected; this queued result is not a final integrated acceptance claim.

Nine focused frontend tests passed across the day grid, person-switch loader and record JSON loader. All 25 modified/new PHP files passed syntax checks. Targeted record UI lint and diff whitespace checks passed. Main owns the combined full type check/build and browser verification.

Main's combined type check found the old grid fixture's null windows incompatible with P01's held-slot contract. The fixture now supplies actual opening/closing timestamps, and DayDose follows the non-null server window shape. The shared recorder adapter belongs to P01 and has not been overwritten.

The rebuilt record stays behind `medications.person_record=p02`; the default is still legacy until full completion and verification. Remaining work: P03 canonical support integration; P02 cross-person hub; P09 export entry integration including the preserved as-needed inclusion choice; legacy correction-route transactional event coverage; dry-run allergy copy command; focused backend results and Main's integrated browser checks.

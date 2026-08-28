<?php

test('handover controlled-witness mutations freeze one sorted shift union before handover and user locks', function (): void {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root.'/app/Services/ShiftHandoverService.php');
    $witnessSource = (string) file_get_contents(
        $root.'/app/Services/Medication/ControlledMedicationTransportWitnessService.php',
    );

    $slice = static function (string $start, string $end) use ($source): string {
        $startOffset = strpos($source, $start);
        $endOffset = strpos($source, $end, (int) $startOffset);

        expect($startOffset)->not->toBeFalse()
            ->and($endOffset)->not->toBeFalse();

        return substr($source, (int) $startOffset, (int) $endOffset - (int) $startOffset);
    };

    $save = $slice('public function save(', 'public function submit(');
    $edit = $slice('public function applyEdit(', 'protected function refreshHandoverTimelineSnapshot(');
    $canonical = $slice(
        'protected function lockCanonicalOutgoingContext(',
        'protected function lockCanonicalHandoverAggregate(',
    );
    $verification = $slice(
        'protected function resolveCdVerification(',
        'protected function controlledWitnessAttestationSnapshot(',
    );

    foreach ([$save, $edit] as $mutation) {
        $union = strpos($mutation, '$this->lockCanonicalOutgoingContext(');
        $users = strpos($mutation, '$this->lockCurrentHandoverAuthority(');
        $verificationCall = strpos($mutation, '$this->resolveCdVerification(');

        expect($union)->not->toBeFalse()
            ->and($users)->not->toBeFalse()
            ->and($verificationCall)->not->toBeFalse()
            ->and($union)->toBeLessThan($users)
            ->and($users)->toBeLessThan($verificationCall)
            ->and($mutation)->toContain(
                '$plannedParticipantIds->all()',
                'lockedShifts: $lockedPresenceShifts',
                '$lockedPresenceShifts,',
            );
    }

    expect($edit)->toContain('$this->lockCanonicalHandoverRow(')
        ->and(strpos($edit, '$this->lockCanonicalOutgoingContext('))
        ->toBeLessThan(strpos($edit, '$this->lockCanonicalHandoverRow('))
        ->and($canonical)->toContain(
            '$this->medicationGovernance->lockControlledWitnessPresenceShifts(',
            '[$outgoingShiftId, ...$additionalShiftIds]',
        )
        ->and(strpos($canonical, '$this->medicationGovernance->lockControlledWitnessPresenceShifts('))
        ->toBeLessThan(strpos($canonical, '$outgoingShift = Shift::query()'))
        ->and($verification)->toContain(
            'lockedPresenceShifts: $lockedPresenceShifts',
        )
        ->and($witnessSource)->toContain(
            "->orderBy('id')",
            '->lockForUpdate()',
            '->keyBy(fn (Shift $shift): int => (int) $shift->id)',
        );
});

test('attendance clock out acquires the complete handover aggregate before its attendance subset', function (): void {
    $root = dirname(__DIR__, 2);
    $attendanceSource = (string) file_get_contents($root.'/app/Domain/Hr/Services/AttendanceService.php');
    $handoverSource = (string) file_get_contents($root.'/app/Services/ShiftHandoverService.php');

    $clockOutStart = strpos($attendanceSource, 'public function clockOut(');
    $clockOutEnd = strpos($attendanceSource, 'public function getEndOfShiftBlockers(', (int) $clockOutStart);
    expect($clockOutStart)->not->toBeFalse()
        ->and($clockOutEnd)->not->toBeFalse();
    $clockOut = substr($attendanceSource, (int) $clockOutStart, (int) $clockOutEnd - (int) $clockOutStart);

    $handoverPrelock = strpos($clockOut, '$this->saveHandoverFromClockOut(');
    $attendanceSubset = strpos($clockOut, '$this->lockCurrentAttendanceCommand(');
    expect($handoverPrelock)->not->toBeFalse()
        ->and($attendanceSubset)->not->toBeFalse()
        ->and($handoverPrelock)->toBeLessThan($attendanceSubset)
        ->and($clockOut)->toContain('[(int) $sessionSnapshot->user_id]');

    $saveStart = strpos($handoverSource, 'public function save(');
    $saveEnd = strpos($handoverSource, 'public function submit(', (int) $saveStart);
    $save = substr($handoverSource, (int) $saveStart, (int) $saveEnd - (int) $saveStart);
    expect($save)->toContain(
        '$this->positiveIdOrNull($outgoingShiftSnapshot?->user_id)',
        '...$additionalParticipantUserIds',
        '$plannedParticipantIds->all()',
    )
        ->and(strpos($save, '$this->lockCanonicalOutgoingContext('))
        ->toBeLessThan(strpos($save, '$this->lockCurrentHandoverAuthority('));
});

test('shift assignment delegates client first locking and current eligibility to the lifecycle boundary', function (): void {
    $root = dirname(__DIR__, 2);
    $controllerSource = (string) file_get_contents($root.'/app/Http/Controllers/ShiftController.php');
    $lifecycleSource = (string) file_get_contents($root.'/app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php');
    $suggestionSource = (string) file_get_contents($root.'/app/Domain/Rostering/AutoSchedule/RosterSuggestionApplier.php');

    $controllerStart = strpos($controllerSource, 'public function assign(');
    $controllerEnd = strpos($controllerSource, 'public function autoFill(', (int) $controllerStart);
    $controllerAssign = substr($controllerSource, (int) $controllerStart, (int) $controllerEnd - (int) $controllerStart);
    expect($controllerAssign)->not->toContain('lockForUpdate()', 'AttendanceTimeEntryProjector')
        ->and($controllerAssign)->toContain('reservationReason: \'assignment\'');

    $lifecycleStart = strpos($lifecycleSource, 'public function assign(');
    $lifecycleEnd = strpos($lifecycleSource, 'public function unassign(', (int) $lifecycleStart);
    $lifecycleAssign = substr($lifecycleSource, (int) $lifecycleStart, (int) $lifecycleEnd - (int) $lifecycleStart);
    $aggregateLock = strpos($lifecycleAssign, '$this->lockCanonicalLifecycleShift(');
    $authorityLock = strpos($lifecycleAssign, '$this->lockCurrentShiftAuthority(');
    $eligibility = strpos($lifecycleAssign, '$this->assignmentEligibility->decide(');
    $assignmentWrite = strpos($lifecycleAssign, '$locked->update([');
    expect($aggregateLock)->not->toBeFalse()
        ->and($authorityLock)->not->toBeFalse()
        ->and($eligibility)->not->toBeFalse()
        ->and($assignmentWrite)->not->toBeFalse()
        ->and($aggregateLock)->toBeLessThan($authorityLock)
        ->and($authorityLock)->toBeLessThan($eligibility)
        ->and($eligibility)->toBeLessThan($assignmentWrite)
        ->and($lifecycleAssign)->toContain(
            "'shifts.overrideEligibility'",
            "'overridden_by' => (int) \$actor->id",
            "'rules_overridden' => collect(\$overrideableWarnings)",
        );

    expect($suggestionSource)->toContain('private function snapshotShiftsFor(')
        ->not->toContain('private function lockShiftsFor(', 'private function lockAndAttachShift(');
});

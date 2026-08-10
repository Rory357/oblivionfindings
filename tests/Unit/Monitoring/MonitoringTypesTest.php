<?php

it('defines the native monitor kinds and honest states', function () {
    expect(enum_exists('App\\Domain\\Monitoring\\Enums\\MonitorKind'))->toBeTrue()
        ->and(enum_exists('App\\Domain\\Monitoring\\Enums\\MonitorState'))->toBeTrue();
});

it('defines focused models for collectors profiles monitors and observations', function () {
    expect(class_exists('App\\Domain\\Monitoring\\Models\\MonitoringCollector'))->toBeTrue()
        ->and(class_exists('App\\Domain\\Monitoring\\Models\\MonitoringProfile'))->toBeTrue()
        ->and(class_exists('App\\Domain\\Monitoring\\Models\\Monitor'))->toBeTrue()
        ->and(class_exists('App\\Domain\\Monitoring\\Models\\MonitorObservation'))->toBeTrue();
});

it('defines the observation ingestion contract', function () {
    expect(class_exists('App\\Domain\\Monitoring\\Data\\ObservationInput'))->toBeTrue()
        ->and(class_exists('App\\Domain\\Monitoring\\Data\\ObservationResult'))->toBeTrue()
        ->and(class_exists('App\\Domain\\Monitoring\\Services\\MonitoringObservationIngestor'))->toBeTrue();
});

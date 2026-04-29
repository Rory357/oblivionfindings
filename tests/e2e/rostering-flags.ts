export const rosteringFlagsEnabled =
    process.env.FEATURE_ROSTERING_PUBLISH === 'true' &&
    process.env.FEATURE_ROSTERING_AUTO_SCHEDULE === 'true';

export const rosteringFlagSkipReason =
    'Rostering publish and auto-schedule flags are disabled for this run';

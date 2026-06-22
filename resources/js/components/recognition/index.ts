// One shared recognition wizard family, mounted from every "recognise / post /
// announce" entry point across HR (the feed hero, /hr/my, /hr/my/shoutouts, …).
// One shell, one backend path, one place to fix bugs. See
// docs/hr-feed-redesign/PROGRESS.md and the design handoff RECOGNITION_WIZARD_REUSE.md.
export { RecognitionWizard } from './recognition-wizard';
export type { RecognitionPerson, RecognitionDefaults } from './recognition-wizard';
export { ComposeWizard } from './compose-wizard';
export { AnnounceWizard } from './announce-wizard';
export type { AnnouncementSite } from './announce-wizard';
export { RecognitionInsightsDialog } from './recognition-insights-dialog';

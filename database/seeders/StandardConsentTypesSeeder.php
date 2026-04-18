<?php

namespace Database\Seeders;

use App\Models\ConsentType;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard set of consent types used by the family-portal
 * consent-request workflow (PR 29) for an NZ supported-living provider.
 *
 * Idempotent: uses ConsentType::firstOrCreate keyed on `name`, so repeat
 * runs do not create duplicates and existing records are preserved.
 *
 * Legal basis text references:
 *  - HDC Code of Health and Disability Services Consumers' Rights, Right 7
 *    (Right to make an informed choice and give informed consent).
 *  - NZ Privacy Act 2020 (Information Privacy Principles, esp. IPP 3, 10, 11).
 *  - Health Information Privacy Code 2020 (HIPC 2020) Rules 3, 10, 11.
 *  - Protection of Personal and Property Rights Act 1988 (PPPR Act 1988)
 *    where capacity assessment / welfare guardianship is relevant.
 */
class StandardConsentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $consentTypes = [
            [
                'name' => 'Asset Location Tracking (Safety)',
                'category' => 'safety',
                'description' => 'Consent to attach location-tracking devices to client-associated assets (e.g. mobility equipment, communication devices) for safety, loss prevention, and emergency response.',
                'purpose' => 'To enable rapid recovery of misplaced equipment and to support emergency response if the client becomes separated from essential assistive items.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7. Personal information collection and use governed by NZ Privacy Act 2020 (IPP 1, 3, 10) and the Health Information Privacy Code 2020 (Rules 1, 3, 10). Where the client may lack decision-making capacity, consent is sought from a welfare guardian or property manager appointed under the PPPR Act 1988 following a documented capacity assessment.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 7,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 30,
                'withdrawal_implications' => 'Tracking will cease within 7 days of withdrawal. The provider may not be able to recover lost or misplaced assets and emergency-response capability will be reduced.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Personal Tracker (Wandering Risk)',
                'category' => 'safety',
                'description' => 'Consent to use a personal location-tracking device (worn or carried) where the client has been assessed as being at risk of unsafe wandering or becoming lost.',
                'purpose' => 'To safeguard the client by enabling timely location and recovery if they leave a safe environment, in line with their positive behaviour support and risk-management plans.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7, balanced against the duty of care owed by the provider. Collection, use and retention of location data complies with the NZ Privacy Act 2020 (IPP 1-5, 9-11) and Health Information Privacy Code 2020 (Rules 1-5, 9-11). Where capacity is in doubt, a PPPR Act 1988 capacity assessment is undertaken and consent is given by an appointed welfare guardian or activated enduring power of attorney (personal care and welfare).',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 7,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 30,
                'withdrawal_implications' => 'Personal tracking will be discontinued. Risk-management and behaviour support plans will be reviewed; alternative safety measures (e.g. increased supervision) may be required.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Photography for Care Records',
                'category' => 'communication',
                'description' => 'Consent to take and retain photographs solely within the client\'s internal care record (e.g. wound progression, skin integrity, equipment set-up, identification).',
                'purpose' => 'To support clinical handover, monitor changes in health or skin integrity, and ensure consistent care across staff and shifts.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7 for the collection of health information by image. Images are health information and are managed under the NZ Privacy Act 2020 and Health Information Privacy Code 2020 (Rules 1, 5, 8, 10, 11) - collected only for care purposes, stored securely, and not disclosed externally without further specific consent.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => false,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => null,
                'renewal_required' => false,
                'renewal_reminder_days' => null,
                'withdrawal_implications' => 'No further care-record photographs will be taken. Existing images remain part of the clinical record under standard health-record retention requirements.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Photography for Public/Marketing',
                'category' => 'communication',
                'description' => 'Consent to use photographs or video of the client in public-facing materials such as the provider website, social media, newsletters, annual reports, or marketing collateral.',
                'purpose' => 'To celebrate client achievements, illustrate services, and promote the work of the organisation with the client\'s express permission.',
                'legal_basis' => 'Express informed consent under HDC Code of Rights Right 7 and NZ Privacy Act 2020 (IPP 3, 10, 11) - public use is a separate purpose from internal care use and requires its own specific authorisation. Disclosure of identifiable images is governed by Health Information Privacy Code 2020 Rule 11. Consent must be freely given without any expectation that care or services depend upon it.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 60,
                'withdrawal_implications' => 'No new public/marketing use will occur. The provider will make reasonable efforts to remove existing images from controlled channels (website, owned social media); content already shared externally (e.g. printed materials, third-party reposts) may not be fully retrievable.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Information Sharing with Whānau / Family',
                'category' => 'communication',
                'description' => 'Consent to share personal and health information with named whānau, family, or nominated support people regarding the client\'s wellbeing, care, and significant events.',
                'purpose' => 'To support whānau-centred care, enable family involvement in the client\'s life, and maintain meaningful relationships consistent with the client\'s wishes.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7 (and Right 8 - the right to support). Disclosure of personal and health information to third parties is authorised by the client under NZ Privacy Act 2020 (IPP 11) and Health Information Privacy Code 2020 (Rule 11). The client may specify which whānau members receive which categories of information.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => false,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => null,
                'renewal_required' => false,
                'renewal_reminder_days' => null,
                'withdrawal_implications' => 'No further information will be shared with the named persons except where law (e.g. mandatory reporting, vital interests) or a specific other consent permits. Whānau members will not be told the reason for the change unless the client authorises it.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Medication Administration (Standing Order)',
                'category' => 'medical',
                'description' => 'Consent for support workers to administer prescribed medications under a standing order or medication-administration plan authorised by the prescribing GP or nurse practitioner.',
                'purpose' => 'To ensure safe, timely and consistent administration of prescribed medications by trained and competency-assessed staff.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7 for treatment. Standing orders comply with the Medicines (Standing Order) Regulations 2002 and the Medicines Act 1981. Health information about medications is managed under the NZ Privacy Act 2020 and Health Information Privacy Code 2020 (Rules 5, 10, 11). Where the client may lack capacity, a PPPR Act 1988 capacity assessment is completed and consent is provided by a welfare guardian or activated enduring power of attorney (personal care and welfare).',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 14,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 30,
                'withdrawal_implications' => 'Staff-administered medication will be transitioned over the 14-day notice period. The client (or their representative) is responsible for arranging an alternative administration arrangement; failure to take prescribed medication may have significant clinical consequences which will be discussed with the prescriber.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Body Worn Camera (Staff)',
                'category' => 'safety',
                'description' => 'Consent to staff using body-worn cameras during interactions with the client, typically in the context of de-escalation, behaviours of concern, or documented safety risks.',
                'purpose' => 'To protect both clients and staff, support accurate incident review, and provide evidence for safeguarding investigations where relevant.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7. Surveillance and audio recording are intrusive collections of personal and health information governed by the NZ Privacy Act 2020 (IPP 1-5, 9-11) and Health Information Privacy Code 2020 (Rules 1-5, 9-11), with strict limits on access, retention, and disclosure. Where capacity is in doubt, a PPPR Act 1988 capacity assessment is completed and consent is provided by an appointed welfare guardian or activated enduring power of attorney.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => 90,
                'renewal_required' => true,
                'renewal_reminder_days' => 14,
                'withdrawal_implications' => 'Body-worn cameras will not be used in interactions with the client. Existing recordings are retained for the minimum period required by safeguarding, complaints, and regulatory processes, then securely destroyed.',
                'version' => 1,
                'active' => true,
            ],
            [
                'name' => 'Telehealth Consultation',
                'category' => 'medical',
                'description' => 'Consent to participate in clinical consultations conducted by video or telephone with GPs, nurse practitioners, allied health, or specialist services.',
                'purpose' => 'To improve access to clinical care, reduce travel burden, and enable timely review by the right clinician.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7, including the right to be informed about the nature, benefits and limitations of telehealth (Right 6). Health information transmitted and recorded during telehealth is managed under the NZ Privacy Act 2020 and Health Information Privacy Code 2020 (Rules 5, 10, 11) using secure platforms. Where the client may lack capacity, a PPPR Act 1988 capacity assessment is completed and consent is provided by a welfare guardian or activated enduring power of attorney (personal care and welfare).',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 30,
                'withdrawal_implications' => 'Future telehealth appointments will not proceed. The client will be supported to access in-person clinical care, which may involve longer wait times or travel.',
                'version' => 1,
                'active' => true,
            ],
        ];

        $created = 0;
        $existing = 0;

        foreach ($consentTypes as $type) {
            $consentType = ConsentType::firstOrCreate(
                ['name' => $type['name']],
                $type
            );

            if ($consentType->wasRecentlyCreated) {
                $created++;
            } else {
                $existing++;
            }
        }

        $this->command?->info("Standard consent types seeded: {$created} created, {$existing} already existed.");
    }
}

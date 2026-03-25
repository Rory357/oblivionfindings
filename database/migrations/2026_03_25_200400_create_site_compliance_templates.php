<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_compliance_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('name', 255);
            $table->string('category', 50);
            $table->text('description')->nullable();
            $table->json('checklist_items');
            $table->string('frequency', 20);
            $table->string('regulatory_reference', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // Seed default NZ compliance templates
        $templates = [
            [
                'name' => 'Fire Evacuation Drill',
                'category' => 'fire_emergency',
                'description' => 'Quarterly fire evacuation drill to ensure all staff and residents can safely evacuate the premises.',
                'checklist_items' => json_encode([
                    ['label' => 'Alarm activated', 'required' => true, 'help_text' => 'Ensure fire alarm system is triggered correctly'],
                    ['label' => 'All areas evacuated', 'required' => true, 'help_text' => 'Confirm every room and area has been checked and cleared'],
                    ['label' => 'Assembly point reached', 'required' => true, 'help_text' => 'All persons gathered at designated assembly point'],
                    ['label' => 'Roll call completed', 'required' => true, 'help_text' => 'Account for all staff, residents, and visitors'],
                    ['label' => 'Time recorded', 'required' => true, 'help_text' => 'Record total evacuation time from alarm to roll call completion'],
                    ['label' => 'Debrief conducted', 'required' => true, 'help_text' => 'Post-drill debrief with all participants to identify improvements'],
                ]),
                'frequency' => 'quarterly',
                'regulatory_reference' => 'HSWA 2015 s.36',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Medication Audit',
                'category' => 'medication',
                'description' => 'Monthly audit of medication management practices and storage compliance.',
                'checklist_items' => json_encode([
                    ['label' => 'All medications accounted for', 'required' => true, 'help_text' => 'Verify all prescribed medications match inventory records'],
                    ['label' => 'Storage temp correct', 'required' => true, 'help_text' => 'Check medication fridge is between 2-8°C'],
                    ['label' => 'Expiry dates checked', 'required' => true, 'help_text' => 'Remove and dispose of any expired medications'],
                    ['label' => 'Controlled drugs register balanced', 'required' => true, 'help_text' => 'Reconcile controlled drug counts with register entries'],
                    ['label' => 'PRN usage reviewed', 'required' => true, 'help_text' => 'Review as-needed medication usage patterns'],
                    ['label' => 'Disposal records current', 'required' => true, 'help_text' => 'Ensure medication disposal is documented per regulations'],
                ]),
                'frequency' => 'monthly',
                'regulatory_reference' => 'Medicines Act 1981',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Infection Control Audit',
                'category' => 'infection_control',
                'description' => 'Monthly infection prevention and control audit to maintain hygiene standards.',
                'checklist_items' => json_encode([
                    ['label' => 'Hand hygiene stations stocked', 'required' => true, 'help_text' => 'Soap, sanitiser, and paper towels available at all stations'],
                    ['label' => 'PPE available', 'required' => true, 'help_text' => 'Adequate supply of gloves, masks, aprons, and eye protection'],
                    ['label' => 'Cleaning schedules current', 'required' => true, 'help_text' => 'All cleaning logs signed and up to date'],
                    ['label' => 'Waste disposal correct', 'required' => true, 'help_text' => 'Clinical and general waste segregated correctly'],
                    ['label' => 'Outbreak plan accessible', 'required' => true, 'help_text' => 'Infection outbreak management plan readily available to staff'],
                ]),
                'frequency' => 'monthly',
                'regulatory_reference' => 'HDSS Infection Prevention Standards',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Health & Safety Walkthrough',
                'category' => 'health_safety',
                'description' => 'Monthly walkthrough to identify and address health and safety risks.',
                'checklist_items' => json_encode([
                    ['label' => 'Fire exits clear', 'required' => true, 'help_text' => 'All fire exits unobstructed and clearly marked'],
                    ['label' => 'First aid kits stocked', 'required' => true, 'help_text' => 'Check contents against standard list and replace used items'],
                    ['label' => 'Hazard board updated', 'required' => true, 'help_text' => 'All current hazards displayed on the hazard board'],
                    ['label' => 'Trip hazards addressed', 'required' => true, 'help_text' => 'Walkways clear, rugs secured, cables managed'],
                    ['label' => 'Emergency contacts displayed', 'required' => true, 'help_text' => 'Current emergency contact list posted in visible locations'],
                    ['label' => 'Smoke alarms tested', 'required' => true, 'help_text' => 'Test all smoke detectors and record results'],
                ]),
                'frequency' => 'monthly',
                'regulatory_reference' => 'HSWA 2015 s.36',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Food Safety Check',
                'category' => 'food_safety',
                'description' => 'Weekly food safety and hygiene compliance check.',
                'checklist_items' => json_encode([
                    ['label' => 'Fridge temp 2-4°C', 'required' => true, 'help_text' => 'Record fridge temperature — must be between 2-4°C'],
                    ['label' => 'Freezer temp -18°C', 'required' => true, 'help_text' => 'Record freezer temperature — must be at or below -18°C'],
                    ['label' => 'Food labelled/dated', 'required' => true, 'help_text' => 'All stored food items labelled with contents and date'],
                    ['label' => 'Surfaces sanitised', 'required' => true, 'help_text' => 'All food preparation surfaces cleaned and sanitised'],
                    ['label' => 'Staff hygiene compliance', 'required' => true, 'help_text' => 'Staff following handwashing and food handling protocols'],
                ]),
                'frequency' => 'weekly',
                'regulatory_reference' => 'Food Act 2014',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Vehicle Safety Check',
                'category' => 'vehicle',
                'description' => 'Weekly safety check for all site vehicles used for client transport.',
                'checklist_items' => json_encode([
                    ['label' => 'WoF current', 'required' => true, 'help_text' => 'Warrant of Fitness is valid and not expired'],
                    ['label' => 'Rego current', 'required' => true, 'help_text' => 'Vehicle registration is current'],
                    ['label' => 'Tyre condition', 'required' => true, 'help_text' => 'Tyre tread depth adequate and no visible damage'],
                    ['label' => 'Lights working', 'required' => true, 'help_text' => 'All headlights, indicators, brake lights functioning'],
                    ['label' => 'First aid kit present', 'required' => true, 'help_text' => 'Vehicle first aid kit present and stocked'],
                    ['label' => 'Wheelchair restraints checked', 'required' => true, 'help_text' => 'Wheelchair tie-downs and occupant restraints in good condition'],
                ]),
                'frequency' => 'weekly',
                'regulatory_reference' => 'Land Transport Act 1998',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Restraint Minimisation Review',
                'category' => 'restraint',
                'description' => 'Quarterly review of all restraint events and minimisation strategies.',
                'checklist_items' => json_encode([
                    ['label' => 'All restraint events reviewed', 'required' => true, 'help_text' => 'Review every documented restraint event since last review'],
                    ['label' => 'Alternatives documented', 'required' => true, 'help_text' => 'Alternative approaches explored and documented for each event'],
                    ['label' => 'Consent current', 'required' => true, 'help_text' => 'Informed consent forms are current for any ongoing restraint use'],
                    ['label' => 'Training up to date', 'required' => true, 'help_text' => 'All relevant staff have current restraint minimisation training'],
                    ['label' => 'Debrief records complete', 'required' => true, 'help_text' => 'Post-event debriefs completed and documented for every event'],
                ]),
                'frequency' => 'quarterly',
                'regulatory_reference' => 'DSS Standards 2.1',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cultural Safety Review',
                'category' => 'cultural',
                'description' => 'Six-monthly review of cultural safety practices and Te Tiriti o Waitangi obligations.',
                'checklist_items' => json_encode([
                    ['label' => 'Te Tiriti obligations reviewed', 'required' => true, 'help_text' => 'Review compliance with Te Tiriti o Waitangi partnership obligations'],
                    ['label' => 'Cultural plans current', 'required' => true, 'help_text' => 'Individual cultural support plans reviewed and updated'],
                    ['label' => 'Whānau engagement documented', 'required' => true, 'help_text' => 'Evidence of meaningful whānau involvement in care planning'],
                    ['label' => 'Staff training current', 'required' => true, 'help_text' => 'Cultural competency training completed by all staff'],
                    ['label' => 'Cultural resources available', 'required' => true, 'help_text' => 'Appropriate cultural resources and support accessible on site'],
                ]),
                'frequency' => 'six_monthly',
                'regulatory_reference' => 'Te Tiriti o Waitangi',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Environmental Wellbeing Check',
                'category' => 'environmental',
                'description' => 'Monthly check of the physical environment to ensure comfort and wellbeing of residents.',
                'checklist_items' => json_encode([
                    ['label' => 'Temperature comfortable', 'required' => true, 'help_text' => 'Indoor temperature is within comfortable range (18-24°C)'],
                    ['label' => 'Lighting adequate', 'required' => true, 'help_text' => 'All areas adequately lit with working bulbs and natural light access'],
                    ['label' => 'Noise levels acceptable', 'required' => true, 'help_text' => 'Noise levels are manageable and not causing distress'],
                    ['label' => 'Outdoor areas accessible', 'required' => true, 'help_text' => 'Outdoor spaces are safe, maintained, and accessible to residents'],
                    ['label' => 'Personalisation supported', 'required' => true, 'help_text' => 'Residents able to personalise their own spaces'],
                ]),
                'frequency' => 'monthly',
                'regulatory_reference' => 'DSS Standards 1.3',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'DSS Standards Self-Assessment',
                'category' => 'regulatory',
                'description' => 'Annual self-assessment against NZ Disability Support Services Standards.',
                'checklist_items' => json_encode([
                    ['label' => 'Rights upheld', 'required' => true, 'help_text' => 'Evidence that the rights of all service users are respected and upheld'],
                    ['label' => 'Individual plans current', 'required' => true, 'help_text' => 'All individual support plans reviewed and current'],
                    ['label' => 'Staff qualified', 'required' => true, 'help_text' => 'Staff qualifications and competencies meet service requirements'],
                    ['label' => 'Complaints process accessible', 'required' => true, 'help_text' => 'Complaints process is accessible and understood by all service users'],
                    ['label' => 'Quality improvement active', 'required' => true, 'help_text' => 'Continuous quality improvement programme is active and documented'],
                ]),
                'frequency' => 'annually',
                'regulatory_reference' => 'NZ DSS Standards 2024',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('site_compliance_templates')->insert($templates);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_compliance_templates');
    }
};

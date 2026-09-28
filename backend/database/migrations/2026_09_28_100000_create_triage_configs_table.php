<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_configs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });

        // Seed with the exact values previously hardcoded in
        // public/assets/js/triage.js, so nothing changes for patients until
        // an admin edits something in the Triage Config panel.
        $now = now();

        DB::table('triage_configs')->insert([
            [
                'key' => 'symptoms',
                'value' => json_encode([
                    ['id' => 'breathing', 'label' => 'Breathing', 'system' => 'respiratory', 'floor' => 'orange'],
                    ['id' => 'chest', 'label' => 'Chest or heart', 'system' => 'cardiac', 'floor' => 'orange'],
                    ['id' => 'bleeding', 'label' => 'Bleeding', 'system' => 'trauma', 'floor' => 'yellow'],
                    ['id' => 'burns', 'label' => 'Burns', 'system' => 'burns', 'floor' => 'orange'],
                    ['id' => 'injury', 'label' => 'Injury or fall', 'system' => 'trauma', 'floor' => 'yellow'],
                    ['id' => 'head', 'label' => 'Head or neck', 'system' => 'neuro', 'floor' => 'yellow'],
                    ['id' => 'headache', 'label' => 'Headache', 'system' => 'neuro', 'floor' => 'green'],
                    ['id' => 'abdominal', 'label' => 'Stomach or gut', 'system' => 'abdominal', 'floor' => 'yellow'],
                    ['id' => 'fever', 'label' => 'Fever or flu', 'system' => 'infection', 'floor' => 'green'],
                    ['id' => 'rash', 'label' => 'Skin or rash', 'system' => 'derm', 'floor' => 'green'],
                    ['id' => 'pregnancy', 'label' => 'Pregnancy', 'system' => 'obstetric', 'floor' => 'orange'],
                    ['id' => 'child', 'label' => 'A sick child', 'system' => 'paediatric', 'floor' => 'yellow'],
                    ['id' => 'mental', 'label' => 'Mental health', 'system' => 'mental', 'floor' => 'yellow'],
                    ['id' => 'eye', 'label' => 'Eyes', 'system' => 'ophthal', 'floor' => 'yellow'],
                    ['id' => 'dental', 'label' => 'Teeth', 'system' => 'dental', 'floor' => 'green'],
                    ['id' => 'chronic', 'label' => 'Chronic medicine', 'system' => 'chronic', 'floor' => 'green'],
                    ['id' => 'poisoning', 'label' => 'Poisoning or bite', 'system' => 'toxicology', 'floor' => 'red'],
                    ['id' => 'urinary', 'label' => 'Waterworks', 'system' => 'urology', 'floor' => 'green'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'discriminators',
                'value' => json_encode([
                    ['id' => 'not_breathing', 'level' => 'red', 'label' => 'Not breathing or gasping'],
                    ['id' => 'unresponsive', 'level' => 'red', 'label' => 'Cannot be woken'],
                    ['id' => 'seizure_now', 'level' => 'red', 'label' => 'Fitting right now'],
                    ['id' => 'chest_pain', 'level' => 'red', 'label' => 'Chest pain with sweating or nausea'],
                    ['id' => 'stroke_signs', 'level' => 'red', 'label' => 'Face droop, arm weakness or slurred speech'],
                    ['id' => 'bleeding_heavy', 'level' => 'red', 'label' => 'Bleeding that will not stop'],
                    ['id' => 'poisoning', 'level' => 'red', 'label' => 'Swallowed poison or overdosed'],
                    ['id' => 'snakebite', 'level' => 'red', 'label' => 'Snake or scorpion bite'],
                    ['id' => 'burn_major', 'level' => 'red', 'label' => 'Burn larger than the person\'s chest'],
                    ['id' => 'birth_imminent', 'level' => 'red', 'label' => 'Baby is coming now'],
                    ['id' => 'self_harm_now', 'level' => 'red', 'label' => 'About to harm yourself or someone else'],
                    ['id' => 'anaphylaxis', 'level' => 'red', 'label' => 'Swelling of lips or tongue after a sting, food or medicine'],
                    ['id' => 'burn_sensitive', 'level' => 'orange', 'label' => 'Burn to the face, hands, feet or genitals'],
                    ['id' => 'fracture_open', 'level' => 'orange', 'label' => 'Bone visible or limb bent the wrong way'],
                    ['id' => 'head_injury', 'level' => 'orange', 'label' => 'Head knock with vomiting or confusion'],
                    ['id' => 'infant_fever', 'level' => 'orange', 'label' => 'Baby under 3 months with a fever'],
                    ['id' => 'pregnancy_bleed', 'level' => 'orange', 'label' => 'Bleeding or severe pain while pregnant'],
                    ['id' => 'severe_pain', 'level' => 'orange', 'label' => 'Pain you would score 8 or more out of 10'],
                    ['id' => 'child_floppy', 'level' => 'orange', 'label' => 'Child who is floppy or will not feed'],
                    ['id' => 'diabetic_crisis', 'level' => 'orange', 'label' => 'Diabetic, very thirsty, confused or drowsy'],
                    ['id' => 'assault', 'level' => 'orange', 'label' => 'Assaulted, including sexual assault'],
                    ['id' => 'fever_adult', 'level' => 'yellow', 'label' => 'Fever for more than three days'],
                    ['id' => 'vomiting', 'level' => 'yellow', 'label' => 'Vomiting or diarrhoea that will not stop'],
                    ['id' => 'wound', 'level' => 'yellow', 'label' => 'A cut that may need stitches'],
                    ['id' => 'infection', 'level' => 'yellow', 'label' => 'A wound that is hot, swollen or leaking'],
                    ['id' => 'mental_health', 'level' => 'yellow', 'label' => 'Struggling to cope, needs to talk to someone today'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'weights',
                'value' => json_encode([
                    'categories' => [
                        'mobility' => ['walking' => 0, 'with_help' => 1, 'cannot_walk' => 2],
                        'breathing' => ['normal' => 0, 'short_on_effort' => 1, 'short_at_rest' => 2, 'struggling' => 3],
                        'consciousness' => ['alert' => 0, 'drowsy' => 1, 'responds_to_pain' => 2, 'unresponsive' => 3],
                        'bleeding' => ['none' => 0, 'minor' => 1, 'soaking' => 3],
                        'temperature' => ['normal' => 0, 'feverish' => 1, 'burning_or_cold' => 2],
                        'trauma' => ['no' => 0, 'yes' => 1],
                    ],
                    // painWeight(score) in the original JS: >=8 -> 3, >=5 -> 2, >=3 -> 1, else 0.
                    'pain_thresholds' => [
                        ['min' => 8, 'weight' => 3],
                        ['min' => 5, 'weight' => 2],
                        ['min' => 3, 'weight' => 1],
                        ['min' => 0, 'weight' => 0],
                    ],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'meta',
                'value' => json_encode([
                    'red' => ['label' => 'Red', 'name' => 'Emergency', 'target' => 0, 'targetLabel' => 'Immediately', 'colour' => '#C8102E'],
                    'orange' => ['label' => 'Orange', 'name' => 'Very urgent', 'target' => 10, 'targetLabel' => 'Within 10 minutes', 'colour' => '#E4701E'],
                    'yellow' => ['label' => 'Yellow', 'name' => 'Urgent', 'target' => 60, 'targetLabel' => 'Within 60 minutes', 'colour' => '#F2C200'],
                    'green' => ['label' => 'Green', 'name' => 'Routine', 'target' => 240, 'targetLabel' => 'Within 4 hours', 'colour' => '#1F7A4C'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'capability',
                'value' => json_encode([
                    'respiratory' => ['emergency', 'clinic', 'doctor'],
                    'cardiac' => ['emergency'],
                    'trauma' => ['emergency', 'clinic'],
                    'burns' => ['emergency'],
                    'neuro' => ['emergency', 'clinic', 'doctor'],
                    'abdominal' => ['emergency', 'clinic', 'doctor'],
                    'infection' => ['clinic', 'doctor', 'pharmacy'],
                    'derm' => ['doctor', 'clinic', 'pharmacy'],
                    'obstetric' => ['emergency', 'clinic'],
                    'paediatric' => ['emergency', 'clinic', 'doctor'],
                    'mental' => ['doctor', 'clinic'],
                    'ophthal' => ['doctor', 'clinic'],
                    'dental' => ['doctor'],
                    'chronic' => ['pharmacy', 'doctor'],
                    'toxicology' => ['emergency'],
                    'urology' => ['doctor', 'clinic', 'pharmacy'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_configs');
    }
};

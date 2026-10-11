<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter "Conditions & Symptoms" for search (fully editable afterwards in
 * Staff → System → Conditions) and the two settings that pick which
 * page-builder pages the mobile app shows for "Terms & Conditions" and
 * "About us". Idempotent: only inserts what is missing.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{0: string, 1: string, 2: string}> name, synonyms, specialization
     */
    public static function starterConditions(): array
    {
        return [
            ['Flu', 'influenza, cold, common cold, runny nose, blocked nose', 'General Practitioner'],
            ['Fever', 'high temperature, chills', 'General Practitioner'],
            ['Cough', 'chesty cough, dry cough', 'General Practitioner'],
            ['Sore throat', 'tonsillitis, strep throat, pharyngitis', 'ENT Specialist'],
            ['Headache', 'migraine, tension headache', 'General Practitioner'],
            ['High blood pressure', 'hypertension, bp', 'General Practitioner'],
            ['Diabetes', 'sugar, blood sugar, type 2 diabetes', 'General Practitioner'],
            ['Back pain', 'lower back pain, backache', 'General Practitioner'],
            ['Urinary tract infection', 'uti, bladder infection, burning urine', 'General Practitioner'],
            ['Stomach ache', 'abdominal pain, cramps, indigestion', 'General Practitioner'],
            ['Diarrhoea', 'diarrhea, runny stomach, gastro', 'General Practitioner'],
            ['Vomiting', 'nausea, being sick', 'General Practitioner'],
            ['Asthma', 'wheezing, shortness of breath, inhaler', 'General Practitioner'],
            ['Allergies', 'hay fever, allergic rhinitis, sneezing', 'General Practitioner'],
            ['Sinusitis', 'sinus infection, sinus pain', 'ENT Specialist'],
            ['Ear infection', 'earache, ear pain, otitis', 'ENT Specialist'],
            ['Hearing loss', 'deafness, blocked ears, tinnitus', 'ENT Specialist'],
            ['Snoring', 'sleep apnoea, sleep apnea', 'ENT Specialist'],
            ['Acne', 'pimples, breakouts', 'Dermatologist'],
            ['Eczema', 'dermatitis, itchy skin, dry skin', 'Dermatologist'],
            ['Skin rash', 'hives, rash, itching', 'Dermatologist'],
            ['Psoriasis', 'scaly skin, plaques', 'Dermatologist'],
            ['Hair loss', 'alopecia, thinning hair', 'Dermatologist'],
            ['Moles', 'skin lesion, mole check, skin cancer screening', 'Dermatologist'],
            ['Chest pain', 'angina, heart pain', 'Cardiologist'],
            ['Heart palpitations', 'irregular heartbeat, arrhythmia, racing heart', 'Cardiologist'],
            ['High cholesterol', 'cholesterol, lipids', 'Cardiologist'],
            ['Heart failure', 'swollen ankles, breathlessness', 'Cardiologist'],
            ['Pregnancy care', 'antenatal, prenatal, pregnant, pregnancy check-up', 'Gynaecologist'],
            ['Period problems', 'irregular periods, painful periods, heavy periods', 'Gynaecologist'],
            ['Contraception', 'family planning, birth control, the pill', 'Gynaecologist'],
            ['Pap smear', 'cervical screening, pap test', 'Gynaecologist'],
            ['Menopause', 'hot flushes, perimenopause', 'Gynaecologist'],
            ['Fertility', 'trying to conceive, infertility', 'Gynaecologist'],
            ['Child vaccinations', 'immunisation, immunization, baby shots', 'Pediatrician'],
            ['Child fever', 'sick child, baby fever', 'Pediatrician'],
            ['Colic', 'crying baby, infant colic', 'Pediatrician'],
            ['Child growth check', 'developmental milestones, well baby check', 'Pediatrician'],
            ['Anxiety', 'stress, panic attacks, worry', 'General Practitioner'],
            ['Depression', 'low mood, feeling down', 'General Practitioner'],
            ['Insomnia', 'trouble sleeping, sleeplessness', 'General Practitioner'],
            ['Joint pain', 'arthritis, sore joints, knee pain', 'General Practitioner'],
        ];
    }

    public function up(): void
    {
        if (DB::table('conditions')->count() === 0) {
            $now = now();
            $rows = [];

            foreach (self::starterConditions() as $i => [$name, $synonyms, $specialization]) {
                $rows[] = [
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'synonyms' => $synonyms,
                    'specialization' => $specialization,
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('conditions')->insert($rows);
        }

        foreach (['app_terms_page_slug' => 'terms', 'app_about_page_slug' => 'about'] as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['app_terms_page_slug', 'app_about_page_slug'])->delete();
    }
};

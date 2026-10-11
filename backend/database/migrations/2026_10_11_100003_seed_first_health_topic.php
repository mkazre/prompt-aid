<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starter "Topic of the month" so the home page "Read topic" button has
 * something to open on day one. Fully editable/replaceable at
 * /staff → Site → Health topics (upload the final article there).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('health_topics')->exists()) {
            return;
        }

        $body = <<<'HTML'
<p>Diabetes mellitus is a long-term condition in which the body cannot keep blood sugar (glucose) in a healthy range — either because the pancreas makes too little insulin (type 1) or because the body does not respond to insulin properly (type 2, by far the most common). Left uncontrolled, high blood sugar slowly damages the heart, blood vessels, kidneys, eyes, nerves and feet. In South Africa, diabetes is one of the leading causes of death, and many people living with it do not know they have it.</p>
<h3>Know the warning signs</h3>
<ul>
<li>Being very thirsty and passing urine often, especially at night</li>
<li>Tiredness, blurred vision and unexplained weight loss</li>
<li>Wounds that heal slowly, frequent infections, tingling or numbness in the feet</li>
</ul>
<p>Type 2 diabetes often has no symptoms at first, so a simple finger-prick or blood test is the only way to know.</p>
<h3>How we can stop it in its tracks</h3>
<ol>
<li><strong>Get screened.</strong> Adults over 40, and anyone with a family history, overweight, high blood pressure or a history of diabetes in pregnancy, should have their blood sugar checked at least once a year.</li>
<li><strong>Eat for your blood sugar.</strong> Fill half the plate with vegetables, choose whole grains and beans over white starch, and cut down on sugary drinks, sweets and fried food.</li>
<li><strong>Move every day.</strong> At least 30 minutes of brisk walking or other activity on most days helps the body use insulin.</li>
<li><strong>Reach a healthy weight.</strong> Losing even 5–7% of body weight greatly lowers the risk of developing type 2 diabetes.</li>
<li><strong>Don't smoke, and limit alcohol.</strong></li>
<li><strong>If you have diabetes, stay in care.</strong> Take your medicine every day, check your sugar as advised, and see your practitioner regularly for blood tests, eye checks, kidney checks and foot checks.</li>
</ol>
<h3>How PromptAid can help</h3>
<p>Book a GP for a screening test, have your bloods taken at a partner laboratory, collect repeat medicine from a pharmacy and get a shuttle to and from your appointments — all from one account. Not sure where to start? Use <em>Ask a Doctor</em> on WhatsApp.</p>
<p><em>This article is general health information and does not replace advice from your own practitioner.</em></p>
HTML;

        DB::table('health_topics')->insert([
            'title' => 'How can we combat Diabetes Mellitus in its deadly tracks',
            'slug' => 'combat-diabetes-mellitus',
            'month' => now()->startOfMonth()->toDateString(),
            'summary' => 'Warning signs, screening and everyday steps that prevent and control diabetes.',
            'body' => $body,
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('health_topics')->where('slug', 'combat-diabetes-mellitus')->delete();
    }
};

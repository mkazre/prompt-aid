/* ===================================================================
   Prompt Aid — SATS pre-triage engine
   -------------------------------------------------------------------
   A patient-facing PRE-triage aid. It is NOT the South African Triage
   Scale itself: real SATS is performed by a trained practitioner using
   measured vitals. This engine only ever escalates, never reassures.

   Two mechanisms, exactly as SATS works:
     1. TEWS-lite  — a weighted score from layperson-answerable proxies
                     for mobility, breathing, consciousness, bleeding,
                     temperature, pain and trauma.
     2. Discriminators — a hard override list. Any hit sets the colour
                     regardless of the score. A discriminator can only
                     raise the level, never lower it.

   Port target: app/Services/Triage/SatsEngine.php
   Keep this file and the PHP implementation in sync, and unit-test the
   discriminator table — it is the part with clinical consequence.
   =================================================================== */
(function (root) {
  'use strict';

  var LEVELS = ['green', 'yellow', 'orange', 'red'];      // ascending severity
  var META = {
    red:    { label: 'Red',    name: 'Emergency',    target: 0,   targetLabel: 'Immediately',        colour: '#C8102E' },
    orange: { label: 'Orange', name: 'Very urgent',  target: 10,  targetLabel: 'Within 10 minutes',  colour: '#E4701E' },
    yellow: { label: 'Yellow', name: 'Urgent',       target: 60,  targetLabel: 'Within 60 minutes',  colour: '#F2C200' },
    green:  { label: 'Green',  name: 'Routine',      target: 240, targetLabel: 'Within 4 hours',     colour: '#1F7A4C' }
  };

  /* --- TEWS-lite weights. Adult bands; paediatric multiplier below. --- */
  var WEIGHTS = {
    mobility:      { walking: 0, with_help: 1, cannot_walk: 2 },
    breathing:     { normal: 0, short_on_effort: 1, short_at_rest: 2, struggling: 3 },
    consciousness: { alert: 0, drowsy: 1, responds_to_pain: 2, unresponsive: 3 },
    bleeding:      { none: 0, minor: 1, soaking: 3 },
    temperature:   { normal: 0, feverish: 1, burning_or_cold: 2 },
    trauma:        { no: 0, yes: 1 }
  };

  function painWeight(score) {
    if (score >= 8) return 3;
    if (score >= 5) return 2;
    if (score >= 3) return 1;
    return 0;
  }

  /* --- Discriminators. Any match forces at least the stated level. --- */
  var DISCRIMINATORS = [
    // --- RED ---
    { id: 'not_breathing',    level: 'red', label: 'Not breathing or gasping' },
    { id: 'unresponsive',     level: 'red', label: 'Cannot be woken' },
    { id: 'seizure_now',      level: 'red', label: 'Fitting right now' },
    { id: 'chest_pain',       level: 'red', label: 'Chest pain with sweating or nausea' },
    { id: 'stroke_signs',     level: 'red', label: 'Face droop, arm weakness or slurred speech' },
    { id: 'bleeding_heavy',   level: 'red', label: 'Bleeding that will not stop' },
    { id: 'poisoning',        level: 'red', label: 'Swallowed poison or overdosed' },
    { id: 'snakebite',        level: 'red', label: 'Snake or scorpion bite' },
    { id: 'burn_major',       level: 'red', label: 'Burn larger than the person\'s chest' },
    { id: 'birth_imminent',   level: 'red', label: 'Baby is coming now' },
    { id: 'self_harm_now',    level: 'red', label: 'About to harm yourself or someone else' },
    { id: 'anaphylaxis',      level: 'red', label: 'Swelling of lips or tongue after a sting, food or medicine' },
    // --- ORANGE ---
    { id: 'burn_sensitive',   level: 'orange', label: 'Burn to the face, hands, feet or genitals' },
    { id: 'fracture_open',    level: 'orange', label: 'Bone visible or limb bent the wrong way' },
    { id: 'head_injury',      level: 'orange', label: 'Head knock with vomiting or confusion' },
    { id: 'infant_fever',     level: 'orange', label: 'Baby under 3 months with a fever' },
    { id: 'pregnancy_bleed',  level: 'orange', label: 'Bleeding or severe pain while pregnant' },
    { id: 'severe_pain',      level: 'orange', label: 'Pain you would score 8 or more out of 10' },
    { id: 'child_floppy',     level: 'orange', label: 'Child who is floppy or will not feed' },
    { id: 'diabetic_crisis',  level: 'orange', label: 'Diabetic, very thirsty, confused or drowsy' },
    { id: 'assault',          level: 'orange', label: 'Assaulted, including sexual assault' },
    // --- YELLOW ---
    { id: 'fever_adult',      level: 'yellow', label: 'Fever for more than three days' },
    { id: 'vomiting',         level: 'yellow', label: 'Vomiting or diarrhoea that will not stop' },
    { id: 'wound',            level: 'yellow', label: 'A cut that may need stitches' },
    { id: 'infection',        level: 'yellow', label: 'A wound that is hot, swollen or leaking' },
    { id: 'mental_health',    level: 'yellow', label: 'Struggling to cope, needs to talk to someone today' }
  ];

  /* --- Symptom categories offered after the colour choice. --- */
  var SYMPTOMS = [
    { id: 'breathing',  label: 'Breathing',        system: 'respiratory', floor: 'orange' },
    { id: 'chest',      label: 'Chest or heart',   system: 'cardiac',     floor: 'orange' },
    { id: 'bleeding',   label: 'Bleeding',         system: 'trauma',      floor: 'yellow' },
    { id: 'burns',      label: 'Burns',            system: 'burns',       floor: 'orange' },
    { id: 'injury',     label: 'Injury or fall',   system: 'trauma',      floor: 'yellow' },
    { id: 'head',       label: 'Head or neck',     system: 'neuro',       floor: 'yellow' },
    { id: 'headache',   label: 'Headache',         system: 'neuro',       floor: 'green' },
    { id: 'abdominal',  label: 'Stomach or gut',   system: 'abdominal',   floor: 'yellow' },
    { id: 'fever',      label: 'Fever or flu',     system: 'infection',   floor: 'green' },
    { id: 'rash',       label: 'Skin or rash',     system: 'derm',        floor: 'green' },
    { id: 'pregnancy',  label: 'Pregnancy',        system: 'obstetric',   floor: 'orange' },
    { id: 'child',      label: 'A sick child',     system: 'paediatric',  floor: 'yellow' },
    { id: 'mental',     label: 'Mental health',    system: 'mental',      floor: 'yellow' },
    { id: 'eye',        label: 'Eyes',             system: 'ophthal',     floor: 'yellow' },
    { id: 'dental',     label: 'Teeth',            system: 'dental',      floor: 'green' },
    { id: 'chronic',    label: 'Chronic medicine', system: 'chronic',     floor: 'green' },
    { id: 'poisoning',  label: 'Poisoning or bite',system: 'toxicology',  floor: 'red'    },
    { id: 'urinary',    label: 'Waterworks',       system: 'urology',     floor: 'green'  }
  ];

  /* --- Which facility types can actually treat each system. --- */
  var CAPABILITY = {
    respiratory: ['emergency', 'clinic', 'doctor'],
    cardiac:     ['emergency'],
    trauma:      ['emergency', 'clinic'],
    burns:       ['emergency'],
    neuro:       ['emergency', 'clinic', 'doctor'],
    abdominal:   ['emergency', 'clinic', 'doctor'],
    infection:   ['clinic', 'doctor', 'pharmacy'],
    derm:        ['doctor', 'clinic', 'pharmacy'],
    obstetric:   ['emergency', 'clinic'],
    paediatric:  ['emergency', 'clinic', 'doctor'],
    mental:      ['doctor', 'clinic'],
    ophthal:     ['doctor', 'clinic'],
    dental:      ['doctor'],
    chronic:     ['pharmacy', 'doctor'],
    toxicology:  ['emergency'],
    urology:     ['doctor', 'clinic', 'pharmacy']
  };

  function rank(level) { return LEVELS.indexOf(level); }
  function escalate(current, candidate) {
    return rank(candidate) > rank(current) ? candidate : current;
  }

  /**
   * assess(input) -> result
   * input = {
   *   selfReported : 'red'|'orange'|'yellow'|'green',
   *   ageBand      : 'infant'|'child'|'adult'|'older',
   *   pregnant     : bool,
   *   symptoms     : [symptomId],
   *   discriminators: [discriminatorId],
   *   observations : { mobility, breathing, consciousness, bleeding, temperature, trauma, pain }
   * }
   */
  function assess(input) {
    input = input || {};
    var obs = input.observations || {};
    var reasons = [];

    /* 1 — TEWS-lite score */
    var score = 0;
    Object.keys(WEIGHTS).forEach(function (k) {
      var v = obs[k];
      if (v && WEIGHTS[k][v] != null) score += WEIGHTS[k][v];
    });
    if (obs.pain != null) score += painWeight(Number(obs.pain));

    // Infants and the frail decompensate faster — SATS uses separate
    // paediatric charts; this is the simplified stand-in.
    if (input.ageBand === 'infant') score += 2;
    else if (input.ageBand === 'child' || input.ageBand === 'older') score += 1;
    if (input.pregnant) score += 1;

    var level = score >= 7 ? 'red' : score >= 5 ? 'orange' : score >= 3 ? 'yellow' : 'green';
    if (score > 0) reasons.push('TEWS-lite score ' + score);

    /* 2 — symptom floors */
    (input.symptoms || []).forEach(function (id) {
      var s = SYMPTOMS.filter(function (x) { return x.id === id; })[0];
      if (s && rank(s.floor) > rank(level)) {
        level = s.floor;
        reasons.push(s.label + ' is never below ' + META[s.floor].label);
      }
    });

    /* 3 — discriminators override everything */
    (input.discriminators || []).forEach(function (id) {
      var d = DISCRIMINATORS.filter(function (x) { return x.id === id; })[0];
      if (d) {
        var next = escalate(level, d.level);
        if (next !== level) reasons.push(d.label);
        level = next;
      }
    });

    /* 4 — never go below what the patient told us they felt */
    if (input.selfReported && rank(input.selfReported) > rank(level)) {
      level = input.selfReported;
      reasons.push('You told us it feels this serious');
    }

    /* 5 — capability routing */
    var systems = (input.symptoms || []).map(function (id) {
      var s = SYMPTOMS.filter(function (x) { return x.id === id; })[0];
      return s ? s.system : null;
    }).filter(Boolean);

    var facilities = {};
    systems.forEach(function (sys) {
      (CAPABILITY[sys] || []).forEach(function (f) { facilities[f] = true; });
    });
    if (level === 'red') facilities.emergency = true;

    return {
      level: level,
      meta: META[level],
      score: score,
      reasons: reasons,
      facilityTypes: Object.keys(facilities),
      callAmbulance: level === 'red',
      selfTransportSafe: level === 'yellow' || level === 'green',
      reference: 'TRI-' + Math.random().toString(36).slice(2, 7).toUpperCase()
    };
  }

  root.PromptAidTriage = {
    LEVELS: LEVELS, META: META, SYMPTOMS: SYMPTOMS,
    DISCRIMINATORS: DISCRIMINATORS, CAPABILITY: CAPABILITY,
    assess: assess, escalate: escalate, rank: rank
  };
})(window);

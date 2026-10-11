import { useEffect, useState } from 'react';
import { api } from '../api/client';
import type { TriageLevel } from './triage';

/**
 * Admin-editable copy and contact numbers (backend: App\Support\SiteContent,
 * edited at /staff → Site → Site content). Served by GET /site-content and
 * GET /app-links. The defaults below mirror the backend's and keep the app
 * usable offline or before the first fetch completes.
 */
export interface EmergencyLevelCopy {
  title: string;
  description: string;
  timeframe: string;
  action: string;
}

export interface SiteContent {
  home: Record<string, string>;
  emergency: {
    dispatch_phone: string;
    whatsapp_number: string;
    eyebrow: string;
    title: string;
    intro: string;
    note: string;
    ask_doctor_label: string;
    video_label: string;
    levels: Record<TriageLevel, EmergencyLevelCopy>;
  };
  status: Record<'green' | 'orange' | 'red', { label: string; description: string }>;
  care: {
    see_now_title: string;
    see_now_text: string;
    see_now_button: string;
    tabs: Record<CareTab, { label: string; heading: string; description: string }>;
    virtual_heading: string;
    virtual_description: string;
  };
  topic?: { title: string | null; url: string; testimonial_url: string };
}

export type CareTab = 'gp' | 'dentists' | 'specialists' | 'allied' | 'radiology' | 'laboratories';

export const DEFAULT_SITE_CONTENT: SiteContent = {
  home: {
    eyebrow: 'ONE TECH, ONE GLOBE, ONE EXCELLENT CARE',
    status_note:
      'Please note the status colour of our practitioners; where green means the practitioner is available to see you in 30 min, orange means the practitioner is available to see you on the next available slot on the queue for that day and red means the practitioner is not available to see you on the day but future consults can be booked through the facility\'s admin.',
    topic_heading: 'Topic of the month',
    topic_read_label: 'Read topic',
    topic_testimonial_label: 'Submit a testimonial',
  },
  emergency: {
    dispatch_phone: '081 670 9522',
    whatsapp_number: '27816709522',
    eyebrow: 'PromptAid Emergency Scale',
    title: 'Do you have an emergency?',
    intro: 'Pick the line that sounds most like you.',
    note: 'If someone is not breathing, call the PromptAid dispatch centre now.',
    ask_doctor_label: 'ASK A DOCTOR',
    video_label: 'VIRTUAL/VIDEO consult',
    levels: {
      red: { title: 'I need help right now', description: 'Not breathing properly, chest pain, heavy bleeding, unconscious, fitting, a serious injury.', timeframe: 'Immediately', action: 'Get ambulance now!' },
      orange: { title: 'This is very urgent', description: 'Severe pain, a burn, a deep cut, a head knock, sudden weakness, a baby who will not feed.', timeframe: 'Within 30 minutes', action: 'Get connected to a Doctor in 30min' },
      yellow: { title: 'I need to be seen today', description: 'Fever, vomiting, a wound, pain that is getting worse, a child who is unwell.', timeframe: 'Within 8 hours', action: 'Manually book a Doctor to see today' },
      green: { title: 'It can wait', description: 'Book a specialist, dentist, allied practitioner e.g. physio, radiology/x-ray, lab services.', timeframe: 'In the next few days', action: 'Book a non urgent service or consult' },
    },
  },
  status: {
    green: { label: 'Available in 30 min', description: 'Open to see you immediately — within 30 minutes.' },
    orange: { label: 'Available today', description: 'Available to see you today, at a booked slot in the queue.' },
    red: { label: 'Not available today', description: 'Not available today — book a slot on the next calendar days.' },
  },
  care: {
    see_now_title: "Don't want to wait for a slot?",
    see_now_text: 'See the next available doctor now — by video or in person.',
    see_now_button: 'See a doctor now',
    tabs: {
      gp: { label: 'General Practitioners', heading: 'General Practitioners', description: '' },
      dentists: { label: 'Dentists', heading: 'Dentists', description: '' },
      specialists: { label: 'Specialists', heading: 'Specialists', description: '' },
      allied: { label: 'Allied', heading: 'Allied health practitioners', description: '' },
      radiology: { label: 'Radiology', heading: 'Radiology', description: '' },
      laboratories: { label: 'Laboratories', heading: 'Laboratories', description: '' },
    },
    virtual_heading: 'Virtual / video consult',
    virtual_description: 'Doctors who offer video consultations.',
  },
};

let cache: SiteContent | null = null;

export function useSiteContent(): SiteContent {
  const [content, setContent] = useState<SiteContent>(cache ?? DEFAULT_SITE_CONTENT);

  useEffect(() => {
    let alive = true;
    api
      .get('/site-content')
      .then(({ data }) => {
        if (!alive || !data?.data) return;
        cache = { ...DEFAULT_SITE_CONTENT, ...data.data };
        setContent(cache!);
      })
      .catch(() => {
        /* offline — keep defaults */
      });
    return () => {
      alive = false;
    };
  }, []);

  return content;
}

export function telUrl(phone: string) {
  return 'tel:' + phone.replace(/[^0-9+]/g, '');
}

export function whatsappUrl(number: string) {
  let n = number.replace(/\D/g, '');
  if (n.startsWith('0')) n = '27' + n.slice(1);
  return `https://wa.me/${n}?text=${encodeURIComponent('Hi PromptAid, I would like to ask a doctor a question.')}`;
}

export const STATUS_COLOURS: Record<'green' | 'orange' | 'red', { fg: string; bg: string }> = {
  green: { fg: '#1F7A4C', bg: '#E7F3EC' },
  orange: { fg: '#B4520F', bg: '#FCEEE2' },
  red: { fg: '#C8102E', bg: '#FBEAEA' },
};

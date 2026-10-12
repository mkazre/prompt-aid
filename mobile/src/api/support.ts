import { api } from './client';

/** Help & support tickets, Help centre FAQs, announcements and NPS surveys (backend routes/api_support.php). */

export interface SupportAttachment {
  name: string;
  mime: string | null;
  is_image: boolean;
  url: string | null;
}

export interface SupportReply {
  id: number;
  is_staff: boolean;
  author: string;
  staff_name: string | null;
  body: string;
  attachment: SupportAttachment | null;
  created_at: string;
}

export interface SupportTicket {
  id: number;
  reference: string;
  subject: string;
  category: string;
  category_label: string;
  status: 'open' | 'awaiting_reply' | 'resolved' | 'closed';
  status_label: string;
  priority: string;
  last_reply_at: string | null;
  last_reply_by: 'user' | 'staff' | null;
  created_at: string;
  can_reply: boolean;
  replies?: SupportReply[];
}

export interface SupportMeta {
  enabled: boolean;
  intro: string;
  response_note: string;
  categories: { key: string; label: string }[];
  max_attachment_kb: number;
}

export interface PickedFile {
  uri: string;
  name: string;
  mimeType?: string | null;
}

export interface Faq {
  id: number;
  question: string;
  answer: string;
  answer_html: string;
  audience: string;
  category: { id: number; name: string; slug: string } | null;
}

export interface Announcement {
  id: number;
  title: string | null;
  message: string;
  link_label: string | null;
  link_url: string | null;
  color: string;
  text_color: string;
  is_dismissible: boolean;
  version: number | null;
}

export interface FeedbackSurvey {
  id: number;
  token: string;
  type: 'appointment' | 'ride';
  type_label: string;
  label: string | null;
  score: number | null;
  comment: string | null;
  answered: boolean;
}

export interface FeedbackMeta {
  title: string;
  body: string;
}

export async function fetchSupportMeta(): Promise<SupportMeta> {
  const { data } = await api.get('/support/meta');
  return data.data;
}

export async function fetchTickets(): Promise<SupportTicket[]> {
  const { data } = await api.get('/support/tickets');
  return data.data;
}

export async function fetchTicket(id: number): Promise<SupportTicket> {
  const { data } = await api.get(`/support/tickets/${id}`);
  return data.data;
}

function withFile(fields: Record<string, string>, file?: PickedFile | null) {
  const form = new FormData();
  Object.entries(fields).forEach(([k, v]) => form.append(k, v));
  if (file) form.append('attachment', { uri: file.uri, name: file.name, type: file.mimeType ?? 'application/octet-stream' } as any);
  return form;
}

export async function createTicket(input: { subject: string; category: string; message: string }, file?: PickedFile | null): Promise<SupportTicket> {
  const { data } = await api.post('/support/tickets', withFile(input, file), { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 60000 });
  return data.data;
}

export async function replyToTicket(id: number, message: string, file?: PickedFile | null): Promise<SupportTicket> {
  const { data } = await api.post(`/support/tickets/${id}/reply`, withFile({ message }, file), { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 60000 });
  return data.data;
}

export async function setTicketStatus(id: number, status: 'resolved' | 'open'): Promise<SupportTicket> {
  const { data } = await api.post(`/support/tickets/${id}/status`, { status });
  return data.data;
}

export async function fetchFaqs(q = ''): Promise<{ faqs: Faq[]; heading: string; intro: string; webUrl: string }> {
  const { data } = await api.get('/help/faqs', { params: q ? { q } : undefined });
  return { faqs: data.data, heading: data.meta?.heading ?? 'Help centre', intro: data.meta?.intro ?? '', webUrl: data.meta?.web_url ?? '' };
}

export async function fetchAnnouncements(): Promise<Announcement[]> {
  const { data } = await api.get('/announcements');
  return data.data ?? [];
}

export async function fetchPendingFeedback(): Promise<{ survey: FeedbackSurvey | null; meta: FeedbackMeta }> {
  const { data } = await api.get('/feedback/pending');
  return { survey: data.data, meta: data.meta };
}

export async function fetchFeedback(token: string): Promise<{ survey: FeedbackSurvey; meta: FeedbackMeta }> {
  const { data } = await api.get(`/feedback/${token}`);
  return { survey: data.data, meta: data.meta };
}

export async function submitFeedback(token: string, score: number, comment: string): Promise<FeedbackSurvey> {
  const { data } = await api.post(`/feedback/${token}`, { score, comment: comment.trim() || null });
  return data.data;
}

export async function dismissFeedback(token: string): Promise<void> {
  await api.post(`/feedback/${token}/dismiss`);
}

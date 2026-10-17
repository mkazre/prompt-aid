import { api } from './client';

/**
 * Chat, in-app notifications and video consult API. Kept in its own module
 * (rather than types.ts) so it doesn't collide with other in-flight work.
 */

export interface ChatAttachment {
  name: string;
  mime: string | null;
  size: number | null;
  is_image: boolean;
  url: string; // short-lived signed URL, no auth header needed
}

export interface ChatMessage {
  id: number;
  conversation_id: number;
  sender_user_id: number;
  sender_name: string | null;
  is_mine: boolean;
  body: string | null;
  attachment: ChatAttachment | null;
  read_at: string | null;
  created_at: string;
  /** client-only: optimistic message awaiting the server */
  pending?: boolean;
  failed?: boolean;
  localKey?: string;
}

export interface ConversationAppointment {
  id: number;
  date: string | null;
  start_time: string | null;
  end_time: string | null;
  status: string;
  is_video: boolean;
  reason: string | null;
  can_join_video: boolean;
}

export interface Conversation {
  id: number;
  patient_profile_id: number;
  doctor_profile_id: number;
  counterpart: { name: string; avatar: string | null; role: 'patient' | 'doctor'; subtitle: string };
  appointment: ConversationAppointment | null;
  last_message: { id: number; body: string; is_mine: boolean; created_at: string } | null;
  unread_count: number;
  last_message_at: string | null;
  can_send: boolean;
}

export interface AppNotification {
  id: number;
  type: string;
  title: string;
  body: string | null;
  data: { route?: string; params?: Record<string, any>; url?: string; conversation_id?: number };
  read_at: string | null;
  created_at: string;
}

export interface UnreadCounts {
  notifications: number;
  messages: number;
}

export interface VideoJoin {
  appointment_id: number;
  join_url: string;
  room: string;
  provider: 'jitsi' | 'jaas' | 'self_hosted_jwt' | 'public' | 'external';
  external: boolean;
  display_name: string;
  audio_only: boolean;
  counterpart_name: string | null;
  window_opens_at: string | null;
  window_closes_at: string | null;
  /** Newer servers: our own call page (single-use, 5-minute link) with the signed Jitsi token inside. */
  embed_url?: string | null;
  embed_expires_at?: string | null;
  domain?: string | null;
  /** Short-lived Jitsi JWT (null for the public fallback provider). */
  jwt?: string | null;
  expires_at?: string | null;
  role?: 'moderator' | 'participant';
}

export async function fetchConversations() {
  const res = await api.get<{ data: Conversation[]; total_unread: number }>('/conversations');
  return res.data;
}

export async function startConversation(body: { appointment_id?: number; doctor_profile_id?: number; patient_profile_id?: number }) {
  const res = await api.post<{ data: Conversation }>('/conversations', body);
  return res.data.data;
}

export async function fetchConversation(id: number) {
  const res = await api.get<{ data: Conversation }>(`/conversations/${id}`);
  return res.data.data;
}

export async function fetchMessages(conversationId: number, opts: { after?: number; before?: number } = {}) {
  const res = await api.get<{ data: ChatMessage[]; last_read_own_id: number | null }>(`/conversations/${conversationId}/messages`, { params: opts });
  return res.data;
}

export interface PickedFile {
  uri: string;
  name: string;
  mimeType: string;
  size?: number | null;
}

export async function sendMessage(conversationId: number, body: string, file?: PickedFile | null) {
  const form = new FormData();
  if (body.trim()) form.append('body', body.trim());
  if (file) {
    form.append('attachment', { uri: file.uri, name: file.name, type: file.mimeType } as any);
  }
  const res = await api.post<{ data: ChatMessage }>(`/conversations/${conversationId}/messages`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60000,
  });
  return res.data.data;
}

export async function markConversationRead(conversationId: number) {
  await api.post(`/conversations/${conversationId}/read`);
}

export async function fetchNotifications(page = 1) {
  const res = await api.get<{ data: AppNotification[]; meta: { current_page: number; last_page: number; total: number } }>('/notifications', {
    params: { page },
  });
  return res.data;
}

export async function fetchUnreadCounts() {
  const res = await api.get<UnreadCounts>('/notifications/unread-count');
  return res.data;
}

export async function markNotificationRead(id: number) {
  await api.post(`/notifications/${id}/read`);
}

export async function markAllNotificationsRead() {
  await api.post('/notifications/read-all');
}

export async function deleteNotification(id: number) {
  await api.delete(`/notifications/${id}`);
}

export async function clearNotifications() {
  await api.delete('/notifications');
}

export async function fetchVideoJoin(appointmentId: number, audioOnly = false) {
  const res = await api.get<VideoJoin>(`/appointments/${appointmentId}/video`, { params: audioOnly ? { audio: 1 } : {} });
  return res.data;
}

export async function endVideoCall(appointmentId: number) {
  await api.post(`/appointments/${appointmentId}/video/end`);
}

/** "2m ago", "3h ago", "Yesterday", "12 Sep" */
export function timeAgo(iso: string | null | undefined) {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const diff = Math.max(0, Date.now() - then) / 1000;
  if (diff < 60) return 'Just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 172800) return 'Yesterday';
  return new Date(iso).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' });
}

export function clockTime(iso: string | null | undefined) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' });
}

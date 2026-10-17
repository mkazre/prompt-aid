import { Platform } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  AudioModule,
  RecordingPresets,
  requestRecordingPermissionsAsync,
  setAudioModeAsync,
  type AudioRecorder,
} from 'expo-audio';
import { api, apiErrorMessage } from '../api/client';

/**
 * Trip audio recorder (safety evidence) — a module-level singleton so a
 * recording keeps going when the screen that started it unmounts.
 *
 * Records in ~30 s standalone .m4a pieces: every piece is stopped, queued and
 * uploaded immediately (POST /rides/{ride}/recordings/{rec}/chunks) while the
 * next piece records, so audio already captured survives if the phone is
 * taken. The upload queue is persisted (AsyncStorage) and retried with
 * back-off, so pieces recorded offline are sent when the network returns —
 * including after an app restart (resumePendingUploads()).
 *
 * Limits (honest):
 *  - ~0.1–0.3 s gap between pieces while the recorder rotates.
 *  - Screen locked / app in background: needs the native build with the
 *    expo-audio plugin (enableBackgroundRecording → iOS UIBackgroundModes
 *    "audio", Android foreground service + persistent notification). JS
 *    timers can be throttled in the background, so a piece may run longer
 *    than 30 s until the app wakes; it is still uploaded.
 *  - If the OS kills the app, the piece being recorded at that moment is lost.
 *  - Expo Go does not support background recording.
 */

export type RecorderPhase = 'idle' | 'starting' | 'recording' | 'stopping';

export interface RecorderSnapshot {
  phase: RecorderPhase;
  rideId: number | null;
  recordingId: number | null;
  startedAt: number | null;
  pending: number; // pieces waiting to upload
  offline: boolean;
  error: string | null;
}

interface QueueItem {
  rideId: number;
  recordingId: number;
  seq: number;
  uri: string;
  durationMs: number;
  final?: boolean; // send /complete after this one
  reason?: string;
}

const QUEUE_KEY = 'pa.tripRecorder.queue.v1';
const CONSENT_KEY = 'pa.tripRecorder.consent.v1';

const BASE = RecordingPresets.HIGH_QUALITY;
const OPTIONS = {
  ...BASE,
  extension: '.m4a',
  sampleRate: 22050,
  numberOfChannels: 1,
  bitRate: 32000,
};

function platformOptions() {
  const common = {
    extension: OPTIONS.extension,
    sampleRate: OPTIONS.sampleRate,
    numberOfChannels: OPTIONS.numberOfChannels,
    bitRate: OPTIONS.bitRate,
    isMeteringEnabled: false,
  };
  if (Platform.OS === 'ios') return { ...common, ...OPTIONS.ios, sampleRate: OPTIONS.sampleRate, bitRate: OPTIONS.bitRate };
  if (Platform.OS === 'android') return { ...common, ...OPTIONS.android, sampleRate: OPTIONS.sampleRate, bitRate: OPTIONS.bitRate };
  return { ...common, ...OPTIONS.web };
}

let snap: RecorderSnapshot = { phase: 'idle', rideId: null, recordingId: null, startedAt: null, pending: 0, offline: false, error: null };
const listeners = new Set<(s: RecorderSnapshot) => void>();

let recorder: AudioRecorder | null = null;
let seq = 0;
let pieceStartedAt = 0;
let rotateTimer: ReturnType<typeof setTimeout> | null = null;
let maxTimer: ReturnType<typeof setTimeout> | null = null;
let queue: QueueItem[] = [];
let uploading = false;
let failures = 0;
let retryTimer: ReturnType<typeof setTimeout> | null = null;

function emit(patch: Partial<RecorderSnapshot>) {
  snap = { ...snap, ...patch, pending: queue.length };
  listeners.forEach((l) => l(snap));
}

export function subscribe(fn: (s: RecorderSnapshot) => void): () => void {
  listeners.add(fn);
  fn(snap);
  return () => listeners.delete(fn);
}

export function getSnapshot() {
  return snap;
}

export async function hasConsented(): Promise<boolean> {
  try {
    return (await AsyncStorage.getItem(CONSENT_KEY)) === '1';
  } catch {
    return false;
  }
}

export async function rememberConsent() {
  try {
    await AsyncStorage.setItem(CONSENT_KEY, '1');
  } catch {
    /* ignore */
  }
}

async function persistQueue() {
  try {
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  } catch {
    /* ignore */
  }
}

function enqueue(item: QueueItem) {
  queue.push(item);
  emit({});
  persistQueue();
  pump();
}

async function pump() {
  if (uploading || !queue.length) return;
  uploading = true;
  const item = queue[0];
  try {
    if (item.uri) {
      const form = new FormData();
      // React Native FormData file part.
      form.append('chunk', { uri: item.uri, name: `piece-${item.seq}.m4a`, type: 'audio/m4a' } as any);
      form.append('seq', String(item.seq));
      form.append('duration_ms', String(Math.max(0, Math.round(item.durationMs))));
      await api.post(`/rides/${item.rideId}/recordings/${item.recordingId}/chunks`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        timeout: 120000,
        transformRequest: (d) => d,
      });
    }
    if (item.final) {
      await api.post(`/rides/${item.rideId}/recordings/${item.recordingId}/complete`, { reason: item.reason ?? 'stopped' }).catch(() => undefined);
    }
    queue.shift();
    failures = 0;
    emit({ offline: false });
  } catch (e: any) {
    const status: number | undefined = e?.response?.status;
    if (status && status >= 400 && status < 500 && ![408, 419, 429].includes(status)) {
      // Permanently rejected (e.g. window closed, too large): drop it, report it.
      queue.shift();
      emit({ error: apiErrorMessage(e, 'A piece of the recording was rejected.') });
      if (status === 410 || status === 422) {
        if (snap.phase === 'recording' && snap.recordingId === item.recordingId) stopRecording('rejected');
      }
    } else {
      failures++;
      emit({ offline: true });
    }
  } finally {
    uploading = false;
    persistQueue();
  }
  if (queue.length) {
    if (failures > 0) {
      if (retryTimer) clearTimeout(retryTimer);
      retryTimer = setTimeout(pump, Math.min(60000, 2000 * 2 ** Math.min(failures, 5)));
    } else {
      pump();
    }
  }
}

/** Call on app start / screen mount: retries pieces left over from a previous session. */
export async function resumePendingUploads() {
  if (queue.length || snap.phase !== 'idle') return;
  try {
    const raw = await AsyncStorage.getItem(QUEUE_KEY);
    const saved = raw ? (JSON.parse(raw) as QueueItem[]) : [];
    if (Array.isArray(saved) && saved.length && !queue.length) {
      queue = saved;
      // A recording interrupted by an app kill: make sure it is closed after its pieces.
      const last = queue[queue.length - 1];
      if (!last.final) queue.push({ ...last, uri: '', seq: -1, durationMs: 0, final: true, reason: 'app_restarted' });
      emit({});
      pump();
    }
  } catch {
    /* ignore */
  }
}

async function beginPiece() {
  if (!recorder) return;
  await recorder.prepareToRecordAsync();
  recorder.record();
  pieceStartedAt = Date.now();
}

async function rotate(chunkSeconds: number) {
  if (!recorder || snap.phase !== 'recording' || snap.rideId == null || snap.recordingId == null) return;
  try {
    await recorder.stop();
    const uri = recorder.uri;
    const durationMs = Date.now() - pieceStartedAt;
    if (uri) enqueue({ rideId: snap.rideId, recordingId: snap.recordingId, seq: seq++, uri, durationMs });
    await beginPiece();
  } catch (e: any) {
    emit({ error: 'The recorder stopped unexpectedly: ' + (e?.message ?? 'unknown error') });
    await stopRecording('recorder_error');
    return;
  }
  rotateTimer = setTimeout(() => rotate(chunkSeconds), chunkSeconds * 1000);
}

/**
 * Start recording a ride. Caller must have shown the consent explainer.
 * Throws a user-facing Error when it cannot start.
 */
export async function startRecording(rideId: number): Promise<void> {
  if (snap.phase !== 'idle') return;
  emit({ phase: 'starting', error: null, rideId });
  try {
    const perm = await requestRecordingPermissionsAsync();
    if (!perm.granted) {
      throw new Error('Microphone access is off. Allow the microphone for Prompt Aid in your phone settings, then try again.');
    }
    await setAudioModeAsync({
      allowsRecording: true,
      playsInSilentMode: true,
      shouldPlayInBackground: true,
      allowsBackgroundRecording: true,
      interruptionMode: 'mixWithOthers',
    });

    let res;
    try {
      res = await api.post(`/rides/${rideId}/recordings`, { mime: 'audio/m4a', layout: 'segments', consent: true });
    } catch (e) {
      throw new Error(apiErrorMessage(e, 'Could not start recording.'));
    }
    const recordingId: number = res.data.recording.id;
    const chunkSeconds: number = Math.max(10, Number(res.data.chunk_seconds) || 30);
    const maxMinutes: number = Math.max(1, Number(res.data.max_minutes) || 120);

    recorder = new AudioModule.AudioRecorder(platformOptions() as any);
    seq = 0;
    await beginPiece();
    emit({ phase: 'recording', recordingId, startedAt: Date.now() });
    rotateTimer = setTimeout(() => rotate(chunkSeconds), chunkSeconds * 1000);
    maxTimer = setTimeout(() => stopRecording('max_length'), maxMinutes * 60000);
  } catch (e: any) {
    cleanupRecorder();
    emit({ phase: 'idle', rideId: null, recordingId: null, startedAt: null, error: e?.message ?? 'Could not start recording.' });
    throw e;
  }
}

function cleanupRecorder() {
  if (rotateTimer) clearTimeout(rotateTimer);
  if (maxTimer) clearTimeout(maxTimer);
  rotateTimer = null;
  maxTimer = null;
  try {
    recorder?.release();
  } catch {
    /* ignore */
  }
  recorder = null;
}

/** Stop, upload the last piece and close the recording on the server. */
export async function stopRecording(reason = 'stopped'): Promise<void> {
  if (snap.phase !== 'recording' || snap.rideId == null || snap.recordingId == null) return;
  const rideId = snap.rideId;
  const recordingId = snap.recordingId;
  emit({ phase: 'stopping' });
  if (rotateTimer) clearTimeout(rotateTimer);
  if (maxTimer) clearTimeout(maxTimer);
  let uri: string | null = null;
  try {
    if (recorder) {
      await recorder.stop();
      uri = recorder.uri;
    }
  } catch {
    /* recorder already stopped */
  }
  enqueue({ rideId, recordingId, seq: uri ? seq++ : -1, uri: uri ?? '', durationMs: Date.now() - pieceStartedAt, final: true, reason });
  cleanupRecorder();
  try {
    await setAudioModeAsync({ allowsRecording: false, allowsBackgroundRecording: false });
  } catch {
    /* ignore */
  }
  emit({ phase: 'idle', rideId: null, recordingId: null, startedAt: null });
}

export function clearError() {
  emit({ error: null });
}

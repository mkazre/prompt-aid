import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, Modal, Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { WebView, WebViewMessageEvent } from 'react-native-webview';
import type { ShouldStartLoadRequest } from 'react-native-webview/lib/WebViewTypes';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { apiErrorMessage } from '../../api/client';
import {
  cancelPaymentIntent,
  createPaymentIntent,
  getPaymentIntent,
  getPaymentOptions,
  isSecured,
  PayableType,
  PaymentIntent,
  PaymentMethodOption,
  PaymentOptions,
  serviceFor,
  subscribeToPlan,
} from '../../api/payments';
import { PaButton } from '../pa';
import { pa, paFonts, paRadius } from '../../theme';

/**
 * In-app payment sheet (Mr D style): choose a method, then the gateway's own
 * widget (PayFast Onsite / Paystack Inline / Ozow) runs INSIDE this sheet in a
 * WebView pointed at our /pay/{id}/embed page — never the external browser.
 * Completion is only trusted after the server confirms it (verified webhook),
 * which we poll for. Works in test mode (mock) when no gateway is live.
 *
 *   <PaymentSheet visible payable={{ type: 'order', id: 12 }} amount={245} title="Order PA-123"
 *     onPaid={(intent) => ...} onClose={() => ...} />
 *   <PaymentSheet visible planId={3} amount={99} title="Chronic medication monthly" ... />
 */
export type PaymentSheetProps = {
  visible: boolean;
  /** What to pay for (omit when subscribing to a plan). */
  payable?: { type: PayableType; id: number };
  /** Subscribe to a plan instead (PayFast recurring billing). */
  planId?: number;
  /** Display only — the server computes the real amount. */
  amount?: number;
  title?: string;
  onPaid: (intent: PaymentIntent) => void;
  onClose: () => void;
};

type Step = 'choose' | 'web' | 'confirming' | 'done' | 'failed';

const uid = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
const money = (n?: number | null) => `R ${Number(n ?? 0).toFixed(2)}`;

export default function PaymentSheet({ visible, payable, planId, amount, title, onPaid, onClose }: PaymentSheetProps) {
  const insets = useSafeAreaInsets();
  const [step, setStep] = useState<Step>('choose');
  const [options, setOptions] = useState<PaymentOptions | null>(null);
  const [method, setMethod] = useState<string | null>(null);
  const [saveCard, setSaveCard] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [embedUrl, setEmbedUrl] = useState<string | null>(null);
  const [intent, setIntent] = useState<PaymentIntent | null>(null);
  const returnPrefix = useRef<string>('');
  const idemKey = useRef(uid());
  const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pollStarted = useRef(0);
  const intentRef = useRef<PaymentIntent | null>(null);
  intentRef.current = intent;

  const service = planId ? 'subscription' : serviceFor(payable?.type ?? 'invoice');

  const stopPoll = () => {
    if (pollTimer.current) clearTimeout(pollTimer.current);
    pollTimer.current = null;
  };

  const reset = useCallback(() => {
    stopPoll();
    setStep('choose');
    setError(null);
    setEmbedUrl(null);
    setIntent(null);
    idemKey.current = uid();
  }, []);

  useEffect(() => {
    if (!visible) return;
    reset();
    setOptions(null);
    getPaymentOptions(service)
      .then((o) => {
        setOptions(o);
        setMethod(o.methods[0]?.key ?? null);
      })
      .catch((e) => setError(apiErrorMessage(e)));
    return stopPoll;
  }, [visible, service, reset]);

  const poll = useCallback(
    (every: number, confirming: boolean) => {
      stopPoll();
      if (confirming) pollStarted.current = Date.now();
      const tick = async () => {
        const current = intentRef.current;
        if (!current) return;
        try {
          const fresh = await getPaymentIntent(current.id);
          setIntent(fresh);
          if (isSecured(fresh.status)) {
            stopPoll();
            setStep('done');
            return;
          }
          if (fresh.status === 'failed' || (confirming && fresh.status === 'cancelled')) {
            stopPoll();
            setError(fresh.message);
            setStep('failed');
            return;
          }
          if (confirming && Date.now() - pollStarted.current > 120000) {
            stopPoll();
            setError('Your bank has not confirmed yet. We will update this automatically as soon as it does.');
            setStep('failed');
            return;
          }
        } catch {
          // network blip: keep polling
        }
        pollTimer.current = setTimeout(tick, every);
      };
      pollTimer.current = setTimeout(tick, confirming ? 800 : every);
    },
    [],
  );

  async function start() {
    if (!method) return;
    setBusy(true);
    setError(null);
    try {
      const res = planId
        ? await subscribeToPlan({ plan_id: planId, gateway: method, idempotency_key: idemKey.current })
        : await createPaymentIntent({
            payable_type: payable!.type,
            payable_id: payable!.id,
            gateway: method,
            idempotency_key: idemKey.current,
            save_card: saveCard,
          });
      returnPrefix.current = res.return_prefix;
      setIntent(res.intent);
      intentRef.current = res.intent;
      setEmbedUrl(res.embed_url);
      setStep('web');
      poll(4000, false);
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  function confirm() {
    setStep('confirming');
    poll(2000, true);
  }

  function backToMethods(msg?: string) {
    const current = intentRef.current;
    if (current && current.status === 'pending') cancelPaymentIntent(current.id);
    reset();
    if (msg) setError(msg);
  }

  function handleReturnUrl(url: string) {
    const outcome = url.slice(returnPrefix.current.length).replace(/^\//, '').split(/[/?#]/)[0];
    if (outcome === 'success') confirm();
    else if (outcome === 'cancel') backToMethods('Payment cancelled. You can choose another method.');
    else confirm(); // error: let the server tell us what happened
  }

  function onShouldStart(req: ShouldStartLoadRequest) {
    const url = req.url || '';
    if (returnPrefix.current && url.startsWith(returnPrefix.current)) {
      handleReturnUrl(url);
      return false;
    }
    if (/^https?:/i.test(url) || url.startsWith('about:') || url.startsWith('data:')) return true;
    // Banking-app deep links (instant EFT) open the bank's app, not a browser.
    Linking.openURL(url).catch(() => undefined);
    return false;
  }

  function onMessage(e: WebViewMessageEvent) {
    try {
      const msg = JSON.parse(e.nativeEvent.data);
      if (msg?.type !== 'pa-payment') return;
      if (msg.event === 'completed') confirm();
      else if (msg.event === 'cancelled') backToMethods('Payment cancelled. You can choose another method.');
      else if (msg.event === 'error') confirm();
    } catch {
      // ignore
    }
  }

  function close() {
    const current = intentRef.current;
    stopPoll();
    if (step === 'done' && current) {
      onPaid(current);
    } else if (current && current.status === 'pending') {
      cancelPaymentIntent(current.id);
    }
    onClose();
  }

  const shownAmount = intent?.amount ?? amount;
  const isTest = !!options?.mock;
  const tall = step === 'web';

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={close}>
      <View style={styles.backdrop}>
        <Pressable style={{ flex: 1 }} onPress={step === 'choose' ? close : undefined} />
        <View style={[styles.sheet, tall && styles.sheetTall, { paddingBottom: Math.max(insets.bottom, 12) }]}>
          <View style={styles.grabber} />
          <View style={styles.header}>
            <View style={{ flex: 1 }}>
              <Text style={styles.kicker}>{planId ? 'Subscription' : 'Secure payment'}</Text>
              <Text style={styles.title} numberOfLines={1}>{title ?? 'Pay'}</Text>
            </View>
            {shownAmount != null && <Text style={styles.amount}>{money(shownAmount)}</Text>}
            <Pressable onPress={close} hitSlop={12} accessibilityLabel="Close payment">
              <Text style={styles.close}>✕</Text>
            </Pressable>
          </View>

          {step === 'choose' && (
            <ScrollView contentContainerStyle={{ padding: 16 }}>
              {isTest && <Text style={styles.test}>Test mode — online payments are not switched on yet, no real money moves.</Text>}
              {!options && !error && <ActivityIndicator color={pa.ink} style={{ marginVertical: 24 }} />}
              {options && options.methods.length === 0 && (
                <Text style={styles.muted}>Online payment is not available for this right now. Please try again later.</Text>
              )}
              {options?.methods.map((m: PaymentMethodOption) => {
                const on = method === m.key;
                return (
                  <Pressable key={m.key} onPress={() => setMethod(m.key)} style={[styles.method, on && styles.methodOn]} accessibilityRole="radio" accessibilityState={{ selected: on }}>
                    <View style={[styles.mark, { backgroundColor: m.color }]}><Text style={styles.markText}>{m.mark}</Text></View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.methodLabel}>{m.label}</Text>
                      <Text style={styles.methodHint}>{m.hint}{m.test_mode && m.key !== 'mock' ? ' · sandbox' : ''}</Text>
                    </View>
                    <View style={[styles.radio, on && styles.radioOn]} />
                  </Pressable>
                );
              })}
              {options?.save_card_supported && service === 'ride' && (
                <View style={styles.saveRow}>
                  <Text style={[styles.muted, { flex: 1 }]}>Save my card for future shuttle fares and fees</Text>
                  <Switch value={saveCard} onValueChange={setSaveCard} />
                </View>
              )}
              {error && <Text style={styles.error}>{error}</Text>}
              <PaButton
                title={planId ? `Subscribe · ${money(shownAmount)}` : `Pay ${money(shownAmount)}`}
                onPress={start}
                loading={busy}
                disabled={!method || !options?.methods.length}
                style={{ marginTop: 16 }}
              />
              <Text style={styles.fine}>Card and bank details are entered on the provider's secure page. PromptAid never sees or stores them.</Text>
            </ScrollView>
          )}

          {step === 'web' && embedUrl && (
            <View style={{ flex: 1 }}>
              <WebView
                source={{ uri: embedUrl }}
                onShouldStartLoadWithRequest={onShouldStart}
                onMessage={onMessage}
                javaScriptEnabled
                domStorageEnabled
                setSupportMultipleWindows={false}
                startInLoadingState
                renderLoading={() => <ActivityIndicator color={pa.ink} style={StyleSheet.absoluteFill} />}
                style={{ flex: 1, backgroundColor: '#fff' }}
              />
              <Pressable onPress={() => backToMethods()} style={styles.change}>
                <Text style={styles.changeText}>← Change payment method</Text>
              </Pressable>
            </View>
          )}

          {(step === 'confirming' || step === 'done' || step === 'failed') && (
            <View style={styles.result}>
              {step === 'confirming' && <ActivityIndicator size="large" color={pa.ink} />}
              {step === 'done' && <View style={[styles.icon, { backgroundColor: pa.go }]}><Text style={styles.iconText}>✓</Text></View>}
              {step === 'failed' && <View style={[styles.icon, { backgroundColor: pa.signal }]}><Text style={styles.iconText}>!</Text></View>}
              <Text style={styles.resultTitle}>
                {step === 'confirming' ? 'Confirming your payment…' : step === 'done' ? (intent?.status === 'authorized' ? 'Payment secured' : 'Payment successful') : 'Payment not completed'}
              </Text>
              <Text style={[styles.muted, { textAlign: 'center' }]}>
                {step === 'confirming' ? 'This usually takes a few seconds.' : step === 'done' ? intent?.message : error}
              </Text>
              {step !== 'confirming' && (
                <PaButton title={step === 'done' ? 'Done' : 'Try again'} onPress={step === 'done' ? close : () => reset()} style={{ marginTop: 20, alignSelf: 'stretch' }} />
              )}
            </View>
          )}
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(16,16,18,0.55)' },
  sheet: { backgroundColor: pa.surface, borderTopLeftRadius: 16, borderTopRightRadius: 16, maxHeight: '88%', minHeight: 320 },
  sheetTall: { height: '92%', maxHeight: '92%' },
  grabber: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: pa.line, marginTop: 8 },
  header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: pa.line },
  kicker: { fontFamily: paFonts.black, fontSize: 10, letterSpacing: 1.2, textTransform: 'uppercase', color: pa.muted },
  title: { fontFamily: paFonts.black, fontSize: 17, color: pa.ink, marginTop: 2 },
  amount: { fontFamily: paFonts.black, fontSize: 18, color: pa.ink },
  close: { fontSize: 18, color: pa.muted, paddingLeft: 4 },
  test: { backgroundColor: pa.beaconWash, borderWidth: 1, borderColor: pa.beaconLine, padding: 10, fontFamily: paFonts.regular, fontSize: 12, color: pa.ink, marginBottom: 12 },
  method: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderColor: pa.line, borderRadius: paRadius.sm, padding: 12, marginBottom: 10, backgroundColor: pa.surface },
  methodOn: { borderColor: pa.ink, borderWidth: 2, padding: 11 },
  mark: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center', borderRadius: paRadius.sm },
  markText: { color: '#fff', fontFamily: paFonts.black, fontSize: 13 },
  methodLabel: { fontFamily: paFonts.bold, fontSize: 15, color: pa.ink },
  methodHint: { fontFamily: paFonts.regular, fontSize: 12, color: pa.muted, marginTop: 2 },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: pa.line },
  radioOn: { borderColor: pa.ink, backgroundColor: pa.ink },
  saveRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 6 },
  error: { backgroundColor: pa.signalWash, color: pa.signalInk, padding: 10, marginTop: 12, fontFamily: paFonts.regular, fontSize: 13 },
  muted: { fontFamily: paFonts.regular, fontSize: 13, color: pa.muted },
  fine: { fontFamily: paFonts.regular, fontSize: 11, color: pa.muted, textAlign: 'center', marginTop: 10 },
  change: { paddingVertical: 12, alignItems: 'center', borderTopWidth: 1, borderTopColor: pa.line },
  changeText: { fontFamily: paFonts.bold, fontSize: 13, color: pa.ink },
  result: { padding: 28, alignItems: 'center', gap: 8 },
  icon: { width: 60, height: 60, borderRadius: 30, alignItems: 'center', justifyContent: 'center', marginBottom: 6 },
  iconText: { color: '#fff', fontSize: 28, fontFamily: paFonts.black },
  resultTitle: { fontFamily: paFonts.black, fontSize: 19, color: pa.ink, textAlign: 'center' },
});

// Firebase Cloud Messaging web push registration. Only attempted when the backend reports
// a Firebase project is configured (shell.fcm_web_config, see app/settings.php's
// notifications.fcm_web_config) — otherwise this module is never invoked at all, so an
// unconfigured deployment pays zero cost (no Firebase SDK fetch, no permission prompt).
import { api } from './api/client';
import type { FcmWebConfig } from './auth/session';

let registered = false;

export async function registerPushIfConfigured(fcmWebConfig: FcmWebConfig | null | undefined): Promise<void> {
  if (!fcmWebConfig || registered) return;
  if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
  if (Notification.permission === 'denied') return;

  try {
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return;

    // Dynamically imported so the Firebase SDK is never fetched for deployments that
    // haven't configured a project — same "pay only if used" principle as the backend gate.
    const { initializeApp } = await import('firebase/app');
    const { getMessaging, getToken } = await import('firebase/messaging');

    const app = initializeApp(fcmWebConfig);
    const messaging = getMessaging(app);
    const registration = await navigator.serviceWorker.ready;
    const token = await getToken(messaging, { vapidKey: fcmWebConfig.vapidKey, serviceWorkerRegistration: registration });
    if (!token) return;

    await api.post('/push/subscribe', { fcm_token: token });
    registered = true;
  } catch {
    // Push is a nice-to-have layered on top of in-app/SMS notifications — never let a
    // browser/SDK/permission failure surface as an error to the user.
  }
}

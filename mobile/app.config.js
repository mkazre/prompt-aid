// Extends app.json. Android push notifications when the app is closed need
// Firebase (FCM): upload google-services.json to EAS as a *file* environment
// variable named GOOGLE_SERVICES_JSON (expo.dev → project → Environment
// variables) and it is picked up here at build time. Without it the app
// still alerts (with sound) while it is open, via its in-app poller.
module.exports = ({ config }) => ({
  ...config,
  android: {
    ...config.android,
    ...(process.env.GOOGLE_SERVICES_JSON ? { googleServicesFile: process.env.GOOGLE_SERVICES_JSON } : {}),
  },
});

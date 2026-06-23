/**
 * AES social sign-in (Google, Microsoft, WhatsApp) — same Firebase flow as login.aesajce.in.
 */
import { initializeApp } from 'https://www.gstatic.com/firebasejs/9.9.1/firebase-app.js';
import {
  getAuth,
  signInWithPopup,
  GoogleAuthProvider,
  OAuthProvider,
} from 'https://www.gstatic.com/firebasejs/9.9.1/firebase-auth.js';
import { getDatabase, ref, set, onValue } from 'https://www.gstatic.com/firebasejs/9.9.1/firebase-database.js';

const firebaseConfig = {
  apiKey: 'AIzaSyBQtpPtf6cfzdJ3EhMf7U34a9JaU2_PPHk',
  authDomain: 'auth.aesajce.in',
  projectId: 'aes-ajce',
  storageBucket: 'aes-ajce.appspot.com',
  messagingSenderId: '535907930790',
  appId: '1:535907930790:web:ea0eb1957fc69228359a67',
  databaseURL: 'https://aes-ajce-default-rtdb.asia-southeast1.firebasedatabase.app',
};

initializeApp(firebaseConfig);
const auth = getAuth();
const db = getDatabase();

function socialLogin(provider) {
  provider.addScope('email');
  provider.setCustomParameters({ prompt: 'select_account' });
  return signInWithPopup(auth, provider)
    .then((data) => {
      const user = {};
      if (data.user !== null) {
        data.user.providerData.forEach((profile) => {
          user.uid = data.user.uid;
          user.provider = profile.providerId;
          user.email = profile.email || data.user.email;
        });
      }
      return user;
    });
}

function setWaOtp(onCode, onVerify) {
  const d = new Date();
  const t = d.toLocaleTimeString('it-IT', { timeZone: 'Asia/Kolkata' }).split(':');
  let ucode = (d.getMilliseconds() + 36) + '' + ((((parseInt(t[1], 10) % 15) + 1) * 60) + parseInt(t[2], 10));
  let waotp = parseInt(ucode, 10).toString(36);
  if (waotp.length < 4) {
    waotp = Math.random().toString(36).substring(2, 3) + waotp;
  }
  const code = waotp.toUpperCase();
  return set(ref(db, 'WOTP/' + code), { cmin: t[1] }).then(() => {
    onCode(code);
    const starCountRef = ref(db, 'WOTP/' + code);
    onValue(starCountRef, (snapshot) => {
      const snap = snapshot.val();
      if (snap && snap.user && snap.cmin && snap.checksum) {
        set(ref(db, 'WOTP/' + code), {}).then(() => {
          onVerify({
            provider: 'whatsapp',
            user: snap.user,
            otp: code,
            checksum: snap.checksum,
          });
        });
      }
    });
  });
}

function runSocial(handler) {
  if (typeof window.submitAesCheckLogin !== 'function') {
    console.error('AES login handler not ready');
    return;
  }
  window.submitAesCheckLogin(handler(), true);
}

window.aesLoginWithGoogle = () => {
  runSocial(() => socialLogin(new GoogleAuthProvider()));
};

window.aesLoginWithMicrosoft = () => {
  runSocial(() => socialLogin(new OAuthProvider('microsoft.com')));
};

window.aesLoginWithWhatsapp = () => {
  const overlay = document.getElementById('aesWaOtpPanel');
  const codeEl = document.getElementById('aesWaOtpCode');
  if (!overlay || !codeEl) return;

  overlay.hidden = false;
  codeEl.textContent = '…';
  setWaOtp(
    (code) => { codeEl.textContent = code; },
    (payload) => {
      overlay.hidden = true;
      window.submitAesCheckLogin(Promise.resolve(payload), true);
    },
  ).catch((err) => {
    overlay.hidden = true;
    if (typeof window.showAesLoginError === 'function') {
      window.showAesLoginError(err.message || 'WhatsApp sign-in failed');
    }
  });
};

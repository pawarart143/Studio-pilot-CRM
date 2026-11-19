/**
 * Reminder ring sound, synthesised with the Web Audio API (no audio file).
 *
 * Browsers only allow sound after the person has interacted with the page.
 * We never try to play on page load: the AudioContext is created on the
 * first click / key press, and ring() returns false (so the caller shows the
 * visual alert alone) until that has happened.
 */
let ctx: AudioContext | null = null;
let listening = false;

function create() {
  const AC = window.AudioContext || (window as any).webkitAudioContext;
  if (!AC) return null;
  try {
    return new AC();
  } catch {
    return null;
  }
}

function unlock() {
  if (!ctx) ctx = create();
  if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => undefined);
}

/** Call once at app start: waits for the first user gesture to enable audio. */
export function armAudio() {
  if (listening) return;
  listening = true;
  const h = () => unlock();
  ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => window.addEventListener(ev, h, { capture: true, passive: true }));
}

export function audioReady() {
  return !!ctx && ctx.state === 'running';
}

/** Plays a short two-tone ring (about 1.6 s). Returns false if audio is blocked. */
export function ring(volume = 0.18): boolean {
  if (!audioReady() || !ctx) return false;
  const c = ctx;
  const start = c.currentTime + 0.02;
  const master = c.createGain();
  master.gain.value = volume;
  master.connect(c.destination);
  // Two bursts of a classic 440/480 Hz telephone ring, 0.4 s on / 0.2 s off.
  [0, 0.6].forEach((offset) => {
    const g = c.createGain();
    g.gain.setValueAtTime(0, start + offset);
    g.gain.linearRampToValueAtTime(1, start + offset + 0.02);
    g.gain.setValueAtTime(1, start + offset + 0.38);
    g.gain.linearRampToValueAtTime(0, start + offset + 0.42);
    g.connect(master);
    [440, 480].forEach((f) => {
      const o = c.createOscillator();
      o.type = 'sine';
      o.frequency.value = f;
      o.connect(g);
      o.start(start + offset);
      o.stop(start + offset + 0.45);
    });
  });
  setTimeout(() => master.disconnect(), 2000);
  return true;
}

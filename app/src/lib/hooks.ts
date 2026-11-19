import { useEffect, useState, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';

export function useDebounce<T>(value: T, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/** Keeps list filters in the URL so they survive reloads and can be shared. */
export function useUrlState<T extends Record<string, string>>(defaults: T) {
  const [sp, setSp] = useSearchParams();
  const state = { ...defaults } as T;
  Object.keys(defaults).forEach((k) => {
    const v = sp.get(k);
    if (v !== null) (state as any)[k] = v;
  });
  const set = useCallback(
    (patch: Partial<T>) => {
      setSp(
        (prev) => {
          const next = new URLSearchParams(prev);
          Object.entries(patch).forEach(([k, v]) => {
            if (v === undefined || v === null || v === '' || v === (defaults as any)[k]) next.delete(k);
            else next.set(k, String(v));
          });
          if (!('page' in patch)) next.delete('page');
          return next;
        },
        { replace: true },
      );
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [setSp],
  );
  return [state, set] as const;
}

export function useMediaQuery(q: string) {
  const [m, setM] = useState(() => window.matchMedia(q).matches);
  useEffect(() => {
    const mq = window.matchMedia(q);
    const h = () => setM(mq.matches);
    mq.addEventListener('change', h);
    return () => mq.removeEventListener('change', h);
  }, [q]);
  return m;
}

export function useTheme() {
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  const toggle = () => {
    const d = !dark;
    document.documentElement.classList.toggle('dark', d);
    try {
      localStorage.setItem('pcrm-theme', d ? 'dark' : 'light');
    } catch {
      /* storage blocked */
    }
    setDark(d);
  };
  return { dark, toggle };
}

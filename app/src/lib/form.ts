import { useState } from 'react';
import { ApiError } from './api';

/** Tiny form state helper: values, field errors, server-error mapping. */
export function useForm<T extends Record<string, any>>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof T>(k: K, v: T[K]) => {
    setValues((s) => ({ ...s, [k]: v }));
    if (errors[k as string]) setErrors((e) => ({ ...e, [k as string]: '' }));
  };
  const bind = (k: keyof T) => ({
    value: (values[k] ?? '') as string,
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => set(k, e.target.value as any),
    invalid: !!errors[k as string],
  });
  const fromError = (e: unknown) => {
    if (e instanceof ApiError && Object.keys(e.errors).length) setErrors(e.errors);
  };
  return { values, setValues, errors, setErrors, set, bind, fromError, reset: () => (setValues(initial), setErrors({})) };
}

/** Required-field and format checks done before calling the API. */
export function validate(values: Record<string, any>, rules: Record<string, ('required' | 'phone' | 'email' | 'number' | 'positive')[]>, labels: Record<string, string> = {}) {
  const errs: Record<string, string> = {};
  for (const [k, rs] of Object.entries(rules)) {
    const v = values[k];
    const label = labels[k] || k.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
    const empty = v === undefined || v === null || String(v).trim() === '';
    if (rs.includes('required') && empty) {
      errs[k] = `${label} is required.`;
      continue;
    }
    if (empty) continue;
    if (rs.includes('phone')) {
      const d = String(v).replace(/[\s()-]/g, '');
      if (!/^(\+91|91|0)?[6-9]\d{9}$/.test(d) && !/^\+\d{8,15}$/.test(d)) errs[k] = 'Enter a valid mobile number.';
    }
    if (rs.includes('email') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v))) errs[k] = 'Enter a valid email.';
    if ((rs.includes('number') || rs.includes('positive')) && isNaN(Number(v))) errs[k] = `${label} must be a number.`;
    if (rs.includes('positive') && Number(v) < 0) errs[k] = `${label} cannot be negative.`;
  }
  return errs;
}

export const clean = <T extends Record<string, any>>(o: T) => {
  const out: Record<string, any> = {};
  Object.entries(o).forEach(([k, v]) => (out[k] = v === '' ? null : v));
  return out as T;
};

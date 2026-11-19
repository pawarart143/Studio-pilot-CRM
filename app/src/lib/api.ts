declare global {
  interface Window {
    PCRM: { restUrl: string; nonce: string; base: string; loggedIn: boolean; homeUrl?: string; version?: string; siteName?: string; google?: string };
  }
}

export class ApiError extends Error {
  status: number;
  code: string;
  errors: Record<string, string>;
  data: any;
  constructor(status: number, code: string, message: string, data: any) {
    super(message);
    this.status = status;
    this.code = code;
    this.errors = data?.errors || {};
    this.data = data || {};
  }
}

const boot = () => window.PCRM;

function url(path: string, params?: Record<string, any>) {
  const u = new URL(boot().restUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, ''), window.location.origin);
  if (params) {
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '' && v !== false) u.searchParams.set(k, String(v));
    });
  }
  return u.toString();
}

async function request<T>(method: string, path: string, body?: any, params?: Record<string, any>): Promise<T> {
  const headers: Record<string, string> = { 'X-WP-Nonce': boot().nonce };
  let payload: BodyInit | undefined;
  if (body instanceof FormData) payload = body;
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let res: Response;
  try {
    res = await fetch(url(path, params), { method, headers, body: payload, credentials: 'same-origin' });
  } catch {
    throw new ApiError(0, 'network', 'Network error. Check your internet connection and try again.', {});
  }
  const fresh = res.headers.get('X-WP-Nonce');
  if (fresh) boot().nonce = fresh;
  const text = await res.text();
  let json: any = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }
  if (!res.ok) {
    const code = json?.code || 'error';
    if ((res.status === 401 || code === 'rest_cookie_invalid_nonce') && !path.startsWith('auth/')) {
      window.location.href = boot().base + '/login?next=' + encodeURIComponent(window.location.pathname);
    }
    throw new ApiError(res.status, code, json?.message || `Request failed (${res.status})`, json?.data);
  }
  return json as T;
}

export const api = {
  get: <T = any>(path: string, params?: Record<string, any>) => request<T>('GET', path, undefined, params),
  post: <T = any>(path: string, body?: any) => request<T>('POST', path, body ?? {}),
  put: <T = any>(path: string, body?: any) => request<T>('PUT', path, body ?? {}),
  del: <T = any>(path: string) => request<T>('DELETE', path),
  upload: <T = any>(path: string, form: FormData) => request<T>('POST', path, form),
  /** Downloads a binary/CSV endpoint with auth and saves it. */
  async download(path: string, params: Record<string, any>, filename: string) {
    const res = await fetch(url(path, params), { headers: { 'X-WP-Nonce': boot().nonce }, credentials: 'same-origin' });
    if (!res.ok) {
      let msg = 'Download failed.';
      try {
        msg = (await res.json()).message || msg;
      } catch {
        /* binary */
      }
      throw new ApiError(res.status, 'download', msg, {});
    }
    const blob = await res.blob();
    saveBlob(blob, filename);
  },
  /** Opens an authenticated PDF in a new tab. */
  async openPdf(path: string, filename: string, download = false) {
    const res = await fetch(url(path, download ? { download: 1 } : {}), { headers: { 'X-WP-Nonce': boot().nonce }, credentials: 'same-origin' });
    if (!res.ok) throw new ApiError(res.status, 'pdf', 'Could not generate the PDF.', {});
    const blob = await res.blob();
    if (download) return saveBlob(blob, filename);
    const u = URL.createObjectURL(blob);
    const w = window.open(u, '_blank');
    if (!w) saveBlob(blob, filename);
    setTimeout(() => URL.revokeObjectURL(u), 60000);
  },
};

export function saveBlob(blob: Blob, filename: string) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

export function errMsg(e: unknown) {
  return e instanceof Error ? e.message : 'Something went wrong.';
}

export type Paged<T> = { items: T[]; total: number; page: number; per_page: number; [k: string]: any };

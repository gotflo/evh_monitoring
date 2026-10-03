// Client de l'API de la console (meme domaine : /api), session propre a la console.
import { toast } from './toast'

const KEY = 'evh_console_token'

export const consoleToken = {
  get: () => { try { return localStorage.getItem(KEY) } catch { return null } },
  set: (t: string) => { try { localStorage.setItem(KEY, t) } catch { /* stockage indisponible */ } },
  clear: () => { try { localStorage.removeItem(KEY) } catch { /* stockage indisponible */ } },
}

/** Evenement emis quand la session de la console n'est plus valide (expiree, acces retire). */
export const SESSION_LOST = 'evh-console-session-lost'

export class ConsoleError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
  get firstMessage(): string {
    return Object.values(this.errors)[0]?.[0] ?? this.message
  }
}

type Options = { method?: string; body?: unknown; toast?: string | false }

/**
 * Appel de /api/... : les actions (POST/PUT/PATCH/DELETE) affichent un retour
 * (message du serveur ou erreur) ; une session refusee (401) renvoie a la connexion.
 */
export async function capi<T = unknown>(path: string, opts: Options = {}): Promise<T> {
  const method = (opts.method ?? 'GET').toUpperCase()
  const isAction = method !== 'GET' && opts.toast !== false
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = consoleToken.get()
  if (token) headers.Authorization = `Bearer ${token}`
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json'

  let res: Response
  const controller = new AbortController()
  const timer = window.setTimeout(() => controller.abort(), 30000)
  try {
    res = await fetch(`/api${path}`, {
      method, headers, body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined, signal: controller.signal,
    })
  } catch (err) {
    const msg = err instanceof DOMException && err.name === 'AbortError'
      ? 'Le serveur met trop de temps à répondre. Réessayez dans un instant.'
      : 'Connexion impossible. Vérifiez votre réseau puis réessayez.'
    if (isAction) toast.error(msg)
    throw new ConsoleError(0, msg)
  } finally {
    window.clearTimeout(timer)
  }

  const data = res.status === 204 ? {} : await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && token) {
      consoleToken.clear()
      window.dispatchEvent(new Event(SESSION_LOST))
    }
    const technical = !data.message || /^(Server Error|Too Many Attempts\.|Unauthenticated\.|Not Found|This action is unauthorized\.)$/i.test(data.message)
    const friendly = res.status === 429 ? 'Trop de tentatives. Patientez une minute puis réessayez.'
      : res.status >= 500 ? 'Le serveur a rencontré un problème. Réessayez dans un instant.'
        : res.status === 403 ? 'Votre rôle ne permet pas cette action.'
          : res.status === 404 ? 'Élément introuvable.'
            : res.status === 401 ? 'Session de la console expirée. Reconnectez-vous.'
              : 'Une erreur est survenue.'
    const error = new ConsoleError(res.status, technical ? friendly : data.message, data.errors ?? {})
    if (isAction && res.status !== 401) toast.error(error.firstMessage)
    throw error
  }
  if (isAction) {
    const text = typeof opts.toast === 'string' ? opts.toast : data?.message
    if (typeof text === 'string' && text) toast.success(text)
  }
  return data as T
}

/** Chaine de requete sans les valeurs vides. */
export function qs(params: Record<string, string | number | boolean | null | undefined>): string {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== null && v !== undefined && v !== '' && v !== false) p.set(k, String(v))
  }
  const s = p.toString()
  return s ? `?${s}` : ''
}

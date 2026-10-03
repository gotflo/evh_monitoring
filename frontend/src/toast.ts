// Notifications ephemeres (toasts) : retour immediat apres chaque action.
// Utilise automatiquement par le client API (succes / echec des actions),
// et appelable directement : toast.success('...'), toast.error('...').

export type ToastKind = 'success' | 'error' | 'info' | 'warning'
export interface ToastItem {
  id: number
  kind: ToastKind
  title: string
  message?: string
  duration: number
}

type Listener = (items: ToastItem[]) => void
let items: ToastItem[] = []
let nextId = 1
const listeners = new Set<Listener>()
const MAX = 4

function emit() { listeners.forEach((l) => l(items)) }

const TITLES: Record<ToastKind, string> = {
  success: "C'est fait",
  error: 'Action impossible',
  info: 'Information',
  warning: 'Attention',
}

function push(kind: ToastKind, message: string, opts: { title?: string; duration?: number } = {}): number {
  // Meme message deja affiche : on le relance au lieu de l'empiler.
  const same = items.find((t) => t.kind === kind && t.message === message)
  if (same) dismiss(same.id)
  const id = nextId++
  const duration = opts.duration ?? (kind === 'error' ? 6000 : 3800)
  items = [...items, { id, kind, title: opts.title ?? TITLES[kind], message, duration }].slice(-MAX)
  emit()
  return id
}

export function dismiss(id: number) {
  items = items.filter((t) => t.id !== id)
  emit()
}

export function subscribeToasts(l: Listener): () => void {
  listeners.add(l)
  l(items)
  return () => { listeners.delete(l) }
}

export const toast = {
  success: (message: string, opts?: { title?: string; duration?: number }) => push('success', message, opts),
  error: (message: string, opts?: { title?: string; duration?: number }) => push('error', message, opts),
  info: (message: string, opts?: { title?: string; duration?: number }) => push('info', message, opts),
  warning: (message: string, opts?: { title?: string; duration?: number }) => push('warning', message, opts),
}

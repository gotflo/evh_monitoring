import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { dismiss, subscribeToasts, type ToastItem } from '../toast'

const ICONS: Record<ToastItem['kind'], string> = {
  success: 'M5 12.5l4.5 4.5L19 7.5',
  error: 'M7 7l10 10M17 7L7 17',
  info: 'M12 8h.01M11 12h1v5h1',
  warning: 'M12 7v6M12 17h.01',
}

/** Pile de toasts : en haut a droite (ordinateur), en haut au centre (telephone). */
export function Toaster() {
  const [items, setItems] = useState<ToastItem[]>([])
  useEffect(() => subscribeToasts(setItems), [])

  return (
    <div className="toaster" aria-live="polite" aria-relevant="additions">
      {items.map((t) => <Toast key={t.id} t={t} />)}
    </div>
  )
}

function Toast({ t }: { t: ToastItem }) {
  const [leaving, setLeaving] = useState(false)
  const [paused, setPaused] = useState(false)
  const [dx, setDx] = useState(0)
  const remaining = useRef(t.duration)
  const startedAt = useRef(0)
  const drag = useRef<{ x: number; id: number } | null>(null)

  function close() {
    setLeaving(true)
    window.setTimeout(() => dismiss(t.id), 260)
  }

  // Minuterie avec pause au survol / au toucher.
  useEffect(() => {
    if (paused || leaving) return
    startedAt.current = Date.now()
    const timer = window.setTimeout(close, remaining.current)
    return () => {
      window.clearTimeout(timer)
      remaining.current -= Date.now() - startedAt.current
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paused, leaving])

  // Glisser vers le cote pour fermer (mobile).
  function onDown(e: ReactPointerEvent) {
    drag.current = { x: e.clientX, id: e.pointerId }
    setPaused(true)
  }
  function onMove(e: ReactPointerEvent) {
    if (drag.current && drag.current.id === e.pointerId) setDx(e.clientX - drag.current.x)
  }
  function onUp() {
    if (!drag.current) return
    drag.current = null
    if (Math.abs(dx) > 90) { close(); return }
    setDx(0); setPaused(false)
  }

  return (
    <div
      className={`toast toast-${t.kind} ${leaving ? 'leaving' : ''}`}
      role={t.kind === 'error' ? 'alert' : 'status'}
      style={dx ? { transform: `translateX(${dx}px)`, opacity: Math.max(0.2, 1 - Math.abs(dx) / 220) } : undefined}
      onMouseEnter={() => setPaused(true)} onMouseLeave={() => { if (!drag.current) setPaused(false) }}
      onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp}
    >
      <span className="toast-icon" aria-hidden>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round">
          <path className="toast-icon-path" d={ICONS[t.kind]} />
        </svg>
      </span>
      <div className="toast-body">
        <strong>{t.title}</strong>
        {t.message && <span>{t.message}</span>}
      </div>
      <button className="toast-close" onClick={close} onPointerDown={(e) => e.stopPropagation()} aria-label="Fermer">x</button>
      <span className="toast-progress" style={{ animationDuration: `${t.duration}ms`, animationPlayState: paused || leaving ? 'paused' : 'running' }} />
    </div>
  )
}

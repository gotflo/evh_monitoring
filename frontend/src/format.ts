// Constantes, formats et chargement de donnees de la console (sans composant).
import { useCallback, useEffect, useState } from 'react'
import { capi, ConsoleError } from './api'

// ------------------------------------------------------------------ Icones (trait, 24 px)

export const CX_ICONS = {
  dashboard: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  pulse: 'M22 12h-4l-3 9L9 3l-3 9H2',
  users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  logs: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8M8 9h2',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
  report: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
  tool: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z',
  key: 'M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
  audit: 'M9 11l3 3 8-8M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9',
  check: 'M20 6 9 17l-5-5',
  alert: 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  x: 'M18 6 6 18M6 6l12 12',
  minus: 'M5 12h14',
  info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
  menu: 'M4 6h16M4 12h16M4 18h16',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
  refresh: 'M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
  back: 'M19 12H5M12 19l-7-7 7-7',
  server: 'M2 2h20v8H2zM2 14h20v8H2zM6 6h.01M6 18h.01',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
  printer: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
}
export type CxIcon = keyof typeof CX_ICONS

// ------------------------------------------------------------------ Formats

const NUM = new Intl.NumberFormat('fr-CA')

export const fmtNum = (n: number | null | undefined) => (n === null || n === undefined ? '-' : NUM.format(n))

export function fmtMs(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) return '-'
  return ms >= 1000 ? `${(ms / 1000).toLocaleString('fr-CA', { maximumFractionDigits: 1 })} s` : `${ms} ms`
}

export function fmtDate(iso: string | null | undefined, withTime = true): string {
  if (!iso) return '-'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString('fr-CA', withTime
    ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    : { day: 'numeric', month: 'short', year: 'numeric' })
}

export function fmtRel(iso: string | null | undefined): string {
  if (!iso) return 'jamais'
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  if (Number.isNaN(s)) return '-'
  if (s < 45) return "à l'instant"
  if (s < 3600) return `il y a ${Math.round(s / 60)} min`
  if (s < 86400) return `il y a ${Math.round(s / 3600)} h`
  if (s < 86400 * 45) return `il y a ${Math.round(s / 86400)} j`
  return fmtDate(iso, false)
}

export function fmtDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined) return '-'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (d) return `${d} j ${h} h`
  if (h) return `${h} h ${m} min`
  return `${m} min`
}

export function fmtBytes(n: number): string {
  if (n < 1024) return `${n} o`
  if (n < 1048576) return `${(n / 1024).toFixed(0)} Ko`
  return `${(n / 1048576).toFixed(1)} Mo`
}

/** Variation en % (null si non calculable). */
export function trend(now: number | null | undefined, before: number | null | undefined): number | null {
  if (now === null || now === undefined || before === null || before === undefined || before === 0) return null
  return Math.round(((now - before) / before) * 1000) / 10
}

// ------------------------------------------------------------------ Chargement de donnees

/**
 * Lecture d'un ecran. Avec refreshMs, les donnees se mettent a jour toutes seules (temps reel),
 * seulement quand l'onglet est visible, sans effacer l'affichage pendant le chargement.
 */
export function useData<T>(path: string | null, refreshMs = 0) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(!!path)
  const [updatedAt, setUpdatedAt] = useState<number | null>(null)
  const [tick, setTick] = useState(0)
  const reload = useCallback(() => setTick((t) => t + 1), [])

  useEffect(() => {
    if (!path) { setLoading(false); return }
    let alive = true
    setLoading(true)
    capi<T>(path)
      .then((d) => { if (alive) { setData(d); setError(null); setUpdatedAt(Date.now()) } })
      .catch((e) => { if (alive) setError(e instanceof ConsoleError ? e.firstMessage : 'Chargement impossible.') })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [path, tick])

  useEffect(() => {
    if (!refreshMs || !path) return
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible') reload() }, refreshMs)
    const onVisible = () => { if (document.visibilityState === 'visible') reload() }
    document.addEventListener('visibilitychange', onVisible)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', onVisible) }
  }, [refreshMs, path, reload])

  return { data, error, loading, reload, setData, updatedAt }
}

/** Couleurs des graphiques (palette verifiee pour le daltonisme). */
export const VIZ = { s1: '#2a78d6', s2: '#eb6834', s3: '#1baf7a', grid: '#e6e9ea', axis: '#8b9793', text: '#17211f', muted: '#586863', surface: '#ffffff' }

export type Tone = 'ok' | 'warn' | 'crit' | 'muted' | 'info'
export const LEVEL_TONE: Record<string, Tone> = { debug: 'muted', info: 'info', notice: 'info', warning: 'warn', error: 'crit', critical: 'crit', alert: 'crit', emergency: 'crit' }
export const LEVEL_LABEL: Record<string, string> = { debug: 'Débogage', info: 'Info', notice: 'Notice', warning: 'Avertissement', error: 'Erreur', critical: 'Critique', alert: 'Alerte', emergency: 'Urgence' }
export const OUTCOME: Record<string, [Tone, string]> = { success: ['ok', 'Réussi'], failure: ['crit', 'Échec'], denied: ['warn', 'Refusé'] }


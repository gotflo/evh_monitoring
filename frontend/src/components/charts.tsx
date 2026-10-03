import { useEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { VIZ } from '../format'

/** Largeur reelle du conteneur : le graphique est dessine a sa taille (texte lisible sur telephone). */
function useWidth(ref: RefObject<HTMLDivElement | null>, fallback = 640): number {
  const [w, setW] = useState(fallback)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const update = () => setW(Math.max(260, Math.round(el.clientWidth)))
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [ref])
  return w
}

/**
 * Graphiques SVG legers (aucune dependance) pour les rapports.
 * Regles : traits de 2 px, barres <= 24 px arrondies en bout, grille discrete, legende des 2 series,
 * etiquettes selectives, info-bulle au survol / au toucher. Les memes fonctions produisent le SVG
 * (meme jeu de composants que la plateforme).
 */


export interface Series { key: string; label: string; color: string; values: (number | null)[] }

const PAD = { top: 14, right: 28, bottom: 26, left: 38 }

function niceMax(max: number, fixed?: number): number {
  if (fixed) return fixed
  if (max <= 0) return 4
  const pow = 10 ** Math.floor(Math.log10(max))
  const n = max / pow
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10
  return step * pow
}

function lineGeometry(labels: string[], series: Series[], w: number, h: number, yMax?: number) {
  const max = niceMax(Math.max(0, ...series.flatMap((s) => s.values.filter((v): v is number => v !== null))), yMax)
  const iw = w - PAD.left - PAD.right
  const ih = h - PAD.top - PAD.bottom
  const x = (i: number) => PAD.left + (labels.length <= 1 ? iw / 2 : (i / (labels.length - 1)) * iw)
  const y = (v: number) => PAD.top + ih - (v / max) * ih
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max)
  const paths = series.map((s) => {
    let d = ''
    let pen = false
    s.values.forEach((v, i) => {
      if (v === null) { pen = false; return }
      d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)} `
      pen = true
    })
    return d.trim()
  })
  return { max, x, y, ticks, paths, iw, ih }
}

const fmt = (v: number | null, unit: string) => (v === null ? '-' : `${Number.isInteger(v) ? v : v.toFixed(1)}${unit}`)

// ---------------------------------------------------------------- Courbes

export function LineChart({ labels, series, unit = '%', yMax, height = 220, ariaLabel }: {
  labels: string[]; series: Series[]; unit?: string; yMax?: number; height?: number; ariaLabel: string
}) {
  const [hover, setHover] = useState<number | null>(null)
  const wrap = useRef<HTMLDivElement>(null)
  const w = useWidth(wrap)
  const g = useMemo(() => lineGeometry(labels, series, w, height, yMax), [labels, series, w, height, yMax])
  // Etiquettes de mois : une sur deux si la place manque.
  const every = w / Math.max(1, labels.length) < 44 ? 2 : 1

  function onMove(clientX: number) {
    const rect = wrap.current?.getBoundingClientRect()
    if (!rect || labels.length === 0) return
    const px = ((clientX - rect.left) / rect.width) * w
    const i = Math.round(((px - PAD.left) / g.iw) * (labels.length - 1))
    setHover(Math.max(0, Math.min(labels.length - 1, i)))
  }

  const lastIndex = labels.length - 1
  return (
    <div className="chart" ref={wrap}>
      {series.length > 1 && (
        <div className="chart-legend">
          {series.map((s) => <span key={s.key}><i style={{ background: s.color }} />{s.label}</span>)}
        </div>
      )}
      <svg width={w} height={height} viewBox={`0 0 ${w} ${height}`} role="img" aria-label={ariaLabel}
        onMouseMove={(e) => onMove(e.clientX)} onMouseLeave={() => setHover(null)}
        onTouchStart={(e) => onMove(e.touches[0].clientX)} onTouchMove={(e) => onMove(e.touches[0].clientX)}>
        {g.ticks.map((t) => (
          <g key={t}>
            <line x1={PAD.left} x2={w - PAD.right} y1={g.y(t)} y2={g.y(t)} stroke={VIZ.grid} strokeWidth="1" />
            <text x={PAD.left - 6} y={g.y(t) + 4} textAnchor="end" className="chart-tick">{Math.round(t)}{unit === '%' ? '%' : ''}</text>
          </g>
        ))}
        {labels.map((l, i) => (i % every === 0 || i === labels.length - 1) && (
          <text key={l + i} x={g.x(i)} y={height - 6} textAnchor="middle" className="chart-tick">{l}</text>
        ))}
        {hover !== null && <line x1={g.x(hover)} x2={g.x(hover)} y1={PAD.top} y2={height - PAD.bottom} stroke={VIZ.axis} strokeWidth="1" />}
        {series.map((s, si) => (
          <g key={s.key}>
            <path d={g.paths[si]} fill="none" stroke={s.color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
            {s.values.map((v, i) => v !== null && (i === lastIndex || i === hover || s.values.filter((x) => x !== null).length === 1) && (
              <circle key={i} cx={g.x(i)} cy={g.y(v)} r="4.5" fill={s.color} stroke={VIZ.surface} strokeWidth="2" />
            ))}
          </g>
        ))}
      </svg>
      {series.every((s) => s.values.every((v) => v === null)) && <div className="chart-empty">Pas encore de données sur cette période</div>}
      {hover !== null && (
        <div className="chart-tip" style={{ left: `${Math.min(85, Math.max(15, (g.x(hover) / w) * 100))}%` }}>
          <strong>{labels[hover]}</strong>
          {series.map((s) => <span key={s.key}><i style={{ background: s.color }} />{s.label} : {fmt(s.values[hover], unit)}</span>)}
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------- Colonnes (une serie)

export function ColumnChart({ labels, values, color = VIZ.s1, unit = '', height = 190, ariaLabel }: {
  labels: string[]; values: number[]; color?: string; unit?: string; height?: number; ariaLabel: string
}) {
  const [hover, setHover] = useState<number | null>(null)
  const wrap = useRef<HTMLDivElement>(null)
  const w = useWidth(wrap)
  const max = niceMax(Math.max(0, ...values))
  const every = w / Math.max(1, values.length) < 44 ? 2 : 1
  const iw = w - PAD.left - PAD.right
  const ih = height - PAD.top - PAD.bottom
  const band = iw / Math.max(1, values.length)
  const bw = Math.min(24, band * 0.6)
  const y = (v: number) => PAD.top + ih - (v / max) * ih
  const peak = values.indexOf(Math.max(...values))

  return (
    <div className="chart" ref={wrap}>
      <svg width={w} height={height} viewBox={`0 0 ${w} ${height}`} role="img" aria-label={ariaLabel} onMouseLeave={() => setHover(null)}>
        {[0, 0.5, 1].map((t) => (
          <g key={t}>
            <line x1={PAD.left} x2={w - PAD.right} y1={y(t * max)} y2={y(t * max)} stroke={VIZ.grid} strokeWidth="1" />
            <text x={PAD.left - 6} y={y(t * max) + 4} textAnchor="end" className="chart-tick">{Math.round(t * max)}</text>
          </g>
        ))}
        {values.map((v, i) => {
          const cx = PAD.left + band * i + band / 2
          const top = y(v)
          const hgt = Math.max(0, PAD.top + ih - top)
          const r = Math.min(4, hgt)
          return (
            <g key={i} onMouseEnter={() => setHover(i)} onTouchStart={() => setHover(i)}>
              <rect x={cx - band / 2} y={PAD.top} width={band} height={ih} fill="transparent" />
              {hgt > 0 && (
                <path d={`M${cx - bw / 2},${top + hgt} V${top + r} Q${cx - bw / 2},${top} ${cx - bw / 2 + r},${top} H${cx + bw / 2 - r} Q${cx + bw / 2},${top} ${cx + bw / 2},${top + r} V${top + hgt} Z`}
                  fill={color} opacity={hover === null || hover === i ? 1 : 0.55} />
              )}
              {(i === peak || i === values.length - 1 || hover === i) && v > 0 && (
                <text x={cx} y={top - 5} textAnchor="middle" className="chart-value">{v}{unit}</text>
              )}
              {(i % every === 0 || i === values.length - 1) && <text x={cx} y={height - 6} textAnchor="middle" className="chart-tick">{labels[i]}</text>}
            </g>
          )
        })}
      </svg>
    </div>
  )
}

// ---------------------------------------------------------------- Barres horizontales (comparaison des tribus)

export function HBarChart({ rows, unit = '%', max = 100, color = VIZ.s1, ariaLabel }: {
  rows: { label: string; value: number | null; previous?: number | null; note?: string }[]; unit?: string; max?: number; color?: string; ariaLabel: string
}) {
  return (
    <div className="hbar" role="img" aria-label={ariaLabel}>
      {rows.map((r) => (
        <div key={r.label} className="hbar-row" title={r.previous != null ? `Mois précédent : ${fmt(r.previous, unit)}` : undefined}>
          <span className="hbar-label">{r.label}</span>
          <div className="hbar-track">
            {r.value !== null && <div className="hbar-fill" style={{ width: `${Math.max(1, (r.value / max) * 100)}%`, background: color }} />}
            {r.previous != null && <span className="hbar-prev" style={{ left: `${(r.previous / max) * 100}%` }} aria-hidden />}
          </div>
          <span className="hbar-value">{fmt(r.value, unit)}{r.note && <small> {r.note}</small>}</span>
        </div>
      ))}
    </div>
  )
}

/** Jauge actif / inactif (proportion d'un tout). */
export function Meter({ value, total, label }: { value: number; total: number; label: string }) {
  const pct = total ? Math.round((value / total) * 100) : 0
  return (
    <div className="meter" role="img" aria-label={`${label} : ${pct} %`}>
      <div className="meter-track"><div className="meter-fill" style={{ width: `${pct}%` }} /></div>
      <span className="meter-text">{pct} % {label}</span>
    </div>
  )
}

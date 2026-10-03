import { useEffect, useRef, useState } from 'react'
import { COUNTRIES, type Country } from '../data/countries'
// Drapeaux : feuille chargee seulement avec ce selecteur (ecran de connexion), images a la demande.
import 'flag-icons/css/flag-icons.min.css'

/**
 * Selecteur de pays sur mesure : affiche de vraies images de drapeaux
 * (les emoji de drapeaux ne s'affichent pas sous Windows).
 */
export function CountrySelect({ value, onChange }: { value: Country; onChange: (c: Country) => void }) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const q = query.trim().toLowerCase()
  const filtered = q
    ? COUNTRIES.filter((c) => c.name.toLowerCase().includes(q) || c.dial.includes(q))
    : COUNTRIES

  function pick(c: Country) { onChange(c); setOpen(false); setQuery('') }

  return (
    <div className="country-select" ref={ref}>
      <button
        type="button" className="country-trigger"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="listbox" aria-expanded={open} aria-label="Choisir le pays"
      >
        <span className={`fi fi-${value.iso.toLowerCase()}`} />
        <span className="country-dial">{value.dial}</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="chev"><path d="M6 9l6 6 6-6" /></svg>
      </button>

      {open && (
        <div className="country-menu" role="listbox">
          <input aria-label="Rechercher un pays"
            className="input country-search" placeholder="Rechercher un pays..."
            value={query} onChange={(e) => setQuery(e.target.value)} autoFocus
          />
          <div className="country-list">
            {filtered.map((c) => (
              <button
                type="button" key={c.iso} role="option" aria-selected={c.iso === value.iso}
                className={`country-option ${c.iso === value.iso ? 'active' : ''}`}
                onClick={() => pick(c)}
              >
                <span className={`fi fi-${c.iso.toLowerCase()}`} />
                <span className="country-name">{c.name}</span>
                <span className="country-dial">{c.dial}</span>
              </button>
            ))}
            {filtered.length === 0 && <p className="country-empty">Aucun pays trouve</p>}
          </div>
        </div>
      )}
    </div>
  )
}

export interface Country {
  iso: string
  name: string
  dial: string
}

// Canada en tete (valeur par defaut). Liste orientee vers les pays
// pertinents pour l'eglise (francophonie, Amerique du Nord, Caraibes...).
export const COUNTRIES: Country[] = [
  { iso: 'CA', name: 'Canada', dial: '+1' },
  { iso: 'US', name: 'États-Unis', dial: '+1' },
  { iso: 'FR', name: 'France', dial: '+33' },
  { iso: 'BE', name: 'Belgique', dial: '+32' },
  { iso: 'CH', name: 'Suisse', dial: '+41' },
  { iso: 'HT', name: 'Haïti', dial: '+509' },
  { iso: 'CD', name: 'RD Congo', dial: '+243' },
  { iso: 'CG', name: 'Congo', dial: '+242' },
  { iso: 'CI', name: "Côte d'Ivoire", dial: '+225' },
  { iso: 'CM', name: 'Cameroun', dial: '+237' },
  { iso: 'SN', name: 'Sénégal', dial: '+221' },
  { iso: 'BJ', name: 'Bénin', dial: '+229' },
  { iso: 'TG', name: 'Togo', dial: '+228' },
  { iso: 'BF', name: 'Burkina Faso', dial: '+226' },
  { iso: 'ML', name: 'Mali', dial: '+223' },
  { iso: 'GN', name: 'Guinée', dial: '+224' },
  { iso: 'GA', name: 'Gabon', dial: '+241' },
  { iso: 'NE', name: 'Niger', dial: '+227' },
  { iso: 'TD', name: 'Tchad', dial: '+235' },
  { iso: 'RW', name: 'Rwanda', dial: '+250' },
  { iso: 'BI', name: 'Burundi', dial: '+257' },
  { iso: 'MG', name: 'Madagascar', dial: '+261' },
  { iso: 'MA', name: 'Maroc', dial: '+212' },
  { iso: 'DZ', name: 'Algérie', dial: '+213' },
  { iso: 'TN', name: 'Tunisie', dial: '+216' },
  { iso: 'NG', name: 'Nigéria', dial: '+234' },
  { iso: 'GH', name: 'Ghana', dial: '+233' },
  { iso: 'GB', name: 'Royaume-Uni', dial: '+44' },
]

export const DEFAULT_COUNTRY = COUNTRIES[0] // Canada

/**
 * Construit un numero E.164 a partir d'un pays et d'une saisie nationale.
 * Retire un eventuel 0 de tete pour les pays hors Amerique du Nord (prefixe interurbain).
 */
export function toE164(country: Country, national: string): string {
  let digits = national.replace(/\D+/g, '')
  if (country.dial !== '+1' && digits.startsWith('0')) {
    digits = digits.replace(/^0+/, '')
  }
  return `${country.dial}${digits}`
}

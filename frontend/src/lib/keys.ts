// Canonical key lists shared by the musician view and (eventually) the
// playlist builder. The order is the cycle of fifths starting from C — that
// matches what most musicians scan for, and it keeps sharp/flat pairs out
// of strange visual neighbours.

export const MAJOR_KEYS = [
  'C', 'G', 'D', 'A', 'E', 'B', 'F#', 'Db', 'Ab', 'Eb', 'Bb', 'F',
] as const

export const MINOR_KEYS = [
  'Am', 'Em', 'Bm', 'F#m', 'C#m', 'G#m', 'D#m', 'Bbm', 'Fm', 'Cm', 'Gm', 'Dm',
] as const

export type MajorKey = (typeof MAJOR_KEYS)[number]
export type MinorKey = (typeof MINOR_KEYS)[number]
export type MusicalKey = MajorKey | MinorKey

/** Combined list, majors first then minors — for select dropdowns. */
export const ALL_KEYS: readonly string[] = [...MAJOR_KEYS, ...MINOR_KEYS]

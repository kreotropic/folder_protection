// Shared lock icon markup — used by both the Files-list badge
// (files-list-lock-badge.js) and the admin settings UI (AdminApp.vue) so the
// two surfaces show the same icon instead of one being an emoji and the
// other an SVG.

import { mdiLock } from '@mdi/js'

export const LOCK_SVG = `<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="${mdiLock}" /></svg>`

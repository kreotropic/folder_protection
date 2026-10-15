// Renders the "protected folder" lock badge the same way core renders the
// favorite star: via the public @nextcloud/files plugin API, driven by DAV
// properties fetched in the very same PROPFIND the file list already issues.
//
// This replaces the old MutationObserver + separate REST fetch approach for
// the badge itself — that approach could only mark a row *after* Vue had
// already painted it once, which is why the lock used to visibly pop in a
// beat after the row appeared. registerFileAction()'s renderInline() is
// called by Vue as part of rendering the row, using data (`nc:is-protected`,
// `nc:is-deletable`) that's already in the listing response, so there is no
// separate round trip and no "flash of no lock" to fix.
//
// The MutationObserver-based marking in folder-protection-ui.js is kept —
// unrelated to this file — it drives hiding "copy"/"delete" from the context
// menu, which has no declarative extension point in the public API.

import { registerDavProperty } from '@nextcloud/files/dav'
import { registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { LOCK_SVG } from './lock-icon.js'

registerDavProperty('nc:is-protected')
registerDavProperty('nc:is-deletable')

/**
 * The webdav client auto-casts these text-node values ("true"/"false") to
 * real booleans while parsing the PROPFIND response, so both forms are
 * checked defensively rather than assuming which one a given version emits.
 */
function isTrue(value) {
	return value === true || value === 'true'
}

function isFalse(value) {
	return value === false || value === 'false'
}

/**
 * A folder is shown as locked when it is itself protected, or when it
 * contains a protected descendant (mirrors StorageWrapper::getPermissions()'s
 * blocksDelete logic, which is exactly what nc:is-deletable already encodes).
 */
function isLocked(node) {
	const attrs = node?.attributes
	if (!attrs) return false
	return isTrue(attrs['is-protected']) || isFalse(attrs['is-deletable'])
}

function badgeLabel(node) {
	return isTrue(node.attributes?.['is-protected'])
		? t('folder_protection', 'Protected')
		: t('folder_protection', 'Contains protected sub-folders')
}

registerFileAction({
	id: 'folder-protection-lock-badge',
	displayName: () => '',
	iconSvgInline: () => LOCK_SVG,
	enabled({ nodes }) {
		return nodes.length === 1 && nodes[0].type === 'folder' && isLocked(nodes[0])
	},
	async exec() {
		return null
	},
	async renderInline({ nodes }) {
		const node = nodes[0]
		if (!node || !isLocked(node)) return null

		return createBadge(badgeLabel(node))
	},
	order: -100,
})

function createBadge(label) {
	const badge = document.createElement('span')
	badge.className = 'fp-lock-badge'
	badge.setAttribute('aria-label', label)
	badge.title = label
	badge.innerHTML = LOCK_SVG
	return badge
}

// Grid view. The Files app renders no inline or renderInline actions there
// (FileEntryActions: `enabledRenderActions() { return this.gridMode ? [] : ... }`),
// so the badge above never appears on a grid card, and with it the context-menu
// hiding in folder-protection-ui.js, which keys off the rendered badge, stops
// working too. No public API reaches grid cards (core's favorite star there is
// an internal overlay), so read the card's Node from its Vue component: the
// FileEntryGrid `source` prop, i.e. the same PROPFIND data renderInline gets.
// If a future Files app stops exposing it, grid cards simply get no badge,
// the list view is unaffected and the server still enforces protection.
// Cards are virtualised and recycled while scrolling, so every pass
// re-evaluates each one instead of marking it once.
const GRID_BADGE_CLASS = 'fp-lock-badge--grid'

function syncGridBadges() {
	for (const row of document.querySelectorAll('.files-list--grid .files-list__row')) {
		const node = row.__vue__?.source
		const icon = row.querySelector('.files-list__row-icon')
		let badge = row.querySelector('.' + GRID_BADGE_CLASS)

		if (!icon || node?.type !== 'folder' || !isLocked(node)) {
			badge?.remove()
			continue
		}

		const label = badgeLabel(node)
		if (!badge) {
			badge = createBadge(label)
			badge.classList.add(GRID_BADGE_CLASS)
			icon.appendChild(badge)
		} else if (badge.title !== label) {
			badge.title = label
			badge.setAttribute('aria-label', label)
		}
	}

	// Switching back to the list view must not leave grid overlays behind.
	for (const badge of document.querySelectorAll('.' + GRID_BADGE_CLASS)) {
		if (!badge.closest('.files-list--grid')) {
			badge.remove()
		}
	}
}

function watchGridView() {
	let scheduled = false
	const schedule = () => {
		if (scheduled) return
		scheduled = true
		window.requestAnimationFrame(() => {
			scheduled = false
			syncGridBadges()
		})
	}

	new MutationObserver((mutations) => {
		// Ignore our own badge insertions/removals, or every pass would trigger the next.
		const relevant = mutations.some((m) => m.type === 'attributes'
			|| [...m.addedNodes, ...m.removedNodes].some((n) => !(n instanceof Element) || !n.classList.contains(GRID_BADGE_CLASS)))
		if (relevant) schedule()
	}).observe(document.body, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: ['class', 'data-cy-files-list-row-name'],
	})
	schedule()
}

function injectBadgeStyles() {
	if (document.getElementById('folder-protection-badge-styles')) return

	const style = document.createElement('style')
	style.id = 'folder-protection-badge-styles'
	style.textContent = `
		.fp-lock-badge {
			display: flex;
			align-items: center;
			justify-content: center;
			width: 16px;
			height: 16px;
			color: var(--color-text-maxcontrast);
			flex-shrink: 0;
		}
		.fp-lock-badge svg {
			width: 16px;
			height: 16px;
		}
		/* Grid view: overlaid on the card's folder icon, bottom-right. */
		.fp-lock-badge.fp-lock-badge--grid {
			position: absolute;
			right: 8px;
			bottom: 8px;
			width: 28px;
			height: 28px;
			border-radius: 50%;
			background-color: var(--color-main-background);
			color: var(--color-main-text);
			box-shadow: 0 0 4px var(--color-box-shadow);
			pointer-events: auto;
		}
		/* Admin "Show lock icons" preference — toggled from AdminApp.vue via
		   FolderProtectionUI.setLocksVisible(). Pure CSS so it takes effect
		   instantly on every already-rendered row, with no re-render needed. */
		body.fp-locks-hidden .fp-lock-badge {
			display: none !important;
		}
	`
	document.head.appendChild(style)
}

injectBadgeStyles()

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', watchGridView)
} else {
	watchGridView()
}

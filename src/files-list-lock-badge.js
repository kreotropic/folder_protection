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

		const label = badgeLabel(node)
		const badge = document.createElement('span')
		badge.className = 'fp-lock-badge'
		badge.setAttribute('aria-label', label)
		badge.title = label
		badge.innerHTML = LOCK_SVG
		return badge
	},
	order: -100,
})

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

<template>
	<div class="folder-protection-widget">
		<!-- Loading -->
		<div v-if="loading" class="folder-protection-widget__loading">
			<span class="icon-loading-small" />
		</div>

		<!-- Lista de pastas -->
		<ul v-else-if="folders.length" class="folder-protection-widget__list">
			<li
				v-for="folder in folders"
				:key="folder.id"
				class="folder-protection-widget__item">
				<div class="folder-protection-widget__icon">
					<span class="folder-protection-widget__lock">🔒</span>
				</div>
				<div class="folder-protection-widget__details">
					<span class="folder-protection-widget__name" :title="folder.path">
						{{ folder.display_name }}
					</span>
					<span class="folder-protection-widget__meta">
						<span class="folder-protection-widget__size">{{ formatSize(folder.size) }}</span>
						<span v-if="folder.reason" class="folder-protection-widget__reason" :title="folder.reason">
							· {{ folder.reason }}
						</span>
					</span>
					<span class="folder-protection-widget__creator">
						{{ t('folder_protection', 'Protected by {user}', { user: folder.created_by || '—' }) }}
					</span>
				</div>
			</li>
		</ul>

		<!-- Sem pastas protegidas -->
		<div v-else class="folder-protection-widget__empty">
			<span class="icon-folder" />
			<p>{{ t('folder_protection', 'No protected folders') }}</p>
		</div>

		<!-- Rodapé com link para admin -->
		<div class="folder-protection-widget__footer">
			<a :href="adminUrl" class="folder-protection-widget__footer-link">
				{{ t('folder_protection', 'Manage protections') }}
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ProtectedFoldersWidget',

	data() {
		return {
			folders: [],
			loading: true,
			error: null,
		}
	},

	computed: {
		adminUrl() {
			return generateUrl('/settings/admin/folder_protection')
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		async loadData() {
			this.loading = true
			const url = generateUrl('/apps/folder_protection/api/widget/data')
			console.log('[FolderProtection] widget mounted, fetching:', url)
			try {
				const response = await axios.get(url)
				console.log('[FolderProtection] widget response:', response.data)
				this.folders = response.data.folders || []
			} catch (e) {
				console.error('[FolderProtection] widget error:', e.message, e.response?.status, e.response?.data)
				this.error = e.message
			} finally {
				this.loading = false
			}
		},

		formatSize(bytes) {
			if (bytes === undefined || bytes === null || bytes < 0) return '—'
			if (bytes === 0) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			let i = 0
			let value = bytes
			while (value >= 1024 && i < units.length - 1) {
				value /= 1024
				i++
			}
			return value.toFixed(i === 0 ? 0 : 1) + ' ' + units[i]
		},
	},
}
</script>

<style scoped>
.folder-protection-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.folder-protection-widget__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 24px;
}

.folder-protection-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	flex: 1;
	overflow-y: auto;
}

.folder-protection-widget__item {
	display: flex;
	align-items: flex-start;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	gap: 10px;
}

.folder-protection-widget__item:last-child {
	border-bottom: none;
}

.folder-protection-widget__icon {
	flex-shrink: 0;
	font-size: 20px;
	margin-top: 2px;
}

.folder-protection-widget__details {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
}

.folder-protection-widget__name {
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--color-main-text);
}

.folder-protection-widget__meta {
	font-size: 12px;
	color: var(--color-text-lighter);
	display: flex;
	gap: 4px;
	align-items: center;
}

.folder-protection-widget__size {
	font-weight: 500;
	color: var(--color-primary);
}

.folder-protection-widget__reason {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 160px;
}

.folder-protection-widget__creator {
	font-size: 11px;
	color: var(--color-text-lighter);
}

.folder-protection-widget__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px 16px;
	color: var(--color-text-lighter);
	gap: 8px;
}

.folder-protection-widget__empty span {
	font-size: 32px;
	opacity: 0.5;
}

.folder-protection-widget__footer {
	border-top: 1px solid var(--color-border);
	padding: 8px 16px;
	text-align: center;
}

.folder-protection-widget__footer-link {
	font-size: 12px;
	color: var(--color-primary);
	text-decoration: none;
}

.folder-protection-widget__footer-link:hover {
	text-decoration: underline;
}
</style>

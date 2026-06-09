<template>
    <div class="fp-tree-picker" :class="{ 'fp-full-height': fullHeight }">
        <!-- Root loading -->
        <div v-if="loadingRoot" class="fp-loading">
            <span class="icon-loading-small"></span>
            {{ t('folder_protection', 'Loading...') }}
        </div>
        <div v-else-if="error" class="fp-error">{{ error }}</div>

        <!-- Tree -->
        <div v-else class="fp-tree-scroll">
            <div v-if="flatTree.length === 0" class="fp-empty">
                {{ t('folder_protection', 'No folders found.') }}
            </div>
            <div
                v-for="node in flatTree"
                :key="node.path"
                class="fp-node"
                :style="{ paddingLeft: (node.depth * 20 + 8) + 'px' }">

                <!-- Expand arrow -->
                <button
                    v-if="node.hasChildren"
                    class="fp-arrow-btn"
                    :class="{ 'is-expanded': node.expanded, 'is-loading': node.loadingChildren }"
                    @click="toggle(node)"
                    :title="node.expanded ? t('folder_protection', 'Collapse') : t('folder_protection', 'Expand')">
                    <span v-if="node.loadingChildren" class="icon-loading-small fp-spin"></span>
                </button>
                <span v-else class="fp-arrow-placeholder"></span>

                <!-- Checkbox -->
                <input
                    type="checkbox"
                    class="fp-check"
                    :checked="isSelected(node.path)"
                    :disabled="node.isProtected"
                    :title="node.isProtected ? t('folder_protection', 'Already protected') : ''"
                    @change="toggleSelect(node.path)" />

                <!-- Folder icon + name -->
                <span class="fp-folder-icon">📁</span>
                <span class="fp-node-name">{{ node.name }}</span>
                <span v-if="node.isProtected" class="fp-lock" :title="t('folder_protection', 'Already protected')">🔒</span>
            </div>
        </div>

        <!-- Footer: reason + actions -->
        <div class="fp-footer">
            <input
                v-model="reason"
                type="text"
                class="fp-reason-input"
                :placeholder="t('folder_protection', 'Reason (optional)')" />
            <div class="fp-footer-actions">
                <button class="button" @click="$emit('cancel')">
                    {{ t('folder_protection', 'Cancel') }}
                </button>
                <button
                    class="button primary"
                    :disabled="selectedPaths.length === 0 || submitting"
                    @click="confirm">
                    {{ submitting
                        ? t('folder_protection', 'Adding...')
                        : t('folder_protection', 'Protect ({n})', { n: selectedPaths.length }) }}
                </button>
            </div>
        </div>
    </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
    name: 'FolderPicker',
    emits: ['done', 'cancel'],
    props: {
        fullHeight: { type: Boolean, default: false },
    },

    data() {
        return {
            flatTree: [],
            loadingRoot: true,
            selectedPaths: [],
            reason: '',
            submitting: false,
            error: null,
        }
    },

    mounted() {
        this.loadRoot()
    },

    methods: {
        t,

        isSelected(path) {
            return this.selectedPaths.includes(path)
        },

        toggleSelect(path) {
            const i = this.selectedPaths.indexOf(path)
            if (i === -1) {
                this.selectedPaths.push(path)
            } else {
                this.selectedPaths.splice(i, 1)
            }
        },

        async loadRoot() {
            this.loadingRoot = true
            this.error = null
            try {
                const items = await this.fetchItems('/files')
                this.flatTree = items.map(item => this.makeNode(item, 0))
            } catch (e) {
                this.error = t('folder_protection', 'Could not load folders.')
            } finally {
                this.loadingRoot = false
            }
        },

        async toggle(node) {
            if (node.expanded) {
                this.collapse(node)
            } else {
                await this.expand(node)
            }
        },

        collapse(node) {
            const prefix = node.path + '/'
            const idx = this.flatTree.indexOf(node)
            let end = idx + 1
            while (end < this.flatTree.length && this.flatTree[end].path.startsWith(prefix)) {
                end++
            }
            this.flatTree.splice(idx + 1, end - idx - 1)
            node.expanded = false
        },

        async expand(node) {
            if (node.loadingChildren) return
            node.loadingChildren = true
            try {
                const items = await this.fetchItems(node.path)
                const children = items.map(item => this.makeNode(item, node.depth + 1))
                const idx = this.flatTree.indexOf(node)
                this.flatTree.splice(idx + 1, 0, ...children)
                node.expanded = true
            } catch (e) {
                // silently ignore — arrow stays collapsed
            } finally {
                node.loadingChildren = false
            }
        },

        makeNode(item, depth) {
            return {
                name: item.name,
                path: item.path,
                isProtected: item.isProtected,
                hasChildren: item.hasChildren,
                depth,
                expanded: false,
                loadingChildren: false,
            }
        },

        async fetchItems(dbPath) {
            const url = generateUrl('/apps/folder_protection/api/browse')
            const resp = await axios.get(url, { params: { path: dbPath } })
            return resp.data.items
        },

        confirm() {
            if (this.selectedPaths.length === 0) return
            this.$emit('done', { paths: this.selectedPaths.slice(), reason: this.reason })
        },
    },
}
</script>

<style scoped>
.fp-tree-picker {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    margin-top: 8px;
    background: var(--color-main-background);
    display: flex;
    flex-direction: column;
}

.fp-loading, .fp-empty, .fp-error {
    padding: 16px;
    text-align: center;
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
}
.fp-error { color: var(--color-error); }

.fp-tree-scroll {
    max-height: 240px;
    overflow-y: auto;
    flex: 1;
}

.fp-node {
    display: flex;
    align-items: center;
    gap: 4px;
    padding-top: 3px;
    padding-bottom: 3px;
    padding-right: 8px;
    border-bottom: 1px solid var(--color-border-dark);
}
.fp-node:last-child { border-bottom: none; }
.fp-node:hover { background: var(--color-background-hover); }

.fp-arrow-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 2px;
    width: 20px;
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.fp-arrow-btn:hover { color: var(--color-main-text); }
.fp-arrow-btn::before {
    content: '';
    display: inline-block;
    width: 5px;
    height: 5px;
    border-right: 1.5px solid currentColor;
    border-bottom: 1.5px solid currentColor;
    transform: rotate(-45deg);
    transition: transform 0.15s ease;
}
.fp-arrow-btn.is-expanded::before { transform: rotate(45deg); }
.fp-arrow-btn.is-loading::before  { display: none; }

.fp-arrow-placeholder {
    display: inline-block;
    width: 20px;
    flex-shrink: 0;
}

.fp-check {
    flex-shrink: 0;
    margin: 0;
    cursor: pointer;
}
.fp-check:disabled { opacity: 0.5; cursor: default; }

.fp-folder-icon {
    flex-shrink: 0;
}

.fp-node-name {
    flex: 1;
    font-size: 0.9em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.fp-lock {
    font-size: 0.75em;
    flex-shrink: 0;
}

.fp-spin {
    display: inline-block;
    width: 14px;
    height: 14px;
}

.fp-footer {
    border-top: 1px solid var(--color-border);
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.fp-reason-input {
    width: 100%;
    box-sizing: border-box;
}

.fp-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}

.fp-tree-picker.fp-full-height {
    border: none;
    margin-top: 0;
    min-height: 460px;
}

.fp-tree-picker.fp-full-height .fp-tree-scroll {
    max-height: 380px;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Displays a group of duplicate files with thumbnails and actions.
-->
<template>
	<div class="dup-group">
		<div class="dup-group__header" @click="expanded = !expanded">
			<span class="dup-group__badge">{{ group.count }} copies</span>
			<span class="dup-group__size">{{ formatBytes(group.totalSize) }} total</span>
			<span class="dup-group__hash" :title="group.contentHash">
				SHA-256: {{ group.contentHash.substring(0, 12) }}…
			</span>
			<span class="dup-group__toggle">{{ expanded ? '▾' : '▸' }}</span>
		</div>

		<transition name="slide">
			<div v-if="expanded" class="dup-group__files">
				<DuplicateItem v-for="file in group.files"
					:key="file.fileId"
					:file="file"
					:is-only-copy="group.files.length <= 1"
					:selected="selectedFiles.includes(file.fileId)"
					:retained="retainedFileIds.includes(file.fileId)"
					@delete="$emit('delete-file', file.fileId)"
					@keep-only="$emit('keep-file', file.fileId, group.files.map(groupFile => groupFile.fileId), group.contentHash)"
					@toggle-select="$emit('toggle-select', file.fileId)" />
			</div>
		</transition>
	</div>
</template>

<script>
import DuplicateItem from './DuplicateItem.vue'

export default {
	name: 'DuplicateGroup',

	components: {
		DuplicateItem,
	},

	props: {
		group: {
			type: Object,
			required: true,
		},
		selectedFiles: {
			type: Array,
			default: () => [],
		},
		retainedFileIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['delete-file', 'keep-file', 'toggle-select'],

	data() {
		return {
			expanded: true,
		}
	},

	methods: {
		formatBytes(bytes) {
			if (bytes === 0) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(1024))
			const val = (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)
			return `${val} ${units[i]}`
		},
	},
}
</script>

<style scoped>
.dup-group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-main-background);
}

.dup-group__header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	cursor: pointer;
	background: var(--color-background-hover);
	user-select: none;
}

.dup-group__header:hover {
	background: var(--color-background-dark);
}

.dup-group__badge {
	background: var(--color-warning);
	color: var(--color-warning-text);
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
}

.dup-group__size {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.dup-group__hash {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	font-family: monospace;
	margin-left: auto;
}

.dup-group__toggle {
	font-size: 1.2em;
	color: var(--color-text-maxcontrast);
}

.dup-group__files {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
	padding: 12px 16px;
}

/* Expand/collapse animation */
.slide-enter-active,
.slide-leave-active {
	transition: all 0.2s ease;
	overflow: hidden;
}

.slide-enter,
.slide-leave-to {
	opacity: 0;
	max-height: 0;
	padding-top: 0;
	padding-bottom: 0;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Card displaying a single file within a duplicate group.
-->
<template>
	<div class="dup-item" :class="{ 'dup-item--selected': selected, 'dup-item--retained': retained }">
		<!-- Thumbnail -->
		<div class="dup-item__preview">
			<img :src="thumbnailUrl"
				:alt="fileName"
				loading="lazy"
				@error="imgError = true">
			<div v-if="retained" class="dup-item__retained-badge">
				Retained copy
			</div>
			<div v-if="imgError" class="dup-item__preview-fallback">
				No preview
			</div>
		</div>

		<!-- File info -->
		<div class="dup-item__info">
			<p class="dup-item__name" :title="file.filePath">{{ fileName }}</p>
			<p class="dup-item__path" :title="file.filePath">
				<a class="dup-item__path-link"
					:href="fileUrl"
					target="_blank"
					rel="noopener noreferrer"
					@click.stop>
					{{ file.filePath }}
				</a>
			</p>
			<p class="dup-item__meta">
				{{ formatBytes(file.fileSize) }}
				· {{ formatDate(file.scannedAt) }}
			</p>
		</div>

		<!-- Actions -->
		<div class="dup-item__actions">
			<label class="dup-item__checkbox">
				<input type="checkbox"
					:checked="selected"
					:disabled="isOnlyCopy"
					@change="$emit('toggle-select')">
			</label>
			<NcButton type="error"
				:disabled="isOnlyCopy"
				:title="isOnlyCopy ? 'Cannot delete the last copy' : 'Delete this copy'"
				@click="$emit('delete')">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>
			<NcButton :disabled="isOnlyCopy"
				:title="isOnlyCopy ? 'No duplicates to remove' : 'Keep this file and delete the other copies in this group'"
				@click="$emit('keep-only')">
				Keep
			</NcButton>
			<NcButton :title="'Open in Files'"
				@click="openInFiles">
				<template #icon>
					<OpenInNewIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { generateUrl } from '@nextcloud/router'
import { previewUrl } from '../services/api.js'

export default {
	name: 'DuplicateItem',

	components: {
		NcButton,
		DeleteIcon,
		OpenInNewIcon,
	},

	props: {
		file: {
			type: Object,
			required: true,
		},
		isOnlyCopy: {
			type: Boolean,
			default: false,
		},
		selected: {
			type: Boolean,
			default: false,
		},
		retained: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['delete', 'keep-only', 'toggle-select'],

	data() {
		return {
			imgError: false,
		}
	},

	computed: {
		thumbnailUrl() {
			return previewUrl(this.file.fileId, 256, 256)
		},

		fileName() {
			const parts = this.file.filePath.split('/')
			return parts[parts.length - 1] || this.file.filePath
		},

		fileDirectory() {
			const parts = this.file.filePath.split('/')
			parts.pop()
			const normalized = parts.join('/')
			return normalized.startsWith('/') ? normalized : `/${normalized}`
		},

		fileUrl() {
			const dirParam = encodeURIComponent(this.fileDirectory || '/')
			const fileParam = encodeURIComponent(this.fileName)
			return generateUrl(`/apps/files/?dir=${dirParam}&scrollto=${fileParam}`)
		},
	},

	methods: {
		formatBytes(bytes) {
			if (bytes === 0) return '0 B'
			const units = ['B', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(1024))
			const val = (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)
			return `${val} ${units[i]}`
		},

		formatDate(isoStr) {
			if (!isoStr) return ''
			const d = new Date(isoStr)
			return d.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},

		openInFiles() {
			window.open(this.fileUrl, '_blank')
		},
	},
}
</script>

<style scoped>
.dup-item {
	display: flex;
	flex-direction: column;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
	background: var(--color-main-background);
	transition: border-color 0.15s;
}

.dup-item--selected {
	border-color: var(--color-primary-element);
}

.dup-item--retained {
	border-color: var(--color-success);
	box-shadow: inset 0 0 0 1px var(--color-success);
}

.dup-item__preview {
	position: relative;
	width: 100%;
	height: 180px;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.dup-item__preview img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.dup-item__preview-fallback {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dup-item__retained-badge {
	position: absolute;
	top: 8px;
	left: 8px;
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-success);
	color: var(--color-success-text);
	font-size: 0.75em;
	font-weight: 600;
}

.dup-item__info {
	padding: 8px 12px;
	flex: 1;
	min-width: 0;
}

.dup-item__name {
	font-weight: 600;
	margin: 0 0 2px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dup-item__path {
	margin: 0 0 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	white-space: normal;
	overflow-wrap: anywhere;
}

.dup-item__path-link {
	color: var(--color-text-maxcontrast);
	text-decoration: underline;
}

.dup-item__meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.dup-item__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 12px;
	border-top: 1px solid var(--color-border);
}

.dup-item__checkbox {
	margin-right: auto;
	cursor: pointer;
}

.dup-item__checkbox input {
	width: 18px;
	height: 18px;
	cursor: pointer;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Card displaying a single classified file with move/delete actions.
  -->
<template>
	<div class="cls-item"
		:class="{ 'cls-item--selected': selected }"
		:data-file-id="file.fileId"
		@click="onCardClick">
		<!-- Thumbnail -->
		<div class="cls-item__preview">
			<img :src="thumbnailUrl"
				:alt="fileName"
				loading="lazy"
				@error="imgError = true">
			<div v-if="selected" class="cls-item__selected-badge">
				✓
			</div>
			<div v-if="imgError" class="cls-item__preview-fallback">
				No preview
			</div>
			<div class="cls-item__confidence" :title="`Confidence: ${Math.round(file.confidence * 100)}%`">
				{{ Math.round(file.confidence * 100) }}%
			</div>
		</div>

		<!-- File info -->
		<div class="cls-item__info">
			<p class="cls-item__name" :title="file.filePath">{{ fileName }}</p>
			<p class="cls-item__path" :title="file.filePath">
				<a class="cls-item__path-link"
					:href="fileUrl"
					target="_blank"
					rel="noopener noreferrer"
					@click.stop>
					{{ file.filePath }}
				</a>
			</p>
			<p class="cls-item__meta">
				{{ formatBytes(file.fileSize) }}
			</p>
			<div v-if="file.indicators && file.indicators.length > 0" class="cls-item__indicators">
				<span v-for="ind in file.indicators"
					:key="ind"
					class="cls-item__indicator">
					{{ formatIndicator(ind) }}
				</span>
			</div>
		</div>

		<!-- Actions -->
		<div class="cls-item__actions">
			<NcButton :title="'Move to folder'"
				@click="$emit('move', file.fileId)">
				<template #icon>
					<FolderMoveIcon :size="20" />
				</template>
				Move
			</NcButton>
			<NcButton type="error"
				:title="'Delete (move to trash)'"
				@click="$emit('delete', file.fileId)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
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
import FolderMoveIcon from 'vue-material-design-icons/FolderMove.vue'
import { generateUrl } from '@nextcloud/router'
import { previewUrl } from '../services/api.js'

export default {
	name: 'ClassifiedItem',

	components: {
		NcButton,
		DeleteIcon,
		OpenInNewIcon,
		FolderMoveIcon,
	},

	props: {
		file: {
			type: Object,
			required: true,
		},
		selected: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['delete', 'move', 'toggle-select'],

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

		formatIndicator(indicator) {
			return indicator.replace(/_/g, ' ')
		},

		openInFiles() {
			window.open(this.fileUrl, '_blank')
		},

		onCardClick(event) {
			if (event.target.closest('button') || event.target.closest('a') || event.target.closest('.cls-item__actions')) {
				return
			}
			this.$emit('toggle-select', this.file.fileId)
		},
	},
}
</script>

<style scoped>
.cls-item {
	display: flex;
	flex-direction: column;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
	background: var(--color-main-background);
	transition: border-color 0.15s;
}

.cls-item--selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.cls-item:hover {
	border-color: var(--color-primary-element);
}

.cls-item__preview {
	position: relative;
	width: 100%;
	height: 180px;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.cls-item__preview img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.cls-item__preview-fallback {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.cls-item__selected-badge {
	position: absolute;
	top: 6px;
	left: 6px;
	width: 20px;
	height: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: #fff;
	font-size: 0.75em;
	font-weight: 700;
	z-index: 2;
}

.cls-item__confidence {
	position: absolute;
	top: 6px;
	right: 6px;
	background: rgba(0, 0, 0, 0.6);
	color: #fff;
	padding: 2px 6px;
	border-radius: 4px;
	font-size: 0.75em;
	font-weight: 600;
}

.cls-item__info {
	padding: 10px 12px;
	flex: 1;
}

.cls-item__name {
	margin: 0 0 2px;
	font-weight: 600;
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.cls-item__path {
	margin: 0 0 4px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.cls-item__path-link {
	color: var(--color-text-maxcontrast);
	text-decoration: none;
}

.cls-item__path-link:hover {
	text-decoration: underline;
}

.cls-item__meta {
	margin: 0 0 6px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.cls-item__indicators {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.cls-item__indicator {
	background: var(--color-background-dark);
	padding: 1px 6px;
	border-radius: 8px;
	font-size: 0.7em;
	color: var(--color-text-maxcontrast);
}

.cls-item__actions {
	display: flex;
	gap: 6px;
	padding: 8px 12px;
	border-top: 1px solid var(--color-border);
	justify-content: flex-end;
	align-items: center;
}
</style>

<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Displays a category group header with expandable list of classified files.
  -->
<template>
	<div class="cls-group">
		<div class="cls-group__header" @click="expanded = !expanded">
			<span class="cls-group__icon">{{ categoryIcon }}</span>
			<span class="cls-group__name">{{ categoryLabel }}</span>
			<span class="cls-group__badge">{{ total }} files</span>
			<span class="cls-group__toggle">{{ expanded ? '▾' : '▸' }}</span>
		</div>

		<transition name="slide">
			<div v-if="expanded" class="cls-group__content">
				<div v-if="files.length > 0" class="cls-group__files">
					<ClassifiedItem v-for="file in files"
						:key="file.fileId"
						:file="file"
						:selected="selectedFileIds.includes(file.fileId)"
						@delete="$emit('delete-file', $event)"
						@move="$emit('move-file', $event)"
						@toggle-select="$emit('toggle-select', $event)" />
				</div>

				<div v-if="files.length === 0 && !loading" class="cls-group__empty">
					No files in this category.
				</div>

				<div v-if="loading" class="cls-group__loading">
					<NcLoadingIcon :size="28" />
				</div>

				<div v-if="files.length < total" class="cls-group__load-more">
					<NcButton @click="$emit('load-more')">
						Load more ({{ files.length }} of {{ total }})
					</NcButton>
				</div>
			</div>
		</transition>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import ClassifiedItem from './ClassifiedItem.vue'

const CATEGORY_META = {
	document: { label: 'Documents & Screenshots', icon: '📄' },
	meme: { label: 'Memes & Social Media', icon: '😂' },
	nature: { label: 'Nature & Landscapes', icon: '🌄' },
	family: { label: 'Family & People', icon: '👨‍👩‍👧‍👦' },
	object: { label: 'Objects & Other', icon: '📦' },
}

export default {
	name: 'ClassifiedGroup',

	components: {
		NcButton,
		NcLoadingIcon,
		ClassifiedItem,
	},

	props: {
		category: {
			type: String,
			required: true,
		},
		files: {
			type: Array,
			default: () => [],
		},
		total: {
			type: Number,
			default: 0,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		selectedFileIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['delete-file', 'move-file', 'toggle-select', 'load-more'],

	data() {
		return {
			expanded: false,
		}
	},

	computed: {
		categoryLabel() {
			return CATEGORY_META[this.category]?.label || this.category
		},
		categoryIcon() {
			return CATEGORY_META[this.category]?.icon || '📁'
		},
	},
}
</script>

<style scoped>
.cls-group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-main-background);
}

.cls-group__header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	cursor: pointer;
	background: var(--color-background-hover);
	user-select: none;
}

.cls-group__header:hover {
	background: var(--color-background-dark);
}

.cls-group__icon {
	font-size: 1.4em;
}

.cls-group__name {
	font-weight: 600;
	font-size: 1.05em;
}

.cls-group__badge {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
}

.cls-group__toggle {
	font-size: 1.2em;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

.cls-group__content {
	padding: 12px 16px;
}

.cls-group__files {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
}

.cls-group__empty {
	text-align: center;
	padding: 20px;
	color: var(--color-text-maxcontrast);
}

.cls-group__loading {
	display: flex;
	justify-content: center;
	padding: 20px;
}

.cls-group__load-more {
	display: flex;
	justify-content: center;
	margin-top: 12px;
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

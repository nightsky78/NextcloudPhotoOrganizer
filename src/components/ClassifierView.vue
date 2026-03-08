<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Main view for the Image Classifier tab.
  - Shows category groups with their classified files and actions.
  -->
<template>
	<div class="classifier">
		<!-- Header with classify button -->
		<div class="classifier__header">
			<h2>Image Classifier</h2>
			<div class="classifier__header-actions">
				<div class="classifier__scope-toggle">
					<button type="button"
						class="classifier__scope-btn"
						:class="{ 'classifier__scope-btn--active': localScope === 'all' }"
						@click="setScope('all')">
						Whole drive
					</button>
					<button type="button"
						class="classifier__scope-btn"
						:class="{ 'classifier__scope-btn--active': localScope === 'photos' }"
						@click="setScope('photos')">
						Photos folder
					</button>
				</div>
				<div v-if="totalClassified > 0" class="classifier__stats">
					<span class="classifier__stat">
						<strong>{{ totalClassified }}</strong> images classified
					</span>
				</div>
			</div>
		</div>

		<!-- Classify progress -->
		<ScanProgress v-if="classifying"
			:status="classifyProgress.status"
			:total="classifyProgress.total"
			:processed="classifyProgress.processed" />

		<!-- Bulk action bar -->
		<div v-if="selectedFileIds.length > 0" class="classifier__bulk-bar">
			<span>{{ selectedFileIds.length }} file(s) selected</span>
			<NcButton @click="onMoveSelected">
				Move selected
			</NcButton>
			<NcButton type="error" @click="confirmBulkDelete">
				Delete selected
			</NcButton>
			<NcButton @click="clearSelection">
				Clear selection
			</NcButton>
		</div>

		<!-- Move dialog -->
		<div v-if="moveDialog.visible" class="classifier__move-dialog">
			<div class="classifier__move-dialog-backdrop" @click="closeMoveDialog" />
			<div class="classifier__move-dialog-content">
				<h3>Move file to folder</h3>
				<p>Enter the target folder path (relative to your files root):</p>
				<input v-model="moveDialog.targetFolder"
					type="text"
					class="classifier__move-input"
					placeholder="e.g. Photos/Nature"
					@keyup.enter="confirmMove">
				<div class="classifier__move-actions">
					<NcButton type="primary" @click="confirmMove">
						Move
					</NcButton>
					<NcButton @click="closeMoveDialog">
						Cancel
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Category groups -->
		<div v-if="!loading && categoryOrder.length > 0"
			ref="selectionArea"
			class="classifier__groups"
			:class="{ 'classifier__groups--dragging': dragSelection.active }"
			@mousedown="onSelectionMouseDown">
			<ClassifiedGroup v-for="cat in categoryOrder"
				:key="cat"
				:category="cat"
				:files="categoryFiles[cat] || []"
				:total="categoryCounts[cat] || 0"
				:loading="categoryLoading[cat] || false"
				:selected-file-ids="selectedFileIds"
				@delete-file="onDeleteFile"
				@move-file="onMoveFile"
				@toggle-select="onToggleSelect"
				@load-more="loadMoreFiles(cat)" />

			<div v-if="dragSelection.active" class="classifier__selection-rect" :style="selectionRectStyle" />
		</div>

		<!-- Empty state -->
		<div v-if="!loading && !classifying && totalClassified === 0" class="classifier__empty">
			<div class="classifier__empty-icon">🏷️</div>
			<h3>No images classified yet</h3>
			<p>
				Run the OCC classification command to automatically sort your photos
				into categories like documents, memes, nature, family photos, and more.
			</p>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="classifier__loading">
			<NcLoadingIcon :size="44" />
			<p>Loading classifications…</p>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import ClassifiedGroup from './ClassifiedGroup.vue'
import ScanProgress from './ScanProgress.vue'

import {
	fetchClassifyStatus,
	fetchCategories,
	fetchCategoryFiles,
	moveClassifiedFile,
	deleteClassifiedFile,
} from '../services/api.js'

const PAGE_SIZE = 50

const CATEGORY_ORDER = ['document', 'meme', 'nature', 'family', 'object']

export default {
	name: 'ClassifierView',

	props: {
		scope: {
			type: String,
			default: 'all',
		},
	},

	components: {
		NcButton,
		NcLoadingIcon,
		ClassifiedGroup,
		ScanProgress,
	},

	data() {
		return {
			localScope: this.scope,
			loading: true,
			classifying: false,
			classifyProgress: { status: 'idle', total: 0, processed: 0 },
			categoryCounts: {},
			categoryFiles: {},
			categoryLoading: {},
			totalClassified: 0,
			categoryOrder: CATEGORY_ORDER,
			selectedFileIds: [],
			pollTimer: null,
			dragSelection: {
				active: false,
				startX: 0,
				startY: 0,
				currentX: 0,
				currentY: 0,
				baseSelected: [],
				appendMode: false,
			},
			moveDialog: {
				visible: false,
				fileIds: [],
				targetFolder: '',
			},
		}
	},

	watch: {
		scope(newScope) {
			if (newScope !== this.localScope) {
				this.localScope = newScope
				this.loadInitialData()
			}
		},
	},

	computed: {
		selectionRectStyle() {
			const left = Math.min(this.dragSelection.startX, this.dragSelection.currentX)
			const top = Math.min(this.dragSelection.startY, this.dragSelection.currentY)
			const width = Math.abs(this.dragSelection.currentX - this.dragSelection.startX)
			const height = Math.abs(this.dragSelection.currentY - this.dragSelection.startY)
			return {
				left: `${left}px`,
				top: `${top}px`,
				width: `${width}px`,
				height: `${height}px`,
			}
		},
	},

	async created() {
		await this.loadInitialData()
	},

	beforeDestroy() {
		this.stopPolling()
		this.detachSelectionListeners()
	},

	methods: {
		async setScope(scope) {
			if (scope !== 'all' && scope !== 'photos') {
				return
			}
			if (scope === this.localScope) {
				return
			}

			this.localScope = scope
			this.$emit('scope-change', scope)
			this.selectedFileIds = []
			this.categoryFiles = {}
			this.categoryCounts = {}
			this.totalClassified = 0
			await this.loadInitialData()
		},

		async loadInitialData() {
			this.loading = true
			try {
				const [catResult, statusResult] = await Promise.allSettled([
					fetchCategories(this.localScope),
					fetchClassifyStatus(),
				])

				if (catResult.status === 'fulfilled') {
					this.categoryCounts = catResult.value.categories || {}
					this.totalClassified = catResult.value.total || 0
				}

				if (statusResult.status === 'fulfilled') {
					this.classifyProgress = statusResult.value
					this.classifying = statusResult.value.status === 'classifying'
					if (this.classifying) {
						this.startPolling()
					}
				}

				// Pre-load first page of files for each non-empty category
				const loadPromises = CATEGORY_ORDER
					.filter(cat => (this.categoryCounts[cat] || 0) > 0)
					.map(async (cat) => {
						this.categoryLoading[cat] = true
						try {
							const result = await fetchCategoryFiles(cat, PAGE_SIZE, 0, this.localScope)
							this.$set(this.categoryFiles, cat, result.files)
							this.$set(this.categoryCounts, cat, result.total)
						} catch (err) {
							console.error(`PhotoDedup: failed to load ${cat} files`, err)
						} finally {
							this.$set(this.categoryLoading, cat, false)
						}
					})

				await Promise.allSettled(loadPromises)
			} catch (err) {
				console.error('PhotoDedup: failed to load classifier data', err)
			} finally {
				this.loading = false
			}
		},

		startPolling() {
			this.stopPolling()
			this.pollTimer = setInterval(async () => {
				try {
					this.classifyProgress = await fetchClassifyStatus()
					if (this.classifyProgress.status !== 'classifying') {
						this.stopPolling()
					}
				} catch (err) {
					// Ignore polling errors
				}
			}, 2000)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},

		async loadMoreFiles(category) {
			const currentFiles = this.categoryFiles[category] || []
			this.$set(this.categoryLoading, category, true)

			try {
				const result = await fetchCategoryFiles(category, PAGE_SIZE, currentFiles.length, this.localScope)
				const merged = [...currentFiles, ...result.files]
				this.$set(this.categoryFiles, category, merged)
			} catch (err) {
				console.error(`PhotoDedup: failed to load more ${category} files`, err)
			} finally {
				this.$set(this.categoryLoading, category, false)
			}
		},

		async onDeleteFile(fileId) {
			if (!confirm('Delete this file? It will be moved to the trash bin.')) {
				return
			}
			try {
				const result = await deleteClassifiedFile(fileId)
				if (result.success) {
					this.removeFileFromCategory(fileId)
				} else {
					alert(result.message)
				}
			} catch (err) {
				console.error('PhotoDedup: delete classified file failed', err)
				alert('Failed to delete file.')
			}
		},

		async confirmBulkDelete() {
			const fileIds = [...this.selectedFileIds]
			if (fileIds.length === 0) {
				return
			}

			if (!confirm(`Delete ${fileIds.length} selected file(s)? They will be moved to the trash bin.`)) {
				return
			}

			let deleted = 0
			let failed = 0

			for (const fileId of fileIds) {
				try {
					const result = await deleteClassifiedFile(fileId)
					if (result.success) {
						this.removeFileFromCategory(fileId)
						deleted++
					} else {
						failed++
					}
				} catch (err) {
					failed++
				}
			}

			this.clearSelection()
			if (failed > 0) {
				alert(`${deleted} deleted, ${failed} failed.`)
			}
		},

		onMoveFile(fileId) {
			this.openMoveDialog([fileId])
		},

		onMoveSelected() {
			if (this.selectedFileIds.length === 0) {
				return
			}
			this.openMoveDialog([...this.selectedFileIds])
		},

		openMoveDialog(fileIds) {
			this.moveDialog = {
				visible: true,
				fileIds,
				targetFolder: '',
			}
		},

		closeMoveDialog() {
			this.moveDialog = {
				visible: false,
				fileIds: [],
				targetFolder: '',
			}
		},

		async confirmMove() {
			const { fileIds, targetFolder } = this.moveDialog
			if (!targetFolder.trim()) {
				alert('Please enter a target folder.')
				return
			}

			let moved = 0
			let failed = 0

			try {
				for (const fileId of fileIds) {
					const result = await moveClassifiedFile(fileId, targetFolder.trim())
					if (result.success) {
						this.removeFileFromCategory(fileId)
						moved++
					} else {
						failed++
					}
				}

				this.closeMoveDialog()
				this.clearSelection()

				if (failed > 0) {
					alert(`${moved} moved, ${failed} failed.`)
				}
			} catch (err) {
				console.error('PhotoDedup: move file failed', err)
				alert('Failed to move file.')
			}
		},

		onToggleSelect(fileId) {
			const idx = this.selectedFileIds.indexOf(fileId)
			if (idx === -1) {
				this.selectedFileIds.push(fileId)
			} else {
				this.selectedFileIds.splice(idx, 1)
			}
		},

		clearSelection() {
			this.selectedFileIds = []
		},

		onSelectionMouseDown(event) {
			if (event.button !== 0) {
				return
			}

			const target = event.target
			if (target.closest('.cls-group__header') || target.closest('.cls-item__actions') || target.closest('button') || target.closest('a') || target.closest('input')) {
				return
			}

			const area = this.$refs.selectionArea
			if (!area) {
				return
			}

			const areaRect = area.getBoundingClientRect()
			this.dragSelection.active = true
			this.dragSelection.startX = this.clamp(event.clientX - areaRect.left, 0, areaRect.width)
			this.dragSelection.startY = this.clamp(event.clientY - areaRect.top, 0, areaRect.height)
			this.dragSelection.currentX = this.dragSelection.startX
			this.dragSelection.currentY = this.dragSelection.startY
			this.dragSelection.appendMode = event.ctrlKey || event.metaKey
			this.dragSelection.baseSelected = this.dragSelection.appendMode ? [...this.selectedFileIds] : []

			if (!this.dragSelection.appendMode) {
				this.clearSelection()
			}

			this.attachSelectionListeners()
			this.updateSelectionFromDrag()
			event.preventDefault()
		},

		onSelectionMouseMove(event) {
			if (!this.dragSelection.active) {
				return
			}

			const area = this.$refs.selectionArea
			if (!area) {
				return
			}

			const areaRect = area.getBoundingClientRect()
			this.dragSelection.currentX = this.clamp(event.clientX - areaRect.left, 0, areaRect.width)
			this.dragSelection.currentY = this.clamp(event.clientY - areaRect.top, 0, areaRect.height)
			this.updateSelectionFromDrag()
		},

		onSelectionMouseUp() {
			if (!this.dragSelection.active) {
				return
			}

			this.dragSelection.active = false
			this.detachSelectionListeners()
		},

		attachSelectionListeners() {
			window.addEventListener('mousemove', this.onSelectionMouseMove)
			window.addEventListener('mouseup', this.onSelectionMouseUp)
		},

		detachSelectionListeners() {
			window.removeEventListener('mousemove', this.onSelectionMouseMove)
			window.removeEventListener('mouseup', this.onSelectionMouseUp)
		},

		updateSelectionFromDrag() {
			const area = this.$refs.selectionArea
			if (!area) {
				return
			}

			const areaRect = area.getBoundingClientRect()
			const selectionRect = {
				left: areaRect.left + Math.min(this.dragSelection.startX, this.dragSelection.currentX),
				right: areaRect.left + Math.max(this.dragSelection.startX, this.dragSelection.currentX),
				top: areaRect.top + Math.min(this.dragSelection.startY, this.dragSelection.currentY),
				bottom: areaRect.top + Math.max(this.dragSelection.startY, this.dragSelection.currentY),
			}

			const selected = new Set(this.dragSelection.baseSelected)
			const cards = area.querySelectorAll('.cls-item[data-file-id]')

			for (const card of cards) {
				const rect = card.getBoundingClientRect()
				if (this.rectIntersects(selectionRect, rect)) {
					const fileId = Number(card.getAttribute('data-file-id'))
					if (!Number.isNaN(fileId)) {
						selected.add(fileId)
					}
				}
			}

			this.selectedFileIds = [...selected]
		},

		rectIntersects(a, b) {
			return a.left <= b.right && a.right >= b.left && a.top <= b.bottom && a.bottom >= b.top
		},

		clamp(value, min, max) {
			return Math.max(min, Math.min(max, value))
		},

		removeFileFromCategory(fileId) {
			this.selectedFileIds = this.selectedFileIds.filter(id => id !== fileId)

			for (const cat of CATEGORY_ORDER) {
				const files = this.categoryFiles[cat]
				if (!files) continue
				const idx = files.findIndex(f => f.fileId === fileId)
				if (idx !== -1) {
					files.splice(idx, 1)
					this.$set(this.categoryFiles, cat, files)
					if (this.categoryCounts[cat]) {
						this.$set(this.categoryCounts, cat, this.categoryCounts[cat] - 1)
					}
					this.totalClassified = Math.max(0, this.totalClassified - 1)
					break
				}
			}
		},
	},
}
</script>

<style scoped>
.classifier {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.classifier__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 20px;
}

.classifier__header h2 {
	margin: 0;
	font-size: 1.5em;
}

.classifier__header-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.classifier__scope-toggle {
	display: inline-flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	overflow: hidden;
}

.classifier__scope-btn {
	border: none;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 6px 12px;
	font-size: 0.85em;
	cursor: pointer;
}

.classifier__scope-btn:hover {
	background: var(--color-background-hover);
}

.classifier__scope-btn--active {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.classifier__stats {
	display: flex;
	gap: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.classifier__stat strong {
	color: var(--color-main-text);
}

.classifier__groups {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.classifier__groups--dragging {
	user-select: none;
	cursor: crosshair;
}

.classifier__selection-rect {
	position: absolute;
	border: 1px solid var(--color-primary-element);
	background: color-mix(in srgb, var(--color-primary-element) 20%, transparent);
	pointer-events: none;
	z-index: 15;
	border-radius: var(--border-radius);
}

.classifier__bulk-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	margin-bottom: 16px;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	position: sticky;
	top: 8px;
	z-index: 20;
}

.classifier__empty {
	text-align: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.classifier__empty-icon {
	font-size: 3em;
	margin-bottom: 12px;
}

.classifier__empty h3 {
	font-size: 1.3em;
	color: var(--color-main-text);
	margin: 0 0 8px;
}

.classifier__empty p {
	max-width: 500px;
	margin: 0 auto;
	line-height: 1.5;
}

.classifier__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 60px 0;
	color: var(--color-text-maxcontrast);
}

/* Move dialog */
.classifier__move-dialog {
	position: fixed;
	inset: 0;
	z-index: 100;
	display: flex;
	align-items: center;
	justify-content: center;
}

.classifier__move-dialog-backdrop {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
}

.classifier__move-dialog-content {
	position: relative;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	min-width: 400px;
	max-width: 500px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.classifier__move-dialog-content h3 {
	margin: 0 0 8px;
}

.classifier__move-dialog-content p {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.classifier__move-input {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 1em;
	margin-bottom: 16px;
	box-sizing: border-box;
}

.classifier__move-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>

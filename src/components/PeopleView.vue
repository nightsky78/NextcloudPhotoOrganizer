<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="people">
		<div class="people__header">
			<h2>People</h2>
			<div class="people__header-actions">
				<div class="people__scope-toggle">
					<button type="button"
						class="people__scope-btn"
						:class="{ 'people__scope-btn--active': localScope === 'all' }"
						@click="setScope('all')">
						Whole drive
					</button>
					<button type="button"
						class="people__scope-btn"
						:class="{ 'people__scope-btn--active': localScope === 'photos' }"
						@click="setScope('photos')">
						Photos folder
					</button>
				</div>
				<div v-if="!loading" class="people__stats">
					<span><strong>{{ totalFaceImages }}</strong> face images</span>
					<span><strong>{{ totalClusters }}</strong> clusters</span>
				</div>
				<button v-if="!loading && scanStatus !== 'scanning' && clusters.length > 0"
					type="button"
					class="people__toggle-references"
					@click="showReferenceBuilder = !showReferenceBuilder">
					{{ showReferenceBuilder ? 'Hide person references' : 'Show person references' }}
				</button>
			</div>
		</div>

		<div v-if="loading" class="people__loading">
			<NcLoadingIcon :size="44" />
			<p>Loading face clusters…</p>
		</div>

		<div v-else-if="scanStatus === 'scanning'" class="people__scanning">
			<NcLoadingIcon :size="44" />
			<p>People scan in progress…</p>
			<p v-if="scanTotal > 0" class="people__scan-progress">
				{{ scanProcessed }} / {{ scanTotal }} files processed
			</p>
		</div>

		<div v-else class="people__clusters">
			<template v-if="clusters.length > 0">
				<div v-for="cluster in clusters" :key="cluster.id" class="people__cluster">
				<div class="people__cluster-head">
					<div class="people__cluster-title">
						<h3>{{ cluster.name }}</h3>
						<div class="people__cluster-label">
							<input v-model="clusterLabels[cluster.id]"
								type="text"
								class="people__label-input"
								placeholder="Label person">
							<button type="button"
								class="people__label-save"
								:disabled="savingLabels[cluster.id]"
								@click="saveClusterLabel(cluster)">
								{{ savingLabels[cluster.id] ? 'Saving…' : 'Save' }}
							</button>
						</div>
						<p v-if="!clusterHasReferenceCandidate(cluster)" class="people__reference-hint">
							Select a photo with exactly one face to set a person reference.
						</p>
					</div>
					<span>{{ cluster.count }} photo(s)</span>
				</div>
				<div class="people__grid">
					<div v-for="file in cluster.files" :key="file.fileId" class="people__item">
						<img :src="previewUrl(file.fileId, 220, 220)"
							:alt="file.filePath"
							loading="lazy">
						<div class="people__item-actions">
							<button type="button"
								class="people__reference-btn"
								:class="{ 'people__reference-btn--active': selectedReferenceSignature(cluster) === file.faceSignature }"
								:title="canUseAsReference(file) ? 'Use this photo as reference' : 'Only photos with exactly one detected face can be used as a reference'"
								:disabled="!canUseAsReference(file)"
								@click="selectReference(cluster.id, file)">
								{{ selectedReferenceSignature(cluster) === file.faceSignature ? 'Reference' : 'Use as reference' }}
							</button>
						</div>
						<div class="people__meta" :title="file.filePath">{{ file.filePath }}</div>
					</div>
				</div>

				<div v-if="cluster.has_more_files" class="people__cluster-actions">
					<button type="button"
						class="people__load-more"
						:disabled="loadingMoreByCluster[cluster.id]"
						@click="loadMoreClusterFiles(cluster)">
						{{ loadingMoreByCluster[cluster.id] ? 'Loading…' : 'Load more' }}
					</button>
				</div>
				</div>
			</template>

			<div v-if="clusters.length === 0 || showReferenceBuilder" class="people__reference-builder">
				<div class="people__reference-builder-head">
					<h3>Create person references</h3>
					<span>{{ referenceCandidates.length }} single-face candidates</span>
				</div>
				<p class="people__reference-builder-sub">
					Select one clear single-face photo, enter a name, then create a reference.
				</p>
				<div v-if="referenceCandidates.length === 0" class="people__reference-empty">
					No single-face candidates available right now.
				</div>
				<div v-else class="people__reference-grid">
					<div v-for="candidate in visibleReferenceCandidates" :key="candidate.faceSignature" class="people__reference-item">
						<img :src="previewUrl(candidate.fileId, 220, 220)"
							:alt="candidate.filePath"
							loading="lazy">
						<input v-model="referenceLabelBySignature[candidate.faceSignature]"
							type="text"
							class="people__reference-input"
							placeholder="Person name">
						<button type="button"
							class="people__reference-create"
							:disabled="creatingReferenceBySignature[candidate.faceSignature] || !canCreateReference(candidate)"
							@click="createReference(candidate)">
							{{ creatingReferenceBySignature[candidate.faceSignature] ? 'Creating…' : 'Create person' }}
						</button>
						<div class="people__meta" :title="candidate.filePath">{{ candidate.filePath }}</div>
					</div>
				</div>
				<div v-if="referenceCandidates.length > referenceCandidateLimit" class="people__cluster-actions">
					<button type="button"
						class="people__load-more"
						@click="showAllReferenceCandidates = !showAllReferenceCandidates">
						{{ showAllReferenceCandidates ? 'Show fewer' : 'Show more candidates' }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import {
	fetchPeopleClusterFiles,
	fetchPeopleClusters,
	fetchPeopleScanStatus,
	previewUrl,
	setPeopleClusterLabel,
} from '../services/api.js'

export default {
	name: 'PeopleView',

	components: {
		NcLoadingIcon,
	},

	props: {
		scope: {
			type: String,
			default: 'all',
		},
	},

	data() {
		return {
			localScope: this.scope,
			loading: true,
			clusters: [],
			totalClusters: 0,
			clusterLimit: 50,
			clusterFileLimit: 50,
			clusterLabels: {},
			referenceSignatureByCluster: {},
			referenceCandidates: [],
			referenceLabelBySignature: {},
			creatingReferenceBySignature: {},
			referenceCandidateLimit: 24,
			showAllReferenceCandidates: false,
			showReferenceBuilder: false,
			savingLabels: {},
			loadingMoreByCluster: {},
			totalFaceImages: 0,
			scanStatus: 'idle',
			scanTotal: 0,
			scanProcessed: 0,
			pollTimer: null,
		}
	},

	watch: {
		scope(newScope) {
			if (newScope !== this.localScope) {
				this.localScope = newScope
				if (this.scanStatus === 'scanning') {
					this.loading = false
					return
				}
				this.loadClusters()
			}
		},
	},

	async created() {
		await this.checkScanStatus()
		if (this.scanStatus === 'scanning') {
			this.loading = false
			return
		}
		await this.loadClusters()
	},

	beforeDestroy() {
		this.stopPolling()
	},

	methods: {
		previewUrl,

		async setScope(scope) {
			if (scope !== 'all' && scope !== 'photos') {
				return
			}
			if (scope === this.localScope) {
				return
			}
			this.localScope = scope
			this.$emit('scope-change', scope)
			if (this.scanStatus === 'scanning') {
				this.loading = false
				return
			}
			await this.loadClusters()
		},

		async loadClusters() {
			this.loading = true
			try {
				const result = await fetchPeopleClusters(this.localScope, this.clusterLimit, this.clusterFileLimit)
				this.clusters = result.clusters || []
				this.referenceCandidates = Array.isArray(result.reference_candidates) ? result.reference_candidates : []
				this.totalClusters = result.total_clusters || this.clusters.length
				this.clusterLabels = Object.fromEntries(
					this.clusters.map((cluster) => [cluster.id, cluster.name || ''])
				)
				this.referenceSignatureByCluster = Object.fromEntries(
					this.clusters.map((cluster) => {
						const validReference = Array.isArray(cluster.files)
							? (cluster.files.find((file) => this.canUseAsReference(file)) || null)
							: null
						const signature = cluster.label_signature
							|| (validReference?.faceSignature || '')
						return [cluster.id, signature]
					})
				)
				this.loadingMoreByCluster = {}
				this.referenceLabelBySignature = Object.fromEntries(
					this.referenceCandidates.map((candidate) => [candidate.faceSignature, this.referenceLabelBySignature[candidate.faceSignature] || ''])
				)
				this.creatingReferenceBySignature = {}
				this.showAllReferenceCandidates = false
				this.totalFaceImages = result.total_face_images || 0
			} catch (err) {
				console.error('PhotoDedup: failed to load people clusters', err)
				this.clusters = []
				this.referenceCandidates = []
				this.totalClusters = 0
				this.clusterLabels = {}
				this.referenceSignatureByCluster = {}
				this.referenceLabelBySignature = {}
				this.creatingReferenceBySignature = {}
				this.showAllReferenceCandidates = false
				this.loadingMoreByCluster = {}
				this.totalFaceImages = 0
			} finally {
				this.loading = false
			}
		},

		selectedReferenceSignature(cluster) {
			if (!cluster || !cluster.id) {
				return ''
			}

			return this.referenceSignatureByCluster[cluster.id] || ''
		},

		selectReference(clusterId, signature) {
			if (!clusterId || !this.canUseAsReference(signature)) {
				return
			}

			this.referenceSignatureByCluster = {
				...this.referenceSignatureByCluster,
				[clusterId]: signature.faceSignature.trim(),
			}
		},

		canUseAsReference(file) {
			if (!file || typeof file !== 'object') {
				return false
			}

			const signature = typeof file.faceSignature === 'string' ? file.faceSignature.trim() : ''
			if (signature === '') {
				return false
			}

			const faceCount = Number.isFinite(Number(file.faceCountInFile))
				? Number(file.faceCountInFile)
				: 0

			return faceCount === 1
		},

		canCreateReference(candidate) {
			if (!this.canUseAsReference(candidate)) {
				return false
			}

			const label = this.referenceLabelBySignature[candidate.faceSignature] || ''
			return label.trim() !== ''
		},

		async createReference(candidate) {
			if (!this.canCreateReference(candidate)) {
				return
			}

			const signature = candidate.faceSignature
			const label = (this.referenceLabelBySignature[signature] || '').trim()

			this.creatingReferenceBySignature = {
				...this.creatingReferenceBySignature,
				[signature]: true,
			}

			try {
				await setPeopleClusterLabel(signature, label)
				await this.loadClusters()
			} catch (err) {
				console.error('PhotoDedup: failed to create reference label', err)
			} finally {
				this.creatingReferenceBySignature = {
					...this.creatingReferenceBySignature,
					[signature]: false,
				}
			}
		},

		clusterHasReferenceCandidate(cluster) {
			if (!cluster || !Array.isArray(cluster.files)) {
				return false
			}

			return cluster.files.some((file) => this.canUseAsReference(file))
		},

		async loadMoreClusterFiles(cluster) {
			if (!cluster || !cluster.id || !cluster.has_more_files) {
				return
			}

			if (this.loadingMoreByCluster[cluster.id]) {
				return
			}

			const signatures = Array.isArray(cluster.cluster_signatures)
				? cluster.cluster_signatures.filter((signature) => typeof signature === 'string' && signature.trim() !== '')
				: []
			const personKey = typeof cluster.person_key === 'string' ? cluster.person_key.trim() : ''

			if (!personKey && signatures.length === 0) {
				cluster.has_more_files = false
				return
			}

			this.loadingMoreByCluster = {
				...this.loadingMoreByCluster,
				[cluster.id]: true,
			}

			try {
				const offset = Number.isInteger(cluster.next_offset) ? cluster.next_offset : (cluster.files || []).length
				const result = await fetchPeopleClusterFiles(
					personKey !== ''
						? { person: personKey }
						: { signatures },
					this.localScope,
					offset,
					this.clusterFileLimit
				)

				const incomingFiles = Array.isArray(result.files) ? result.files : []
				const existingIds = new Set((cluster.files || []).map((file) => file.fileId))
				const mergedFiles = [...(cluster.files || [])]
				for (const file of incomingFiles) {
					if (existingIds.has(file.fileId)) {
						continue
					}
					existingIds.add(file.fileId)
					mergedFiles.push(file)
				}

				cluster.files = mergedFiles
				cluster.has_more_files = Boolean(result.has_more)
				cluster.next_offset = Number.isInteger(result.next_offset) ? result.next_offset : mergedFiles.length
			} catch (err) {
				console.error('PhotoDedup: failed to load more cluster files', err)
			} finally {
				this.loadingMoreByCluster = {
					...this.loadingMoreByCluster,
					[cluster.id]: false,
				}
			}
		},

		async saveClusterLabel(cluster) {
			const signature = this.selectedReferenceSignature(cluster)
			if (!signature) {
				return
			}

			const selectedFile = Array.isArray(cluster?.files)
				? cluster.files.find((file) => file.faceSignature === signature)
				: null
			if (!this.canUseAsReference(selectedFile)) {
				return
			}

			this.savingLabels = {
				...this.savingLabels,
				[cluster.id]: true,
			}

			try {
				await setPeopleClusterLabel(signature, this.clusterLabels[cluster.id] || '')
				await this.loadClusters()
			} catch (err) {
				console.error('PhotoDedup: failed to save people label', err)
			} finally {
				this.savingLabels = {
					...this.savingLabels,
					[cluster.id]: false,
				}
			}
		},

		async checkScanStatus() {
			try {
				const status = await fetchPeopleScanStatus()
				this.scanStatus = status.status || 'idle'
				this.scanTotal = status.total || 0
				this.scanProcessed = status.processed || 0

				if (this.scanStatus === 'scanning') {
					this.loading = false
					this.startPolling()
				}
			} catch {
				// Ignore — endpoint may not exist yet
			}
		},

		startPolling() {
			this.stopPolling()
			this.pollTimer = setInterval(async () => {
				try {
					const status = await fetchPeopleScanStatus()
					this.scanStatus = status.status || 'idle'
					this.scanTotal = status.total || 0
					this.scanProcessed = status.processed || 0

					if (this.scanStatus !== 'scanning') {
						this.stopPolling()
						await this.loadClusters()
					}
				} catch {
					this.stopPolling()
				}
			}, 3000)
		},

		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},
	},

	computed: {
		visibleReferenceCandidates() {
			if (this.showAllReferenceCandidates) {
				return this.referenceCandidates
			}

			return this.referenceCandidates.slice(0, this.referenceCandidateLimit)
		},
	},
}
</script>

<style scoped>
.people {
	padding: 20px;
	padding-bottom: 96px;
	max-width: 1200px;
	margin: 0 auto;
}

.people__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 20px;
}

.people__header h2 {
	margin: 0;
}

.people__header-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.people__scope-toggle {
	display: inline-flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	overflow: hidden;
}

.people__scope-btn {
	border: none;
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	padding: 6px 12px;
	font-size: 0.85em;
	cursor: pointer;
}

.people__scope-btn:hover {
	background: var(--color-background-hover);
}

.people__scope-btn--active {
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.people__toggle-references {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 6px 12px;
	border-radius: var(--border-radius-pill);
	cursor: pointer;
	font-size: 0.85em;
}

.people__stats {
	display: flex;
	gap: 16px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__stats strong {
	color: var(--color-main-text);
}

.people__loading,
.people__scanning,
.people__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 60px 0;
	color: var(--color-text-maxcontrast);
}

.people__empty-icon {
	font-size: 2rem;
}

.people__scan-progress {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__cli-hint {
	display: inline-block;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 8px 16px;
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 0.9em;
	user-select: all;
}

.people__cli-sub {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.people__clusters {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding-bottom: 24px;
}

.people__reference-builder {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 14px;
	background: var(--color-main-background);
}

.people__reference-builder-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.people__reference-builder-head h3 {
	margin: 0;
	font-size: 1.05em;
}

.people__reference-builder-head span {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__reference-builder-sub {
	margin: 0 0 10px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.people__reference-empty {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__reference-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 10px;
}

.people__reference-item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.people__reference-item img {
	width: 100%;
	aspect-ratio: 1 / 1;
	object-fit: cover;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.people__reference-input {
	width: 100%;
}

.people__reference-create {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 6px 10px;
	border-radius: var(--border-radius-pill);
	cursor: pointer;
}

.people__reference-create:disabled {
	opacity: 0.6;
	cursor: default;
}

.people__cluster {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 14px;
	background: var(--color-main-background);
}

.people__cluster-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.people__cluster-title {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.people__reference-hint {
	margin: 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.people__cluster-head h3 {
	margin: 0;
	font-size: 1.05em;
}

.people__cluster-head span {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.people__cluster-label {
	display: flex;
	align-items: center;
	gap: 8px;
}

.people__label-input {
	min-width: 220px;
	max-width: 320px;
}

.people__label-save {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius-pill);
	cursor: pointer;
}

.people__label-save:disabled {
	opacity: 0.6;
	cursor: default;
}

.people__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 10px;
}

.people__item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.people__item-actions {
	display: flex;
	justify-content: flex-end;
}

.people__reference-btn {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.75em;
	cursor: pointer;
}

.people__reference-btn:disabled {
	opacity: 0.6;
	cursor: default;
}

.people__reference-btn--active {
	background: var(--color-primary-element-light);
}

.people__item img {
	width: 100%;
	aspect-ratio: 1 / 1;
	object-fit: cover;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.people__meta {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.people__cluster-actions {
	display: flex;
	justify-content: center;
	margin-top: 12px;
}

.people__load-more {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 6px 14px;
	border-radius: var(--border-radius-pill);
	cursor: pointer;
}

.people__load-more:disabled {
	opacity: 0.6;
	cursor: default;
}
</style>

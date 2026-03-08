<!--
  - SPDX-FileCopyrightText: 2026 Johannes
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
<div class="locations">
<div class="locations__header">
<h2>Locations</h2>
<div class="locations__header-actions">
<div class="locations__scope-toggle">
<button type="button"
class="locations__scope-btn"
:class="{ 'locations__scope-btn--active': localScope === 'all' }"
@click="setScope('all')">
Whole drive
</button>
<button type="button"
class="locations__scope-btn"
:class="{ 'locations__scope-btn--active': localScope === 'photos' }"
@click="setScope('photos')">
Photos folder
</button>
</div>
<div v-if="!loading && !scanning" class="locations__stats">
<span><strong>{{ totalPhotosWithLocation }}</strong> geotagged photos</span>
<span><strong>{{ markers.length }}</strong> markers</span>
</div>
</div>
</div>

<!-- Scan progress bar -->
<ScanProgress v-if="scanning"
:status="scanProgress.status"
:total="scanProgress.total"
:processed="scanProgress.processed" />

<div v-if="loading && markers.length === 0 && !scanning" class="locations__loading">
<NcLoadingIcon :size="44" />
<p>Loading map markers…</p>
</div>

<div v-if="!loading && !scanning && markers.length === 0" class="locations__empty">
<div class="locations__empty-icon">📍</div>
<h3>No geotagged photos found</h3>
<p>Run the OCC GPS extraction command to extract location data from your files.</p>
<p>This only needs to be done once — new photos are scanned incrementally.</p>
</div>

<div v-if="markers.length > 0" class="locations__body">
<div ref="map" class="locations__map" />
<div class="locations__panel">
<h3>{{ selectedMarkerTitle }}</h3>
<p class="locations__coords" v-if="selectedMarker">
{{ selectedMarker.lat.toFixed(5) }}, {{ selectedMarker.lng.toFixed(5) }}
</p>
<div v-if="selectedFiles.length === 0" class="locations__panel-empty">
Select a marker to view photos from that location.
</div>
<div v-else class="locations__grid">
<div v-for="file in selectedFiles" :key="file.fileId" class="locations__item">
<img :src="previewUrl(file.fileId, 220, 220)"
:alt="file.filePath"
loading="lazy">
<div class="locations__meta" :title="file.filePath">{{ file.filePath }}</div>
</div>
</div>
</div>
</div>

<div v-if="loading && markers.length > 0" class="locations__loading-inline">
<NcLoadingIcon :size="20" />
<span>Updating markers…</span>
</div>
</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

import ScanProgress from './ScanProgress.vue'

import {
fetchLocationMarkers,
fetchLocationScanStatus,
previewUrl,
} from '../services/api.js'

import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

delete L.Icon.Default.prototype._getIconUrl
L.Icon.Default.mergeOptions({
iconRetinaUrl: markerIcon2x,
iconUrl: markerIcon,
shadowUrl: markerShadow,
})

const POLL_INTERVAL_MS = 1500

export default {
name: 'LocationsView',

components: {
NcLoadingIcon,
ScanProgress,
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
scanning: false,
scanProgress: { status: 'idle', total: 0, processed: 0 },
markers: [],
totalPhotosWithLocation: 0,
map: null,
mapContainerEl: null,
markerLayer: null,
selectedMarker: null,
selectedFiles: [],
pollTimer: null,
}
},

watch: {
scope(newScope) {
if (newScope !== this.localScope) {
this.localScope = newScope
this.loadMarkers()
}
},
},

computed: {
selectedMarkerTitle() {
if (!this.selectedMarker) {
return 'Photos at selected location'
}
return `${this.selectedMarker.count} photo(s) at marker`
},
},

async mounted() {
await this.checkScanStatus()
if (this.scanning) {
this.startPolling()
}
await this.loadMarkers()
},

beforeDestroy() {
this.stopPolling()
if (this.map) {
this.map.remove()
this.map = null
}
this.mapContainerEl = null
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
await this.loadMarkers()
},

/**
 * Load cached markers from the database (fast read).
 */
async loadMarkers() {
this.loading = true
try {
const result = await fetchLocationMarkers(this.localScope)
this.markers = result.markers || []
this.totalPhotosWithLocation = result.total_photos_with_location || 0
this.selectedMarker = this.markers[0] || null
this.selectedFiles = this.selectedMarker ? (this.selectedMarker.files || []) : []
} catch (err) {
console.error('PhotoDedup: failed to load location markers', err)
this.markers = []
this.totalPhotosWithLocation = 0
this.selectedMarker = null
this.selectedFiles = []
} finally {
this.loading = false
this.$nextTick(() => {
if (this.markers.length > 0) {
this.renderMap()
return
}

if (this.map) {
this.map.remove()
this.map = null
}
this.markerLayer = null
this.mapContainerEl = null
})
}
},

async checkScanStatus() {
try {
const status = await fetchLocationScanStatus()
this.scanProgress = status
this.scanning = status.status === 'scanning'
} catch (err) {
this.scanProgress = { status: 'idle', total: 0, processed: 0 }
this.scanning = false
}
},

startPolling() {
this.stopPolling()
this.pollTimer = setInterval(async () => {
try {
const status = await fetchLocationScanStatus()
this.scanProgress = status
this.scanning = status.status === 'scanning'

if (status.status === 'completed' || status.status === 'error' || status.status === 'idle') {
this.stopPolling()
await this.loadMarkers()
}
} catch (err) {
console.error('PhotoDedup: failed to poll location scan status', err)
}
}, POLL_INTERVAL_MS)
},

stopPolling() {
if (this.pollTimer) {
clearInterval(this.pollTimer)
this.pollTimer = null
}
},

renderMap() {
if (!this.$refs.map || this.markers.length === 0) {
return
}

const mapElement = this.$refs.map
const hasLeafletContainer = mapElement.classList.contains('leaflet-container')
const needsReinit = !this.map || this.mapContainerEl !== mapElement || !hasLeafletContainer

try {
if (needsReinit) {
if (this.map) {
this.map.remove()
}

if (Object.prototype.hasOwnProperty.call(mapElement, '_leaflet_id')) {
delete mapElement._leaflet_id
}

while (mapElement.firstChild) {
mapElement.removeChild(mapElement.firstChild)
}

mapElement.className = 'locations__map'

this.map = L.map(mapElement)
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
maxZoom: 19,
attribution: '&copy; OpenStreetMap contributors',
}).addTo(this.map)
this.markerLayer = L.layerGroup().addTo(this.map)
this.mapContainerEl = mapElement
}

if (this.markerLayer) {
this.markerLayer.clearLayers()
} else {
this.markerLayer = L.layerGroup().addTo(this.map)
}

const bounds = []
for (const marker of this.markers) {
const leaf = L.marker([marker.lat, marker.lng])
leaf.bindPopup(`${marker.count} photo(s)`)
leaf.on('click', () => {
this.selectedMarker = marker
this.selectedFiles = marker.files || []
})
leaf.addTo(this.markerLayer)
bounds.push([marker.lat, marker.lng])
}

if (bounds.length > 0) {
this.map.fitBounds(bounds, { padding: [20, 20] })
}

this.$nextTick(() => {
if (this.map) {
this.map.invalidateSize()
}
})
} catch (err) {
console.error('PhotoDedup: failed to render map markers', err)
}
},
},
}
</script>

<style scoped>
.locations {
padding: 20px;
max-width: 1200px;
margin: 0 auto;
}

.locations__header {
display: flex;
justify-content: space-between;
align-items: center;
gap: 12px;
flex-wrap: wrap;
margin-bottom: 20px;
}

.locations__header h2 {
margin: 0;
}

.locations__header-actions {
display: flex;
align-items: center;
gap: 16px;
flex-wrap: wrap;
}

.locations__scope-toggle {
display: inline-flex;
align-items: center;
border: 1px solid var(--color-border);
border-radius: var(--border-radius-pill);
overflow: hidden;
}

.locations__scope-btn {
border: none;
background: var(--color-main-background);
color: var(--color-text-maxcontrast);
padding: 6px 12px;
font-size: 0.85em;
cursor: pointer;
}

.locations__scope-btn:hover {
background: var(--color-background-hover);
}

.locations__scope-btn--active {
background: var(--color-primary-element-light);
color: var(--color-main-text);
}

.locations__stats {
display: flex;
gap: 16px;
font-size: 0.9em;
color: var(--color-text-maxcontrast);
}

.locations__stats strong {
color: var(--color-main-text);
}

.locations__loading,
.locations__empty {
display: flex;
flex-direction: column;
align-items: center;
gap: 12px;
padding: 60px 0;
color: var(--color-text-maxcontrast);
}

.locations__loading-inline {
display: inline-flex;
align-items: center;
gap: 8px;
margin-top: 10px;
color: var(--color-text-maxcontrast);
font-size: 0.9em;
}

.locations__empty-icon {
font-size: 2rem;
}

.locations__body {
display: grid;
grid-template-columns: 2fr 1fr;
gap: 14px;
}

.locations__map {
min-height: 460px;
border-radius: var(--border-radius-large);
overflow: hidden;
border: 1px solid var(--color-border);
}

.locations__panel {
border: 1px solid var(--color-border);
border-radius: var(--border-radius-large);
padding: 12px;
background: var(--color-main-background);
max-height: 460px;
overflow: auto;
}

.locations__panel h3 {
margin: 0;
font-size: 1.05em;
}

.locations__coords {
margin: 6px 0 12px;
font-size: 0.85em;
color: var(--color-text-maxcontrast);
}

.locations__panel-empty {
font-size: 0.9em;
color: var(--color-text-maxcontrast);
padding: 8px 0;
}

.locations__grid {
display: grid;
grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
gap: 10px;
}

.locations__item {
display: flex;
flex-direction: column;
gap: 6px;
}

.locations__item img {
width: 100%;
aspect-ratio: 1 / 1;
object-fit: cover;
border-radius: var(--border-radius);
border: 1px solid var(--color-border);
background: var(--color-background-dark);
}

.locations__meta {
font-size: 0.78em;
color: var(--color-text-maxcontrast);
white-space: nowrap;
overflow: hidden;
text-overflow: ellipsis;
}

@media (max-width: 1000px) {
.locations__body {
grid-template-columns: 1fr;
}

.locations__panel,
.locations__map {
max-height: none;
}
}
</style>

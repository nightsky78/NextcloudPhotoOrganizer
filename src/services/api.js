/**
 * SPDX-FileCopyrightText: 2026 Johannes
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * API client for the PhotoDedup backend.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/photodedup/api/v1'

function url(path) {
	return generateUrl(BASE + path)
}

/**
 * Fetch duplicate groups (paginated).
 * @param {number} limit
 * @param {number} offset
 * @returns {Promise<{groups: Array, total: number}>}
 */
export async function fetchDuplicates(limit = 50, offset = 0, scope = 'all') {
	const { data } = await axios.get(url('/duplicates'), { params: { limit, offset, scope } })
	return data
}

/**
 * Fetch a single duplicate group by content hash.
 * @param {string} hash SHA-256 hex string
 * @returns {Promise<Object>}
 */
export async function fetchDuplicateGroup(hash) {
	const { data } = await axios.get(url(`/duplicates/${hash}`))
	return data
}

/**
 * Trigger a full scan for the current user.
 * @param {boolean} force Re-hash even if files appear unchanged
 * @returns {Promise<{total: number, hashed: number, skipped: number, errors: number}>}
 */
export async function triggerScan(force = false) {
	const { data } = await axios.post(url('/scan'), null, {
		params: { force: force ? 'true' : 'false' },
	})
	return data
}

/**
 * Poll the current scan status.
 * @returns {Promise<{status: string, total: number, processed: number, updated_at: string}>}
 */
export async function fetchScanStatus() {
	const { data } = await axios.get(url('/scan/status'))
	return data
}

/**
 * Delete a single file by its Nextcloud file ID.
 * @param {number} fileId
 * @returns {Promise<{success: boolean, message: string}>}
 */
export async function deleteFile(fileId) {
	const { data } = await axios.delete(url(`/files/${fileId}`))
	return data
}

/**
 * Bulk-delete files by their IDs.
 * @param {number[]} fileIds
 * @returns {Promise<{deleted: number, failed: number, results: Array}>}
 */
export async function bulkDeleteFiles(fileIds) {
	const { data } = await axios.post(url('/files/bulk-delete'), { fileIds })
	return data
}

/**
 * Fetch statistics (indexed files, duplicate groups, wasted space, etc.).
 * @returns {Promise<{indexed_files: number, duplicate_groups: number, duplicate_files: number, wasted_bytes: number}>}
 */
export async function fetchStats(scope = 'all') {
	const { data } = await axios.get(url('/stats'), { params: { scope } })
	return data
}

/**
 * Build a preview URL for a file.
 * @param {number} fileId
 * @param {number} width
 * @param {number} height
 * @returns {string}
 */
export function previewUrl(fileId, width = 256, height = 256) {
	return generateUrl(`/core/preview?fileId=${fileId}&x=${width}&y=${height}&a=true`)
}

// ── Image classifier API ────────────────────────────────────────────

/**
 * Trigger image classification for the current user.
 * @param {boolean} force Re-classify even if already classified
 * @returns {Promise<{total: number, classified: number, skipped: number, errors: number}>}
 */
export async function triggerClassify(force = false) {
	const { data } = await axios.post(url('/classify'), null, {
		params: { force: force ? 'true' : 'false' },
	})
	return data
}

/**
 * Poll classification progress.
 * @returns {Promise<{status: string, total: number, processed: number}>}
 */
export async function fetchClassifyStatus() {
	const { data } = await axios.get(url('/classify/status'))
	return data
}

/**
 * Get category counts.
 * @returns {Promise<{categories: Object, total: number}>}
 */
export async function fetchCategories(scope = 'all') {
	const { data } = await axios.get(url('/classify/categories'), { params: { scope } })
	return data
}

/**
 * Get files in a specific category (paginated).
 * @param {string} category
 * @param {number} limit
 * @param {number} offset
 * @returns {Promise<{files: Array, total: number}>}
 */
export async function fetchCategoryFiles(category, limit = 50, offset = 0, scope = 'all') {
	const { data } = await axios.get(url(`/classify/category/${category}`), {
		params: { limit, offset, scope },
	})
	return data
}

/**
 * Move a classified file to a target folder.
 * @param {number} fileId
 * @param {string} targetFolder
 * @returns {Promise<{success: boolean, message: string, newPath?: string}>}
 */
export async function moveClassifiedFile(fileId, targetFolder) {
	const { data } = await axios.post(url(`/classify/move/${fileId}`), { targetFolder })
	return data
}

/**
 * Delete a classified file (move to trash).
 * @param {number} fileId
 * @returns {Promise<{success: boolean, message: string}>}
 */
export async function deleteClassifiedFile(fileId) {
	const { data } = await axios.delete(url(`/classify/files/${fileId}`))
	return data
}

// ── People & locations insights API ────────────────────────────────

/**
 * Get person clusters (face-only image groups).
 * @returns {Promise<{clusters: Array, total_clusters: number, total_face_images: number}>}
 */
export async function fetchPeopleClusters(scope = 'all') {
	const { data } = await axios.get(url('/people/clusters'), { params: { scope } })
	return data
}

/**
 * Poll people-scan progress.
 * @returns {Promise<{status: string, total: number, processed: number, updated_at: string}>}
 */
export async function fetchPeopleScanStatus() {
	const { data } = await axios.get(url('/people/scan/status'))
	return data
}

/**
 * Get cached map markers from the database.
 * @returns {Promise<{markers: Array, total_markers: number, total_photos_with_location: number}>}
 */
export async function fetchLocationMarkers(scope = 'all') {
	const { data } = await axios.get(url('/locations/markers'), { params: { scope } })
	return data
}

/**
 * Trigger a location scan — extracts GPS coordinates from EXIF metadata
 * and caches the results in the database.
 * @param {boolean} force Re-extract even if already cached
 * @returns {Promise<{total: number, scanned: number, skipped: number, with_location: number, errors: number}>}
 */
export async function triggerLocationScan(force = false) {
	const { data } = await axios.post(url('/locations/scan'), null, {
		params: { force: force ? 'true' : 'false' },
	})
	return data
}

/**
 * Poll location-scan progress.
 * @returns {Promise<{status: string, total: number, processed: number, updated_at: string}>}
 */
export async function fetchLocationScanStatus() {
	const { data } = await axios.get(url('/locations/scan/status'))
	return data
}

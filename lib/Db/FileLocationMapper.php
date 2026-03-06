<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database mapper for the pdd_file_locations table.
 *
 * @extends QBMapper<FileLocation>
 */
class FileLocationMapper extends QBMapper
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'pdd_file_locations', FileLocation::class);
    }

    // ── Single-record lookups ───────────────────────────────────────

    /**
     * Find a location record by Nextcloud file ID for a given user.
     *
     * @throws DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findByFileId(string $userId, int $fileId): FileLocation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Check whether a file has already been scanned for location data and is unchanged.
     *
     * @return bool True if the file is already in the table with the same mtime.
     */
    public function isAlreadyScanned(string $userId, int $fileId, int $currentMtime): bool
    {
        try {
            $existing = $this->findByFileId($userId, $fileId);
            return $existing->getFileMtime() === $currentMtime;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    // ── Marker queries ──────────────────────────────────────────────

    /**
     * Return all files with GPS coordinates for a user (optionally scoped).
     *
     * @return FileLocation[]
     */
    public function findWithLocation(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('has_location', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $this->applyScopeFilter($qb, $scope);

        $qb->orderBy('file_id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Count files that have GPS coordinates for a user.
     */
    public function countWithLocation(string $userId, string $scope = self::SCOPE_ALL): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('has_location', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $this->applyScopeFilter($qb, $scope);

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Total scanned files for a user (with or without location).
     */
    public function countForUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    // ── Bulk operations ─────────────────────────────────────────────

    /**
     * Delete all location records for a user.
     */
    public function deleteAllForUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }

    /**
     * Delete location record by file ID.
     */
    public function deleteByFileId(string $userId, int $fileId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Keyset pagination for cleanup / batch operations.
     *
     * @return FileLocation[]
     */
    public function findForUserAfterId(string $userId, int $afterId = 0, int $limit = 1000): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function applyScopeFilter(IQueryBuilder $qb, string $scope): void
    {
        if ($scope !== self::SCOPE_PHOTOS) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->eq('file_path', $qb->createNamedParameter('Photos')),
                $qb->expr()->like('file_path', $qb->createNamedParameter('Photos/%')),
                $qb->expr()->eq('file_path', $qb->createNamedParameter('photos')),
                $qb->expr()->like('file_path', $qb->createNamedParameter('photos/%')),
            ),
        );
    }
}

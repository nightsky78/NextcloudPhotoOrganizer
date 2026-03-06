<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database mapper for the photodedup_file_hashes table.
 *
 * @extends QBMapper<FileHash>
 */
class FileHashMapper extends QBMapper
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'photodedup_file_hashes', FileHash::class);
    }

    // ── Single-record lookups ───────────────────────────────────────

    /**
     * Find a hash record by Nextcloud file ID for a given user.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findByFileId(string $userId, int $fileId): FileHash
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    // ── Duplicate-group queries ─────────────────────────────────────

    /**
     * Return content hashes that appear more than once for the user.
     *
     * Each row: ['content_hash' => string, 'count' => int, 'total_size' => int]
     *
     * @return array<int, array{content_hash: string, count: int, total_size: int}>
     */
    public function findDuplicateHashes(string $userId, int $limit = 100, int $offset = 0, string $scope = self::SCOPE_ALL): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('content_hash')
            ->selectAlias($qb->func()->count('id'), 'count')
            ->selectAlias($qb->func()->sum('file_size'), 'total_size')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);

        $qb->groupBy('content_hash')
            ->groupBy('content_hash')
            ->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('count', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return array_map(static fn(array $row): array => [
            'content_hash' => (string) $row['content_hash'],
            'count' => (int) $row['count'],
            'total_size' => (int) $row['total_size'],
        ], $rows);
    }

    /**
     * Return the total number of distinct duplicate hashes for a user.
     */
    public function countDuplicateHashes(string $userId, string $scope = self::SCOPE_ALL): int
    {
        // Use a subquery approach for portability.
        $qb = $this->db->getQueryBuilder();
        $qb->select('content_hash')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);

        $qb->groupBy('content_hash')
            ->groupBy('content_hash')
            ->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = 0;
        while ($result->fetchOne() !== false) {
            $count++;
        }
        $result->closeCursor();

        return $count;
    }

    /**
     * Get all file-hash records sharing a given content hash.
     *
     * @return FileHash[]
     */
    public function findByContentHash(string $userId, string $contentHash, string $scope = self::SCOPE_ALL): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('content_hash', $qb->createNamedParameter($contentHash)));

        $this->applyScopeFilter($qb, $scope);

        $qb->orderBy('file_mtime', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * List indexed records for a user (paginated).
     *
     * @return FileHash[]
     */
    public function findForUser(string $userId, int $limit = 1000, int $offset = 0): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * List indexed records for a user using keyset pagination.
     *
     * @return FileHash[]
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

    // ── Bulk operations ─────────────────────────────────────────────

    /**
     * Delete all hash records for a user.
     */
    public function deleteAllForUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }

    /**
     * Delete hash record by file ID.
     */
    public function deleteByFileId(string $userId, int $fileId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    // ── Statistics ──────────────────────────────────────────────────

    /**
     * Total indexed files for a user.
     */
    public function countForUser(string $userId, string $scope = self::SCOPE_ALL): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Total duplicate files (all copies including originals) for a user.
     */
    public function countDuplicateFiles(string $userId, string $scope = self::SCOPE_ALL): int
    {
        // Portable approach: first collect duplicate hashes, then count files.
        $dupHashes = $this->findDuplicateHashes($userId, 100000, 0, $scope);
        if (empty($dupHashes)) {
            return 0;
        }

        $hashes = array_map(static fn(array $row): string => $row['content_hash'], $dupHashes);

        // Count files matching those hashes (batch in chunks to avoid parameter limits)
        $total = 0;
        foreach (array_chunk($hashes, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('id'))
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('content_hash', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));

            $this->applyScopeFilter($qb, $scope);

            $result = $qb->executeQuery();
            $total += (int) $result->fetchOne();
            $result->closeCursor();
        }

        return $total;
    }

    /**
     * Total wasted storage (duplicate bytes, excluding one copy per group).
     */
    public function totalWastedBytes(string $userId, string $scope = self::SCOPE_ALL): int
    {
        // For each duplicate group, wasted = (count - 1) * file_size.
        // We compute this with a grouped query.
        $qb = $this->db->getQueryBuilder();
        $qb->select('content_hash')
            ->selectAlias($qb->func()->count('id'), 'cnt')
            ->selectAlias('file_size', 'file_size')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);

        $qb->groupBy('content_hash', 'file_size')
            ->groupBy('content_hash', 'file_size')
            ->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $wasted = 0;
        while ($row = $result->fetch()) {
            $wasted += ((int) $row['cnt'] - 1) * (int) $row['file_size'];
        }
        $result->closeCursor();

        return $wasted;
    }

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

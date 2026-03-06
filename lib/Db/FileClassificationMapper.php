<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database mapper for the pdd_classifications table.
 *
 * @extends QBMapper<FileClassification>
 */
class FileClassificationMapper extends QBMapper
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'pdd_classifications', FileClassification::class);
    }

    // ── Single-record lookups ───────────────────────────────────────

    /**
     * Find a classification record by file ID for a given user.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findByFileId(string $userId, int $fileId): FileClassification
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    // ── Category queries ────────────────────────────────────────────

    /**
     * Count files per category for a user.
     *
     * @return array<string, int> category => count
     */
    public function countByCategory(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('category')
            ->selectAlias($qb->func()->count('id'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);

        $qb->groupBy('category')
            ->groupBy('category')
            ->orderBy('count', 'DESC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['category']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get classified files for a specific category (paginated).
     *
     * @return FileClassification[]
     */
    public function findByCategory(string $userId, string $category, int $limit = 50, int $offset = 0, string $scope = self::SCOPE_ALL): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));

        $this->applyScopeFilter($qb, $scope);

        $qb->orderBy('confidence', 'DESC')
            ->orderBy('confidence', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Count files in a specific category.
     */
    public function countByUserCategory(string $userId, string $category, string $scope = self::SCOPE_ALL): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));

        $this->applyScopeFilter($qb, $scope);

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    // ── Bulk operations ─────────────────────────────────────────────

    /**
     * Delete all classification records for a user.
     */
    public function deleteAllForUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $qb->executeStatement();
    }

    /**
     * Delete classification record by file ID.
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
     * Total classified files for a user.
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

    /**
     * List classification records for a user using keyset pagination.
     *
     * @return FileClassification[]
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
}

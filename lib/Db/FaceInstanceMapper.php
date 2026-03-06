<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FaceInstance>
 */
class FaceInstanceMapper extends QBMapper
{
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'pdd_face_instances', FaceInstance::class);
    }

    /**
     * @return FaceInstance[]
     */
    public function findWithFace(string $userId, string $scope = 'all'): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyScopeFilter($qb, $scope);
        $qb->orderBy('file_id', 'ASC')
            ->addOrderBy('face_index', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @param array<int, string> $signatures
     * @return FaceInstance[]
     */
    public function findBySignatures(string $userId, array $signatures, string $scope = 'all'): array
    {
        if ($signatures === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->in(
                    'face_signature',
                    $qb->createNamedParameter($signatures, IQueryBuilder::PARAM_STR_ARRAY),
                ),
            );

        $this->applyScopeFilter($qb, $scope);
        $qb->orderBy('face_confidence', 'DESC')
            ->addOrderBy('file_size', 'DESC')
            ->addOrderBy('file_id', 'ASC')
            ->addOrderBy('face_index', 'ASC');

        return $this->findEntities($qb);
    }

    public function deleteByFileId(string $userId, int $fileId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    public function insertFaceInstance(FaceInstance $entity): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'user_id' => $qb->createNamedParameter($entity->getUserId()),
                'file_id' => $qb->createNamedParameter($entity->getFileId(), IQueryBuilder::PARAM_INT),
                'file_path' => $qb->createNamedParameter($entity->getFilePath()),
                'file_size' => $qb->createNamedParameter($entity->getFileSize(), IQueryBuilder::PARAM_INT),
                'mime_type' => $qb->createNamedParameter($entity->getMimeType()),
                'face_index' => $qb->createNamedParameter($entity->getFaceIndex(), IQueryBuilder::PARAM_INT),
                'face_signature' => $qb->createNamedParameter($entity->getFaceSignature()),
                'face_confidence' => $qb->createNamedParameter($entity->getFaceConfidence()),
                'file_mtime' => $qb->createNamedParameter($entity->getFileMtime(), IQueryBuilder::PARAM_INT),
                'scanned_at' => $qb->createNamedParameter(
                    ($entity->getScannedAt() ?? new \DateTime())->format('Y-m-d H:i:s'),
                ),
            ]);

        $qb->executeStatement();
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

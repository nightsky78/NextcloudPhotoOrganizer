<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FaceSignatureLabel>
 */
class FaceSignatureLabelMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'pdd_face_signature_labels', FaceSignatureLabel::class);
    }

    /**
     * @return array<string, string>
     */
    public function getLabelMapForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $entities = $this->findEntities($qb);
        $map = [];
        foreach ($entities as $entity) {
            $map[$entity->getFaceSignature()] = $entity->getLabelName();
        }

        return $map;
    }

    public function setLabel(string $userId, string $faceSignature, string $labelName): void
    {
        $trimmedLabel = trim($labelName);
        if ($trimmedLabel === '') {
            $this->deleteLabel($userId, $faceSignature);
            return;
        }

        try {
            $existing = $this->findBySignature($userId, $faceSignature);
            $existing->setLabelName($trimmedLabel);
            $existing->setUpdatedAt(new DateTime());
            $this->update($existing);
        } catch (DoesNotExistException) {
            $entity = new FaceSignatureLabel();
            $entity->setUserId($userId);
            $entity->setFaceSignature($faceSignature);
            $entity->setLabelName($trimmedLabel);
            $entity->setUpdatedAt(new DateTime());
            $this->insert($entity);
        }
    }

    private function deleteLabel(string $userId, string $faceSignature): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('face_signature', $qb->createNamedParameter($faceSignature)));

        $qb->executeStatement();
    }

    /**
     * @throws DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    private function findBySignature(string $userId, string $faceSignature): FaceSignatureLabel
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('face_signature', $qb->createNamedParameter($faceSignature)));

        return $this->findEntity($qb);
    }
}

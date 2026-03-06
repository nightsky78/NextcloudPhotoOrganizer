<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string    getUserId()
 * @method void      setUserId(string $userId)
 * @method string    getFaceSignature()
 * @method void      setFaceSignature(string $faceSignature)
 * @method string    getLabelName()
 * @method void      setLabelName(string $labelName)
 * @method \DateTime getUpdatedAt()
 * @method void      setUpdatedAt(\DateTime $updatedAt)
 */
class FaceSignatureLabel extends Entity implements JsonSerializable
{
    protected string $userId = '';
    protected string $faceSignature = '';
    protected string $labelName = '';
    protected ?\DateTime $updatedAt = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('faceSignature', 'string');
        $this->addType('labelName', 'string');
        $this->addType('updatedAt', 'datetime');
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'faceSignature' => $this->faceSignature,
            'labelName' => $this->labelName,
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}

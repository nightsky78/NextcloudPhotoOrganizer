<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a classified image file.
 *
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getFileId()
 * @method void   setFileId(int $fileId)
 * @method string getFilePath()
 * @method void   setFilePath(string $filePath)
 * @method int    getFileSize()
 * @method void   setFileSize(int $fileSize)
 * @method string getMimeType()
 * @method void   setMimeType(string $mimeType)
 * @method string getCategory()
 * @method void   setCategory(string $category)
 * @method float  getConfidence()
 * @method void   setConfidence(float $confidence)
 * @method string|null getIndicators()
 * @method void   setIndicators(?string $indicators)
 * @method \DateTime getClassifiedAt()
 * @method void      setClassifiedAt(\DateTime $classifiedAt)
 */
class FileClassification extends Entity implements JsonSerializable
{
    protected string $userId = '';
    protected int $fileId = 0;
    protected string $filePath = '';
    protected int $fileSize = 0;
    protected string $mimeType = '';
    protected string $category = '';
    protected float $confidence = 0.0;
    protected ?string $indicators = null;
    protected ?\DateTime $classifiedAt = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('fileId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileSize', 'integer');
        $this->addType('mimeType', 'string');
        $this->addType('category', 'string');
        $this->addType('confidence', 'float');
        $this->addType('indicators', 'string');
        $this->addType('classifiedAt', 'datetime');
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'fileId' => $this->fileId,
            'filePath' => $this->filePath,
            'fileSize' => $this->fileSize,
            'mimeType' => $this->mimeType,
            'category' => $this->category,
            'confidence' => round($this->confidence, 3),
            'indicators' => $this->indicators !== null ? json_decode($this->indicators, true) : [],
            'classifiedAt' => $this->classifiedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}

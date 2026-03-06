<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a hashed image file in the deduplication index.
 *
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getFileId()
 * @method void   setFileId(int $fileId)
 * @method string getFilePath()
 * @method void   setFilePath(string $filePath)
 * @method int    getFileSize()
 * @method void   setFileSize(int $fileSize)
 * @method string getContentHash()
 * @method void   setContentHash(string $contentHash)
 * @method string getMimeType()
 * @method void   setMimeType(string $mimeType)
 * @method int    getFileMtime()
 * @method void   setFileMtime(int $fileMtime)
 * @method \DateTime getScannedAt()
 * @method void      setScannedAt(\DateTime $scannedAt)
 */
class FileHash extends Entity implements JsonSerializable
{
    protected string $userId = '';
    protected int $fileId = 0;
    protected string $filePath = '';
    protected int $fileSize = 0;
    protected string $contentHash = '';
    protected string $mimeType = '';
    protected int $fileMtime = 0;
    protected ?\DateTime $scannedAt = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('fileId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileSize', 'integer');
        $this->addType('contentHash', 'string');
        $this->addType('mimeType', 'string');
        $this->addType('fileMtime', 'integer');
        $this->addType('scannedAt', 'datetime');
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'fileId' => $this->fileId,
            'filePath' => $this->filePath,
            'fileSize' => $this->fileSize,
            'contentHash' => $this->contentHash,
            'mimeType' => $this->mimeType,
            'fileMtime' => $this->fileMtime,
            'scannedAt' => $this->scannedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}

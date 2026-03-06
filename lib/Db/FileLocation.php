<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents cached GPS location data extracted from an image file.
 *
 * Files without GPS coordinates are also stored (hasLocation = false) so
 * they are not re-scanned on subsequent runs.
 *
 * @method string    getUserId()
 * @method void      setUserId(string $userId)
 * @method int       getFileId()
 * @method void      setFileId(int $fileId)
 * @method string    getFilePath()
 * @method void      setFilePath(string $filePath)
 * @method int       getFileSize()
 * @method void      setFileSize(int $fileSize)
 * @method string    getMimeType()
 * @method void      setMimeType(string $mimeType)
 * @method bool      getHasLocation()
 * @method void      setHasLocation(bool $hasLocation)
 * @method float|null getLat()
 * @method void      setLat(?float $lat)
 * @method float|null getLng()
 * @method void      setLng(?float $lng)
 * @method int       getFileMtime()
 * @method void      setFileMtime(int $fileMtime)
 * @method \DateTime getScannedAt()
 * @method void      setScannedAt(\DateTime $scannedAt)
 */
class FileLocation extends Entity implements JsonSerializable
{
    protected string $userId = '';
    protected int $fileId = 0;
    protected string $filePath = '';
    protected int $fileSize = 0;
    protected string $mimeType = '';
    protected bool $hasLocation = false;
    protected ?float $lat = null;
    protected ?float $lng = null;
    protected int $fileMtime = 0;
    protected ?\DateTime $scannedAt = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('fileId', 'integer');
        $this->addType('filePath', 'string');
        $this->addType('fileSize', 'integer');
        $this->addType('mimeType', 'string');
        $this->addType('hasLocation', 'boolean');
        $this->addType('lat', 'float');
        $this->addType('lng', 'float');
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
            'mimeType' => $this->mimeType,
            'hasLocation' => $this->hasLocation,
            'lat' => $this->lat !== null ? round($this->lat, 6) : null,
            'lng' => $this->lng !== null ? round($this->lng, 6) : null,
            'fileMtime' => $this->fileMtime,
            'scannedAt' => $this->scannedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}

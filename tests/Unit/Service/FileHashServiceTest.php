<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Tests\Unit\Service;

use OCA\PhotoDedup\Db\FileHash;
use OCA\PhotoDedup\Db\FileHashMapper;
use OCA\PhotoDedup\Service\FileHashService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileHashServiceTest extends TestCase
{
    private FileHashMapper&MockObject $mapper;
    private LoggerInterface&MockObject $logger;
    private FileHashService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(FileHashMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new FileHashService($this->mapper, $this->logger);
    }

    public function testGetByFileIdReturnsEntityWhenFound(): void
    {
        $entity = new FileHash();
        $entity->setUserId('alice');
        $entity->setFileId(42);

        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->with('alice', 42)
            ->willReturn($entity);

        $result = $this->service->getByFileId('alice', 42);

        $this->assertNotNull($result);
        $this->assertSame(42, $result->getFileId());
    }

    public function testGetByFileIdReturnsNullWhenNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->service->getByFileId('alice', 999);

        $this->assertNull($result);
    }

    public function testIsFileChangedReturnsTrueForNewFile(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willThrowException(new DoesNotExistException(''));

        $this->assertTrue($this->service->isFileChanged('alice', 1, 1000, 500));
    }

    public function testIsFileChangedReturnsFalseForUnchangedFile(): void
    {
        $entity = new FileHash();
        $entity->setFileMtime(1000);
        $entity->setFileSize(500);

        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willReturn($entity);

        $this->assertFalse($this->service->isFileChanged('alice', 1, 1000, 500));
    }

    public function testIsFileChangedReturnsTrueWhenMtimeDiffers(): void
    {
        $entity = new FileHash();
        $entity->setFileMtime(999);
        $entity->setFileSize(500);

        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willReturn($entity);

        $this->assertTrue($this->service->isFileChanged('alice', 1, 1000, 500));
    }

    public function testIsFileChangedReturnsTrueWhenSizeDiffers(): void
    {
        $entity = new FileHash();
        $entity->setFileMtime(1000);
        $entity->setFileSize(400);

        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willReturn($entity);

        $this->assertTrue($this->service->isFileChanged('alice', 1, 1000, 500));
    }

    public function testUpsertCreatesNewEntityWhenNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willThrowException(new DoesNotExistException(''));

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (FileHash $entity) {
                $this->assertSame('alice', $entity->getUserId());
                $this->assertSame(42, $entity->getFileId());
                $this->assertSame('abc123', $entity->getContentHash());
                return $entity;
            });

        $result = $this->service->upsert('alice', 42, '/photos/img.jpg', 1024, 'abc123', 'image/jpeg', 1000);

        $this->assertNotNull($result);
    }

    public function testUpsertUpdatesExistingEntity(): void
    {
        $existing = new FileHash();
        $existing->setUserId('alice');
        $existing->setFileId(42);
        $existing->setContentHash('old_hash');

        $this->mapper->expects($this->once())
            ->method('findByFileId')
            ->willReturn($existing);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (FileHash $entity) {
                $this->assertSame('new_hash', $entity->getContentHash());
                return $entity;
            });

        $result = $this->service->upsert('alice', 42, '/photos/img.jpg', 2048, 'new_hash', 'image/jpeg', 2000);

        $this->assertNotNull($result);
    }

    public function testDeleteByFileIdDelegatesToMapper(): void
    {
        $this->mapper->expects($this->once())
            ->method('deleteByFileId')
            ->with('alice', 42);

        $this->service->deleteByFileId('alice', 42);
    }
}

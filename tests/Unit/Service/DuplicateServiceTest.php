<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Tests\Unit\Service;

use OCA\PhotoDedup\Db\FileHash;
use OCA\PhotoDedup\Db\FileHashMapper;
use OCA\PhotoDedup\Service\DuplicateService;
use OCA\PhotoDedup\Service\FileHashService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DuplicateServiceTest extends TestCase
{
    private FileHashMapper&MockObject $mapper;
    private FileHashService&MockObject $fileHashService;
    private IRootFolder&MockObject $rootFolder;
    private LoggerInterface&MockObject $logger;
    private DuplicateService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(FileHashMapper::class);
        $this->fileHashService = $this->createMock(FileHashService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DuplicateService(
            $this->mapper,
            $this->fileHashService,
            $this->rootFolder,
            $this->logger,
        );
    }

    public function testGetDuplicateGroupRejectsInvalidHash(): void
    {
        $result = $this->service->getDuplicateGroup('alice', 'not-a-valid-hash');
        $this->assertNull($result);
    }

    public function testGetDuplicateGroupReturnsNullForSingleFile(): void
    {
        $hash = str_repeat('a', 64);
        $file = new FileHash();
        $file->setContentHash($hash);

        $this->mapper->expects($this->once())
            ->method('findByContentHash')
            ->with('alice', $hash, 'all')
            ->willReturn([$file]);

        $result = $this->service->getDuplicateGroup('alice', $hash);
        $this->assertNull($result);
    }

    public function testGetDuplicateGroupReturnsDuplicates(): void
    {
        $hash = str_repeat('a', 64);

        $file1 = new FileHash();
        $file1->setUserId('alice');
        $file1->setFileId(1);
        $file1->setFilePath('/photos/a.jpg');
        $file1->setFileSize(1000);
        $file1->setContentHash($hash);
        $file1->setMimeType('image/jpeg');
        $file1->setFileMtime(100);

        $file2 = new FileHash();
        $file2->setUserId('alice');
        $file2->setFileId(2);
        $file2->setFilePath('/photos/b.jpg');
        $file2->setFileSize(1000);
        $file2->setContentHash($hash);
        $file2->setMimeType('image/jpeg');
        $file2->setFileMtime(200);

        $this->mapper->expects($this->once())
            ->method('findByContentHash')
            ->with('alice', $hash, 'all')
            ->willReturn([$file1, $file2]);

        $result = $this->service->getDuplicateGroup('alice', $hash);

        $this->assertNotNull($result);
        $this->assertSame($hash, $result['contentHash']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(2000, $result['totalSize']);
        $this->assertCount(2, $result['files']);
    }

    public function testDeleteFileRefusesLastCopy(): void
    {
        $hash = str_repeat('b', 64);

        $record = new FileHash();
        $record->setFileId(1);
        $record->setContentHash($hash);

        $this->fileHashService->expects($this->once())
            ->method('getByFileId')
            ->with('alice', 1)
            ->willReturn($record);

        $this->fileHashService->expects($this->once())
            ->method('getByContentHash')
            ->with('alice', $hash)
            ->willReturn([$record]); // Only one copy

        $result = $this->service->deleteFile('alice', 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('last remaining copy', $result['message']);
    }

    public function testDeleteFileSucceedsWhenMultipleCopiesExist(): void
    {
        $hash = str_repeat('c', 64);

        $record = new FileHash();
        $record->setFileId(1);
        $record->setContentHash($hash);

        $sibling = new FileHash();
        $sibling->setFileId(2);
        $sibling->setContentHash($hash);

        $this->fileHashService->method('getByFileId')
            ->with('alice', 1)
            ->willReturn($record);

        $this->fileHashService->method('getByContentHash')
            ->with('alice', $hash)
            ->willReturn([$record, $sibling]);

        // Mock Nextcloud file access
        $file = $this->createMock(File::class);
        $file->method('getPath')->willReturn('/alice/files/photos/a.jpg');
        $file->method('isDeletable')->willReturn(true);
        $file->expects($this->once())->method('delete');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->with(1)->willReturn([$file]);

        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $this->fileHashService->expects($this->once())
            ->method('deleteByFileId')
            ->with('alice', 1);

        $result = $this->service->deleteFile('alice', 1);

        $this->assertTrue($result['success']);
    }

    public function testDeleteFileReturnsNotFoundForUnknownFile(): void
    {
        $this->fileHashService->method('getByFileId')
            ->willReturn(null);

        $result = $this->service->deleteFile('alice', 999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    public function testGetStatsReturnsCorrectStructure(): void
    {
        $this->mapper->method('countForUser')->willReturn(100);
        $this->mapper->method('countDuplicateHashes')->willReturn(5);
        $this->mapper->method('countDuplicateFiles')->willReturn(15);
        $this->mapper->method('totalWastedBytes')->willReturn(50000);

        $stats = $this->service->getStats('alice');

        $this->assertSame(100, $stats['indexed_files']);
        $this->assertSame(5, $stats['duplicate_groups']);
        $this->assertSame(15, $stats['duplicate_files']);
        $this->assertSame(50000, $stats['wasted_bytes']);
    }
}

<?php

declare(strict_types=1);

namespace OCA\PhotoDedup\Tests\Unit\Service;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\FileHashService;
use OCA\PhotoDedup\Service\ScannerService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScannerServiceTest extends TestCase
{
    private IRootFolder&MockObject $rootFolder;
    private FileHashService&MockObject $fileHashService;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private ScannerService $service;

    protected function setUp(): void
    {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->fileHashService = $this->createMock(FileHashService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ScannerService(
            $this->rootFolder,
            $this->fileHashService,
            $this->config,
            $this->logger,
        );
    }

    public function testGetProgressReturnsIdleWhenMissing(): void
    {
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', Application::APP_ID, 'scan_progress', '')
            ->willReturn('');

        $progress = $this->service->getProgress('alice');

        $this->assertSame('idle', $progress['status']);
        $this->assertSame(0, $progress['total']);
        $this->assertSame(0, $progress['processed']);
        $this->assertSame('', $progress['updated_at']);
    }

    public function testGetProgressReturnsIdleWhenCorruptJson(): void
    {
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->willReturn('{bad-json');

        $progress = $this->service->getProgress('alice');

        $this->assertSame('idle', $progress['status']);
        $this->assertSame(0, $progress['total']);
        $this->assertSame(0, $progress['processed']);
    }

    public function testProcessFileSkipsZeroSizedFiles(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getSize')->willReturn(0);

        $this->fileHashService->expects($this->never())
            ->method('isFileChanged');

        $this->assertFalse($this->service->processFile('alice', $file, false));
    }

    public function testProcessFileSkipsUnchangedFilesWhenNotForced(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(8);
        $file->method('getMTime')->willReturn(1000);
        $file->method('getSize')->willReturn(2048);

        $this->fileHashService->expects($this->once())
            ->method('isFileChanged')
            ->with('alice', 8, 1000, 2048)
            ->willReturn(false);

        $this->fileHashService->expects($this->never())
            ->method('upsert');

        $this->assertFalse($this->service->processFile('alice', $file, false));
    }

    public function testProcessFileHashesAndUpsertsWhenChanged(): void
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'abc');
        rewind($stream);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(9);
        $file->method('getMTime')->willReturn(2000);
        $file->method('getSize')->willReturn(3);
        $file->method('fopen')->with('r')->willReturn($stream);
        $file->method('getPath')->willReturn('/alice/files/Photos/image.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');

        $this->fileHashService->expects($this->once())
            ->method('isFileChanged')
            ->with('alice', 9, 2000, 3)
            ->willReturn(true);

        $this->fileHashService->expects($this->once())
            ->method('upsert')
            ->with(
                'alice',
                9,
                'Photos/image.jpg',
                3,
                hash('sha256', 'abc'),
                'image/jpeg',
                2000,
            );

        $this->assertTrue($this->service->processFile('alice', $file, false));
    }

    public function testScanUserHandlesMissingUserFolder(): void
    {
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('alice')
            ->willThrowException(new NotFoundException());

        $this->config->expects($this->exactly(2))
            ->method('setUserValue')
            ->with(
                'alice',
                Application::APP_ID,
                'scan_progress',
                $this->callback(static fn(string $json): bool => str_contains($json, '"status":"scanning"') || str_contains($json, '"status":"error"')),
            );

        $result = $this->service->scanUser('alice');

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['hashed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['errors']);
    }
}

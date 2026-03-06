<?php

declare(strict_types=1);

namespace OCA\PhotoDedup\Tests\Unit\Controller;

use OCA\PhotoDedup\Controller\DuplicateApiController;
use OCA\PhotoDedup\Service\DuplicateService;
use OCA\PhotoDedup\Service\ScannerService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DuplicateApiControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private DuplicateService&MockObject $duplicateService;
    private ScannerService&MockObject $scannerService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->duplicateService = $this->createMock(DuplicateService::class);
        $this->scannerService = $this->createMock(ScannerService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testIndexClampsPaginationAndNormalizesScope(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return match ($key) {
                'limit' => '999',
                'offset' => '-20',
                'scope' => 'invalid',
                default => $default,
            };
        });

        $expected = ['groups' => [], 'total' => 0];

        $this->duplicateService->expects($this->once())
            ->method('getDuplicateGroups')
            ->with('alice', 200, 0, 'all')
            ->willReturn($expected);

        $response = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($expected, $response->getData());
    }

    public function testShowReturnsBadRequestForInvalidHash(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $this->duplicateService->expects($this->never())
            ->method('getDuplicateGroup');

        $response = $controller->show('invalid-hash');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Invalid hash format.'], $response->getData());
    }

    public function testShowReturnsNotFoundWhenGroupMissing(): void
    {
        $hash = str_repeat('a', 64);
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $this->duplicateService->expects($this->once())
            ->method('getDuplicateGroup')
            ->with('alice', $hash, 'all')
            ->willReturn(null);

        $response = $controller->show($hash);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'Duplicate group not found.'], $response->getData());
    }

    public function testScanReturnsConflictWhenAlreadyRunning(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $progress = ['status' => 'scanning', 'total' => 10, 'processed' => 3, 'updated_at' => 'x'];

        $this->scannerService->expects($this->once())
            ->method('getProgress')
            ->with('alice')
            ->willReturn($progress);

        $this->scannerService->expects($this->never())
            ->method('scanUser');

        $response = $controller->scan();

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame([
            'error' => 'A scan is already in progress.',
            'progress' => $progress,
        ], $response->getData());
    }

    public function testScanPassesForceFlagToScannerService(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return match ($key) {
                'force' => 'true',
                default => $default,
            };
        });

        $result = ['total' => 3, 'hashed' => 2, 'skipped' => 1, 'errors' => 0];

        $this->scannerService->expects($this->once())
            ->method('getProgress')
            ->with('alice')
            ->willReturn(['status' => 'idle', 'total' => 0, 'processed' => 0, 'updated_at' => '']);

        $this->scannerService->expects($this->once())
            ->method('scanUser')
            ->with('alice', true)
            ->willReturn($result);

        $response = $controller->scan();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testDeleteFileRejectsInvalidFileId(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $response = $controller->deleteFile(0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Invalid file ID.'], $response->getData());
    }

    public function testBulkDeleteRejectsTooManyIds(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return $key === 'fileIds' ? range(1, 501) : $default;
        });

        $response = $controller->bulkDelete();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Too many files. Maximum 500 per request.'], $response->getData());
    }

    public function testBulkDeleteSanitizesIdsAndDelegates(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return $key === 'fileIds' ? ['1', 2, '3'] : $default;
        });

        $result = [
            'deleted' => 3,
            'failed' => 0,
            'results' => [
                ['fileId' => 1, 'success' => true, 'message' => 'ok'],
            ],
        ];

        $this->duplicateService->expects($this->once())
            ->method('bulkDelete')
            ->with('alice', [1, 2, 3])
            ->willReturn($result);

        $response = $controller->bulkDelete();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    private function createControllerWithRequestCallback(callable $requestCallback): DuplicateApiController
    {
        $this->request->method('getParam')
            ->willReturnCallback($requestCallback);

        return new DuplicateApiController(
            $this->request,
            $this->duplicateService,
            $this->scannerService,
            $this->logger,
            'alice',
        );
    }
}

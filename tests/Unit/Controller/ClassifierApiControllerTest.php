<?php

declare(strict_types=1);

namespace OCA\PhotoDedup\Tests\Unit\Controller;

use OCA\PhotoDedup\Controller\ClassifierApiController;
use OCA\PhotoDedup\Service\ClassifierService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClassifierApiControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private ClassifierService&MockObject $classifierService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->classifierService = $this->createMock(ClassifierService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testClassifyReturnsConflictWhenAlreadyRunning(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $progress = ['status' => 'classifying', 'total' => 25, 'processed' => 4, 'updated_at' => 'x'];
        $this->classifierService->expects($this->once())
            ->method('getProgress')
            ->with('alice')
            ->willReturn($progress);

        $response = $controller->classify();

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame([
            'error' => 'Classification already in progress.',
            'progress' => $progress,
        ], $response->getData());
    }

    public function testClassifyPassesForceFlag(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return $key === 'force' ? 'true' : $default;
        });

        $result = ['total' => 2, 'classified' => 2, 'skipped' => 0, 'errors' => 0];

        $this->classifierService->expects($this->once())
            ->method('getProgress')
            ->with('alice')
            ->willReturn(['status' => 'idle', 'total' => 0, 'processed' => 0, 'updated_at' => '']);

        $this->classifierService->expects($this->once())
            ->method('classifyUser')
            ->with('alice', true)
            ->willReturn($result);

        $response = $controller->classify();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testCategoryFilesRejectsInvalidCategory(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $response = $controller->categoryFiles('invalid');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertIsArray($response->getData());
        $this->assertStringContainsString('Invalid category', (string) $response->getData()['error']);
    }

    public function testCategoryFilesClampsPaginationAndUsesScope(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return match ($key) {
                'limit' => '1000',
                'offset' => '-1',
                'scope' => 'photos',
                default => $default,
            };
        });

        $result = ['files' => [], 'total' => 0];

        $this->classifierService->expects($this->once())
            ->method('getFilesByCategory')
            ->with('alice', 'nature', 200, 0, 'photos')
            ->willReturn($result);

        $response = $controller->categoryFiles('nature');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testMoveFileRejectsPathTraversal(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return $key === 'targetFolder' ? '../etc' : $default;
        });

        $response = $controller->moveFile(10);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Invalid target folder path.'], $response->getData());
    }

    public function testMoveFileReturnsConflictOnServiceFailure(): void
    {
        $controller = $this->createControllerWithRequestCallback(function (string $key, mixed $default): mixed {
            return $key === 'targetFolder' ? 'Photos/Nature' : $default;
        });

        $serviceResult = ['success' => false, 'message' => 'Destination exists'];
        $this->classifierService->expects($this->once())
            ->method('moveFile')
            ->with('alice', 11, 'Photos/Nature')
            ->willReturn($serviceResult);

        $response = $controller->moveFile(11);

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame($serviceResult, $response->getData());
    }

    public function testDeleteClassifiedFileRejectsInvalidFileId(): void
    {
        $controller = $this->createControllerWithRequestCallback(static fn(string $key, mixed $default): mixed => $default);

        $response = $controller->deleteClassifiedFile(-1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Invalid file ID.'], $response->getData());
    }

    private function createControllerWithRequestCallback(callable $requestCallback): ClassifierApiController
    {
        $this->request->method('getParam')
            ->willReturnCallback($requestCallback);

        return new ClassifierApiController(
            $this->request,
            $this->classifierService,
            $this->logger,
            'alice',
        );
    }
}

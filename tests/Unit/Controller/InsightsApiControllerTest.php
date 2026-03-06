<?php

declare(strict_types=1);

namespace OCA\PhotoDedup\Tests\Unit\Controller;

use OCA\PhotoDedup\Controller\InsightsApiController;
use OCA\PhotoDedup\Service\PeopleLocationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InsightsApiControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private IUserSession&MockObject $userSession;
    private PeopleLocationService&MockObject $peopleLocationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->peopleLocationService = $this->createMock(PeopleLocationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // ── peopleClusters ──────────────────────────────────────────────

    public function testPeopleClustersReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->peopleClusters('all');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Not authenticated'], $response->getData());
    }

    public function testPeopleClustersDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $this->userSession->method('getUser')->willReturn($user);
        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);

        $result = ['clusters' => [], 'total_clusters' => 0, 'total_face_images' => 0];
        $this->peopleLocationService->expects($this->once())
            ->method('getPeopleClusters')
            ->with('alice', 'photos', 10, 50)
            ->willReturn($result);

        $controller = $this->createController();
        $response = $controller->peopleClusters('photos');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    // ── peopleScanStatus ────────────────────────────────────────────

    public function testPeopleScanStatusReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->peopleScanStatus();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testPeopleScanStatusDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $progress = ['status' => 'scanning', 'total' => 200, 'processed' => 80, 'updated_at' => '2026-03-07T10:00:00+00:00'];
        $this->peopleLocationService->expects($this->once())
            ->method('getPeopleScanProgress')
            ->with('alice')
            ->willReturn($progress);

        $controller = $this->createController();
        $response = $controller->peopleScanStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($progress, $response->getData());
    }

    // ── peopleClusterFiles ─────────────────────────────────────────

    public function testPeopleClusterFilesReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->peopleClusterFiles();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testPeopleClusterFilesReturnsBadRequestWhenPersonAndSignaturesMissing(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'signatures' => [],
                default => $default,
            });

        $controller = $this->createController();
        $response = $controller->peopleClusterFiles();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Missing person or signatures'], $response->getData());
    }

    public function testPeopleClusterFilesDelegatesToServiceByPerson(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'scope' => 'photos',
                'offset' => 100,
                'limit' => 50,
                'person' => 'alice-person',
                default => $default,
            });

        $result = [
            'files' => [
                ['fileId' => 2, 'filePath' => 'Photos/b.jpg', 'mimeType' => 'image/jpeg', 'fileSize' => 1100, 'faceConfidence' => 0.93],
            ],
            'total' => 120,
            'offset' => 100,
            'limit' => 50,
            'has_more' => false,
            'next_offset' => 120,
        ];

        $this->peopleLocationService->expects($this->once())
            ->method('getPeopleClusterFilesByPerson')
            ->with('alice', 'alice-person', 'photos', 100, 50)
            ->willReturn($result);

        $controller = $this->createController();
        $response = $controller->peopleClusterFiles();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    public function testPeopleClusterFilesDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'scope' => 'photos',
                'offset' => 50,
                'limit' => 50,
                'signatures' => ['emb:v1:a', 'emb:v1:b'],
                default => $default,
            });

        $result = [
            'files' => [
                ['fileId' => 1, 'filePath' => 'Photos/a.jpg', 'mimeType' => 'image/jpeg', 'fileSize' => 1000, 'faceConfidence' => 0.91],
            ],
            'total' => 75,
            'offset' => 50,
            'limit' => 50,
            'has_more' => false,
            'next_offset' => 75,
        ];

        $this->peopleLocationService->expects($this->once())
            ->method('getPeopleClusterFiles')
            ->with('alice', ['emb:v1:a', 'emb:v1:b'], 'photos', 50, 50)
            ->willReturn($result);

        $controller = $this->createController();
        $response = $controller->peopleClusterFiles();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    // ── setPeopleLabel ─────────────────────────────────────────────

    public function testSetPeopleLabelReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->setPeopleLabel();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testSetPeopleLabelReturnsBadRequestWhenMissingSignature(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'signature' => '',
                'label' => 'Alice',
                default => $default,
            });

        $controller = $this->createController();
        $response = $controller->setPeopleLabel();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'Missing signature'], $response->getData());
    }

    public function testSetPeopleLabelDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'signature' => 'emb:v1:abc',
                'label' => 'Alice',
                default => $default,
            });

        $this->peopleLocationService->expects($this->once())
            ->method('setFaceSignatureLabel')
            ->with('alice', 'emb:v1:abc', 'Alice');

        $controller = $this->createController();
        $response = $controller->setPeopleLabel();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['success' => true], $response->getData());
    }

    // ── locationMarkers ─────────────────────────────────────────────

    public function testLocationMarkersReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->locationMarkers('all');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Not authenticated'], $response->getData());
    }

    public function testLocationMarkersDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $this->userSession->method('getUser')->willReturn($user);

        $result = ['markers' => [], 'total_markers' => 0, 'total_photos_with_location' => 0];
        $this->peopleLocationService->expects($this->once())
            ->method('getLocationMarkers')
            ->with('alice', 'all')
            ->willReturn($result);

        $controller = $this->createController();
        $response = $controller->locationMarkers('all');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }

    // ── locationScan ────────────────────────────────────────────────

    public function testLocationScanReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->locationScan();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testLocationScanReturnsConflictWhenAlreadyRunning(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->peopleLocationService->expects($this->once())
            ->method('getLocationScanProgress')
            ->with('alice')
            ->willReturn([
                'status' => 'scanning',
                'total' => 100,
                'processed' => 50,
                'updated_at' => '2026-03-06T10:00:00+00:00',
            ]);

        $controller = $this->createController();
        $response = $controller->locationScan();

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
    }

    public function testLocationScanDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->request->method('getParam')
            ->with('force', 'false')
            ->willReturn('false');

        $this->peopleLocationService->expects($this->once())
            ->method('getLocationScanProgress')
            ->with('alice')
            ->willReturn(['status' => 'idle', 'total' => 0, 'processed' => 0, 'updated_at' => '']);

        $scanResult = ['total' => 50, 'scanned' => 30, 'skipped' => 20, 'with_location' => 10, 'errors' => 0];
        $this->peopleLocationService->expects($this->once())
            ->method('scanLocationData')
            ->with('alice', false)
            ->willReturn($scanResult);

        $controller = $this->createController();
        $response = $controller->locationScan();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($scanResult, $response->getData());
    }

    // ── locationScanStatus ──────────────────────────────────────────

    public function testLocationScanStatusReturnsUnauthorizedWhenNoUser(): void
    {
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $controller = $this->createController();
        $response = $controller->locationScanStatus();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testLocationScanStatusDelegatesToService(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $progress = ['status' => 'scanning', 'total' => 100, 'processed' => 42, 'updated_at' => '2026-03-06T10:00:00+00:00'];
        $this->peopleLocationService->expects($this->once())
            ->method('getLocationScanProgress')
            ->with('alice')
            ->willReturn($progress);

        $controller = $this->createController();
        $response = $controller->locationScanStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($progress, $response->getData());
    }

    // ── Helper ──────────────────────────────────────────────────────

    private function createController(): InsightsApiController
    {
        return new InsightsApiController(
            $this->request,
            $this->userSession,
            $this->peopleLocationService,
            $this->logger,
        );
    }
}

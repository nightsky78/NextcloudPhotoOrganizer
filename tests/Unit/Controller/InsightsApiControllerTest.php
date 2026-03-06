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

        $result = ['clusters' => [], 'total_clusters' => 0, 'total_face_images' => 0];
        $this->peopleLocationService->expects($this->once())
            ->method('getPeopleClusters')
            ->with('alice', 'photos')
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

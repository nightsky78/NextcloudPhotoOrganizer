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

class InsightsApiControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private IUserSession&MockObject $userSession;
    private PeopleLocationService&MockObject $peopleLocationService;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->peopleLocationService = $this->createMock(PeopleLocationService::class);
    }

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

    private function createController(): InsightsApiController
    {
        return new InsightsApiController(
            'photodedup',
            $this->request,
            $this->userSession,
            $this->peopleLocationService,
        );
    }
}

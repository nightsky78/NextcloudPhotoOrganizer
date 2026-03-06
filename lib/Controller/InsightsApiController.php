<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Controller;

use OCA\PhotoDedup\Service\PeopleLocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;

class InsightsApiController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly PeopleLocationService $peopleLocationService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function peopleClusters(string $scope = 'all'): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $result = $this->peopleLocationService->getPeopleClusters($user->getUID(), $scope);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function locationMarkers(string $scope = 'all'): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $result = $this->peopleLocationService->getLocationMarkers($user->getUID(), $scope);
        return new DataResponse($result);
    }
}

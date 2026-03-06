<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Controller;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\PeopleLocationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class InsightsApiController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly PeopleLocationService $peopleLocationService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
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

        $clusterLimit = max(1, min(50, (int) $this->request->getParam('clusterLimit', 10)));
        $fileLimit = max(1, min(200, (int) $this->request->getParam('fileLimit', 50)));

        $result = $this->peopleLocationService->getPeopleClusters($user->getUID(), $scope, $clusterLimit, $fileLimit);
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     */
    public function peopleClusterFiles(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $scope = trim((string) $this->request->getParam('scope', 'all'));
        $offset = max(0, (int) $this->request->getParam('offset', 0));
        $limit = max(1, min(200, (int) $this->request->getParam('limit', 50)));
        $person = trim((string) $this->request->getParam('person', ''));

        if ($person !== '') {
            $result = $this->peopleLocationService->getPeopleClusterFilesByPerson(
                $user->getUID(),
                $person,
                $scope,
                $offset,
                $limit,
            );

            return new JSONResponse($result);
        }

        $rawSignatures = $this->request->getParam('signatures', []);

        if (!is_array($rawSignatures)) {
            return new JSONResponse(['error' => 'Invalid signatures payload'], Http::STATUS_BAD_REQUEST);
        }

        $signatures = [];
        foreach ($rawSignatures as $signature) {
            $trimmed = trim((string) $signature);
            if ($trimmed === '') {
                continue;
            }

            $signatures[] = $trimmed;
        }

        if ($signatures === []) {
            return new JSONResponse(['error' => 'Missing person or signatures'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->peopleLocationService->getPeopleClusterFiles(
            $user->getUID(),
            $signatures,
            $scope,
            $offset,
            $limit,
        );

        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     *
     * Poll people-scan progress.
     */
    public function peopleScanStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            $this->peopleLocationService->getPeopleScanProgress($user->getUID()),
        );
    }

    /**
     * @NoAdminRequired
     */
    public function setPeopleLabel(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $signature = trim((string) $this->request->getParam('signature', ''));
        if ($signature === '') {
            return new JSONResponse(['error' => 'Missing signature'], Http::STATUS_BAD_REQUEST);
        }

        $label = trim((string) $this->request->getParam('label', ''));
        $this->peopleLocationService->setFaceSignatureLabel($user->getUID(), $signature, $label);

        return new JSONResponse(['success' => true]);
    }

    /**
     * @NoAdminRequired
     *
     * Returns cached location markers from the database (fast).
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

    /**
     * @NoAdminRequired
     *
     * Trigger a location scan — extracts GPS coordinates from image EXIF and
     * caches the results in the database.  Only new/changed files are processed
     * unless `force=true` is passed.
     */
    public function locationScan(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        // Prevent concurrent scans
        $progress = $this->peopleLocationService->getLocationScanProgress($userId);
        if ($progress['status'] === 'scanning') {
            return new JSONResponse(
                ['error' => 'Location scan already in progress.', 'progress' => $progress],
                Http::STATUS_CONFLICT,
            );
        }

        $force = $this->request->getParam('force', 'false') === 'true';
        $result = $this->peopleLocationService->scanLocationData($userId, $force);

        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     *
     * Poll location-scan progress.
     */
    public function locationScanStatus(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            $this->peopleLocationService->getLocationScanProgress($user->getUID()),
        );
    }
}

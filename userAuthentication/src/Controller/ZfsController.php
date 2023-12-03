<?php
// src/Controller/ZfsController.php

namespace App\Controller;
use App\Service\UserService;
use App\Service\ZfsService;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;


use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;


use App\Entity\User;
use App\Controller\UserController;

/**
 * @Route("/api/zfs", name="zfs_")
 */
class ZfsController extends AbstractController
{
    private UserController $userController;
    private ParameterBagInterface $params;
    private ManagerRegistry $doctrine;
    private EntityManagerInterface $entityManager;
    private Filesystem $filesystem;
    private UserService $userService;
    private ZfsService $zfsService;

    public function __construct(UserController $userController, ParameterBagInterface $params, ManagerRegistry $doctrine, EntityManagerInterface $entityManager, Filesystem $filesystem, UserService $userService, ZfsService $zfsService)
    {
        $this->userController = $userController;
        $this->userService = $userService;
        $this->params = $params;
        $this->doctrine = $doctrine;
        $this->entityManager = $entityManager;
        $this->filesystem = $filesystem;
        $this->zfsService=$zfsService;
    }

    #[Route('/snapshot', name: 'create_snapshot', methods: ['POST'])]
    public function createSnapshot(Request $request): JsonResponse
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }

        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $userId = $user->getId();
        //var_dump($userId);
        $zpool = $this->params->get('ROOT_ZPOOL');

        // Create a snapshot
        $dateTime = new \DateTime();
        $snapshotName = $zpool . '/' . $userId . '@' . $dateTime->format('Y-m-d_H:i:s');
        $process = new Process(['zfs', 'snapshot', $snapshotName]); //zfs allow datto snapshot filebox

        try {
            $process->mustRun();
            return new JsonResponse(['message' => 'Snapshot created successfully'], Response::HTTP_OK);
        } catch (ProcessFailedException $exception) {
            return new JsonResponse(['error' => 'Failed to create snapshot', 'message' => $exception->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/snapshots', name: 'zfs_list_snapshots', methods: ['GET'])]
    public function listSnapshots(Request $request): JsonResponse
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        $user = $this->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $userId = $user->getId();
        $zpool = $this->params->get('ROOT_ZPOOL');
        $process = new Process(['zfs', 'list', '-t', 'snapshot']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();
        $lines = explode(PHP_EOL, $output);
        $snapshots = [];

        foreach ($lines as $line) {
            if (str_contains($line, "$zpool/$userId@")) {
                $parts = preg_split('/\s+/', $line);
                $nameParts = explode('@', $parts[0]);
                $dateHour = $nameParts[1];
                $snapshots[] = [
                    'name' => $parts[0],
                    'date_and_hour' => $dateHour,
                    'used' => $parts[1],
                    'avail' => $parts[2],
                    'refer' => $parts[3],
                    'mountpoint' => $parts[4],
                ];
            }
        }

        return new JsonResponse($snapshots);
    }

    #[Route('/snapshot', name: 'delete_snapshot', methods: ['DELETE'])]
    public function deleteSnapshot(Request $request): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        $user = $this->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $data = json_decode($request->getContent(), true);
        $fullname = $data['name'];

        // Extract user ID from snapshot name
        $snapshotUserId = explode('@', explode('/', $fullname)[1])[0];

        if ($user->getId() != $snapshotUserId) {
            return new JsonResponse(['error' => 'Access denied to delete this snapshot. Only the owner can delete snapshots.'], Response::HTTP_FORBIDDEN);
        }

        // Check if snapshot exists and delete it if it does
        $process = new Process(['sudo', '/usr/sbin/zfs', 'destroy', $fullname]);

        $process->run();

        if (!$process->isSuccessful()) {
            return new JsonResponse(['error' => 'Invalid snapshot name or other error occurred'], Response::HTTP_BAD_REQUEST);
        }

        return new Response('Snapshot deleted successfully', Response::HTTP_OK);
    }

    /**
     * @throws Exception
     */
    #[Route('/recovery', name: 'recovery', methods: ['POST'])]
    public function recovery(Request $request): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        $user = $this->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $userId = $user->getId();
        $data = json_decode($request->getContent(), true);
        $snapshotName = $data['name'];
        $zpool = $this->params->get('ROOT_ZPOOL');

        $rollbackProcess = new Process(['sudo', '/usr/sbin/zfs', 'rollback', '-r', $snapshotName]);
        $rollbackProcess->run();

        if (!$rollbackProcess->isSuccessful()) {
            throw new ProcessFailedException($rollbackProcess);
        }

        $jsonFilePath =  '/' .$zpool . '/' . $userId . '/'. $userId  .'.json';
        //var_dump($jsonFilePath);
        if (!$this->filesystem->exists($jsonFilePath)) {
            return new JsonResponse(['error' => 'Data file not found'], Response::HTTP_BAD_REQUEST);
        }

        $jsonData = json_decode(file_get_contents($jsonFilePath), true);

        // Clear current user's data
        $this->zfsService->clearUserData($userId, $this->entityManager);

        // Populate DB with data from JSON
        $this->zfsService->populateUserData($jsonData, $userId, $this->entityManager);

        return new JsonResponse(['status' => 'Data successfully recovered'], Response::HTTP_OK);
    }




}


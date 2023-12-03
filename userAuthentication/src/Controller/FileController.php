<?php
// src/Controller/FileController.php

namespace App\Controller;


use App\Entity\File;
use App\Service\FileService;
use App\Controller\UserController;

use App\Service\UserService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;



use App\Entity\User;
use App\Entity\BlacklistedToken;

use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Folder;


/**
 * @Route("/api/file", name="file_")
 */
class FileController extends AbstractController
{

    private FileService $fileService;
    private UserController $userController;
    private ParameterBagInterface $params;

    private ManagerRegistry $doctrine;

    private EntityManagerInterface $entityManager;
    private UserService $userService;

    public function __construct(FileService $fileService, ParameterBagInterface $params, EntityManagerInterface $entityManager, ManagerRegistry $doctrine, UserController $userController, UserService $userService)
    {
        $this->userService = $userService;

        $this->fileService = $fileService;
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->doctrine = $doctrine;
        $this->userController=$userController;
    }

    /**
     * @throws Exception
     */
    #[Route('/create/{type}', name: 'file_create', methods: ['POST'])]
    public function create(string $type, Request $request, Filesystem $filesystem, UserController $userController): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if ($user === null) {
            // No user is authenticated - handle this case as needed
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $userId=$user->getId();


        $data = json_decode($request->getContent());

        $name = $data->name;
        $parentId = $data->parent_id ?? null;
        if (isset($parentId)) {
            $parentFolder = $this->doctrine->getRepository(Folder::class)->find($parentId);
            if (!$parentFolder || $parentFolder->getUser() !== $user) {
                return new JsonResponse(['error' => 'Invalid Parent Folder'], Response::HTTP_FORBIDDEN);
            }
        }
        $content = $data->content ?? null;

        $entityManager = $this->doctrine->getManager();
        $uniqueName=null;
        if ($type === 'folder') {

            // Save to database
            $folder = new Folder();
            $folder->setName($name);
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            if (isset($parentId)) {

                $parentFolder = $folderRepository->find($parentId);
                $folder->setParent($parentFolder);
            }

            // Check if a folder with the same name and parentId already exists
            $existingFolder = $folderRepository->findOneBy(['name' => $name, 'parent' => $parentId, 'user' => $userId]);
            if ($existingFolder !== null) {
                return new JsonResponse(['error' => 'A folder with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
            }
            $folder->setCreatedAt(new \DateTimeImmutable());
            $folder->setUser($user);
            $folder->setType($type);
            $entityManager->persist($folder);
            $entityManager->flush();


        } else if ($type === 'file') {
            $size=$data->size;
            $entityManager = $this->doctrine->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            $decodedContent = base64_decode($content);
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $uniqueName = uniqid() . '.' . $extension;
            $baseDir = $this->params->get('ROOT_DIRECTORY');
            try {
                $fileRepository = $this->doctrine->getRepository(File::class);

                // Check if a file with the same name and parentId already exists
                $existingFile = $fileRepository->findOneBy(['name' => $name, 'parent' => $parentId, 'user' => $userId]);
                if ($existingFile !== null) {
                    return new JsonResponse(['error' => 'A file with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
                }

                $filesystem->dumpFile($baseDir . '/'. $userId.'/'. $uniqueName, $decodedContent);

                // Save to database
                $file = new File();
                $file->setName($name);
                $file->setSize($size);
                if (isset($parentId)) {
                    $folderRepository = $this->doctrine->getRepository(Folder::class);
                    $folder = $folderRepository->find($parentId);
                    $file->setParent($folder);
                }


                $file->setCreatedAt(new \DateTimeImmutable());
                $file->setUser($user);
                $file->setUniqueName($uniqueName);
                $file->setType($type);

                $entityManager->persist($file);
                $entityManager->flush();
                $entityManager->getConnection()->commit(); // commit transaction
            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();

                // Delete the file from the filesystem
                if ($filesystem->exists($baseDir . '/'. $userId.'/'. $uniqueName)) {
                    $filesystem->remove($baseDir . '/'. $userId.'/'. $uniqueName);
                }
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } else {
            return new JsonResponse(['error' => 'Invalid Type'], Response::HTTP_FORBIDDEN);
        }

        $responseData=[
            'id' => $type === 'file' ? $file->getId() : ($folder?->getId()),
            'name' => $name,
            'type' => $type,
            'parent_id' => $parentId,
            'created_at' => date('Y-m-d H:i:s')
        ];
        if ($type === 'file') {
            $responseData['size']=$size;
        }

        $jsonFilePath = $this->params->get('ROOT_DIRECTORY').'/' . $userId . '/' . $userId . '.json';

// Check if the json file of the db already exists
        if (file_exists($jsonFilePath)) {
            // File exists, read the existing content
            $existingData = json_decode(file_get_contents($jsonFilePath), true);
        } else {
            // File does not exist, initialize with empty arrays
            $existingData = ['files' => [], 'folders' => []];
        }

// Append the new data
        if ($type === 'file') {
            $responseDataFile=$responseData;
            $responseDataFile['unique_name']=$uniqueName;
            $existingData['files'][] = $responseDataFile;
        } else {
            $existingData['folders'][] = $responseData;
        }

// Write the updated data back to the file
        file_put_contents($jsonFilePath, json_encode($existingData, JSON_PRETTY_PRINT));

// ...

// Return your response
        return $this->json($responseData, Response::HTTP_CREATED);

    }

    /**
     * @throws Exception
     */
    #[Route('/download/{type}/{fileId}', name: 'file_download', methods: ['GET'])]
    public function download(string $fileId, string $type, Request $request, FileService $fileService, UserController $userController): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userId=$user->getId();

        if ($type === 'file') {
            $fileRepository = $this->doctrine->getRepository(File::class);
            $file = $fileRepository->find($fileId);
            $fileUser = $file->getUser();
            if ($fileUser !== $user) {
                return new Response(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }
            $uniqueName = $file->getUniqueName();
            $fileName = $file->getName();
            $baseDir = $this->params->get('ROOT_DIRECTORY');
            $filePath = $baseDir . '/'. $userId.'/'. $uniqueName;

            // Call the service to download the file
            $content = $fileService->downloadFile($filePath);

            // Create and return the response with appropriate headers
            return new Response(
                $content,
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"'
                ]
            );
        } elseif ($type === 'folder') {
            $folderRepository = $this->doctrine->getRepository(Folder::class);

            $folder = $folderRepository->find($fileId);
            if (!$folder) {
                return new Response(['error' => 'Folder not found'], Response::HTTP_NOT_FOUND);
            }

            $zip = new \ZipArchive();
            $zipFileName = tempnam(sys_get_temp_dir(), 'zip');
            $zip->open($zipFileName, \ZipArchive::CREATE);

            // Zip the desired folder and its contents
            $fileService->addFolderToZip($folder, $userId, $zip, '', $this->params);

            $zip->close();

            // Now you can return the zip file as a response
            return new Response(
                file_get_contents($zipFileName),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $folder->getName() . '.zip"'
                ]
            );

        } else {
            return new Response(['error' => 'Invalid Type'], Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @throws Exception
     */
    #[Route('/update/{type}/{id}', name: 'file_update', methods: ['PUT'])]
    public function update(string $type, string $id, Request $request, Filesystem $filesystem, UserController $userController): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();

        $userId=$user->getId();
        // Get the request data
        $data = json_decode($request->getContent());
        //var_dump($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }



        $name = $data->name;
        $parentId = $data->parent_id ?? null;
        if (isset($parentId)) {
            $parentFolder = $this->doctrine->getRepository(Folder::class)->find($parentId);
            if (!$parentFolder || $parentFolder->getUser() !== $user) {
                return new JsonResponse(['error' => 'Invalid Parent Folder'], Response::HTTP_FORBIDDEN);
            }
        }
        $content = $data->content ?? null;

        $zpoolDir = $this->params->get('ROOT_DIRECTORY');
        $jsonFile = $zpoolDir . '/' . $userId . '/' . $userId .'.json';
        //var_dump($jsonFile);
        if (!file_exists($jsonFile)) {
            return new JsonResponse(['error' => 'JSON file does not exist'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $jsonData = json_decode(file_get_contents($jsonFile), true);


        // Update the file or folder
        if ($type === 'file') {
            $fileRepository = $this->doctrine->getRepository(File::class);
            $fileUser = $fileRepository->find($id)->getUser();
            if ($fileUser !== $user) {
                return new Response(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }
            $entityManager = $this->doctrine->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            $fileRepository = $this->doctrine->getRepository(File::class);
            $originalFile = $fileRepository->find($id);
            $uniqueName = $originalFile->getUniqueName();
            $baseDir = $this->params->get('ROOT_DIRECTORY');
            try {

                // Decode the content (if provided) and write it to the file
                $fileRepository = $this->doctrine->getRepository(File::class);

                // Check if a file with the same name and parentId already exists
                $existingFile = $fileRepository->findOneBy(['name' => $name, 'parent' => $parentId, 'user' => $userId]);

                if ($existingFile !== null && $existingFile->getId() !== $id) {
                    // There is another file with the same name and parent, which is not the file we're updating
                    return new JsonResponse(['error' => 'A file with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
                }
                if ($content) {
                    $size=$data->size;
                    $originalFile->setSize($size);
                    $decodedContent = base64_decode($content);
                    $filesystem->dumpFile($baseDir . '/'. $userId.'/'. $uniqueName, $decodedContent);
                }

                // Update the file's metadata
                $originalFile->setName($name);

                if (isset($parentId)) {
                    $folderRepository = $this->doctrine->getRepository(Folder::class);
                    $folder = $folderRepository->find($parentId);
                    $originalFile->setParent($folder);
                }else{
                    $originalFile->setParent(null);
                }


                // Save to database
                $entityManager = $this->doctrine->getManager();
                $entityManager->persist($originalFile);
                $entityManager->flush();
                $entityManager->getConnection()->commit(); // commit transaction

                $result = [
                    'id' => $originalFile->getId(),
                    'name' => $originalFile->getName(),
                    'size' => $originalFile->getSize(),
                    'type' => 'file',
                    'parent_id' => $originalFile->getParent() !== null ? $originalFile->getParent()->getId() : null,
                    'created_at' => $originalFile->getCreatedAt()->format('Y-m-d H:i:s'),

                ];
                foreach ($jsonData['files'] as &$file) {

                    if ($file['id'] === $originalFile->getId()) {
                        $file['name'] = $originalFile->getName();
                        $file['size'] = $originalFile->getSize();
                        $file['parent_id'] = $originalFile->getParent() !== null ? $originalFile->getParent()->getId() : null;
                        $file['created_at'] = $originalFile->getCreatedAt()->format('Y-m-d H:i:s');
                        $file['unique_name']=$originalFile->getUniqueName();
                        break;
                    }
                }

                if (file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT)) === false) {
                    return new JsonResponse(['error' => 'Failed to write to JSON file'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                return new JsonResponse($result, Response::HTTP_OK);
            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();

                // Delete the file from the filesystem
                if ($filesystem->exists($baseDir . '/' . $userId . '/' . $uniqueName)) {
                    $filesystem->remove($baseDir . '/' . $userId . '/' . $uniqueName);
                }
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else if ($type === 'folder') {
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            $folder = $folderRepository->find($id);
            $folderUser = $folder->getUser();
            if ($folderUser !== $user) {
                return new Response(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }


            // Update the folder's metadata
            $folder->setName($name);
            if (isset($parentId)) {
                $newParentFolder = $folderRepository->find($parentId);
                // Check if folder is trying to become its own parent
                if ($newParentFolder === $folder) {
                    return new JsonResponse(['error' => 'A folder cannot be its own parent'], Response::HTTP_CONFLICT);
                }
                $descendants = $folderRepository->getAllDescendants($folder);
                if (in_array($newParentFolder, $descendants)) {
                    return new JsonResponse(['error' => 'A folder cannot be moved to its descendants'], Response::HTTP_CONFLICT);
                }
                $folder->setParent($newParentFolder);
            } else {
                $folder->setParent(null);
            }

            // Check if a folder with the same name and parentId already exists but not with the same id
            $existingFolder = $folderRepository->findOneBy(['name' => $name, 'parent' => $parentId, 'user' => $userId]);

            if ($existingFolder !== null && $existingFolder->getId() !== $id) {
                // There is another folder with the same name and parent, which is not the folder we're updating
                return new JsonResponse(['error' => 'A folder with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
            }
            // Save to database
            $entityManager = $this->doctrine->getManager();
            $entityManager->persist($folder);
            $entityManager->flush();

            $result = [
                'id' => $folder->getId(),
                'name' => $folder->getName(),
                'type' => 'folder',
                'parent_id' => $folder->getParent()?->getId(),
                'created_at' => $folder->getCreatedAt()->format('Y-m-d H:i:s'),

            ];

            foreach ($jsonData['folders'] as &$folder1) {
                if ($folder1['id'] === $folder->getId()) {
                    $folder1['name'] = $folder->getName();
                    $folder1['parent_id'] = $folder->getParent()?->getId();
                    $folder1['created_at'] = $folder->getCreatedAt()->format('Y-m-d H:i:s');

                    break;
                }
            }

            if (file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT)) === false) {
                return new JsonResponse(['error' => 'Failed to write to JSON file'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new JsonResponse($result, Response::HTTP_OK);
        } else {

            return new JsonResponse(['error' => 'Invalid Type'], Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @throws Exception
     */
    #[Route('/delete/{type}/{fileId}', name: 'file_delete', methods: ['DELETE'])]
    public function delete(string $fileId, string $type, Request $request, Filesystem $filesystem, FileService $fileService, UserController $userController): JsonResponse
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userId=$user->getId();


        if ($type === 'file') {
            $fileRepository = $this->doctrine->getRepository(File::class);
            $file = $fileRepository->find($fileId);

            // Check file exists and user owns it
            if (!$file || $file->getUser() !== $user) {
                return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }
            $entityManager = $this->doctrine->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction

            try {
                $entityManager->remove($file);
                $entityManager->flush();

                // Call the service to delete the file
                $message = $fileService->deleteFile($file->getUniqueName(), $userId, $filesystem, $this->params);

                $entityManager->getConnection()->commit(); // commit transaction

            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();

                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // After deleting the file from the database and filesystem,
            // also remove it from the JSON file
            $jsonFile = $this->params->get('ROOT_DIRECTORY').'/' . $userId . '/' . $userId . '.json';
            $jsonData = json_decode(file_get_contents($jsonFile), true);

            foreach ($jsonData['files'] as $key => $item) {
                if ($item['id'] == $fileId) {
                    unset($jsonData['files'][$key]);
                    break;
                }
            }

            file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));
        } else if ($type === 'folder') {
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            $folder = $folderRepository->find($fileId);

            // Check folder exists and user owns it
            if (!$folder || $folder->getUser() !== $user) {
                return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }
            $entityManager = $this->doctrine->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            try {
                // Recursive delete
                $fileService->deleteFolderAndContents($folder, $userId, $filesystem, $fileService, $this->doctrine, $this->params);
                $message = 'Folder Deleted';
                $entityManager->getConnection()->commit(); // commit transaction

            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } else {
            // Return the response for file or folder not found
            return new JsonResponse(['message' => 'File or Folder not found'], Response::HTTP_NOT_FOUND);
        }

        // Return the response
        return new JsonResponse(['message' => $message], Response::HTTP_OK);
    }

    #[Route('/getMetadata/{parentId}', name: 'file_getMetadata', methods: ['GET'])]
    public function getMetadata(?string $parentId, Request $request, UserController $userController): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userId = $user->getId();

        // Get Doctrine manager
        $entityManager = $this->doctrine->getManager();

        // Create query builder for files
        $fileQueryBuilder = $entityManager->createQueryBuilder();
        $folderRepository = $this->doctrine->getRepository(Folder::class);
        //var_dump($parentId);
        $parent=null;
        if ($parentId!=='0'){
            $parent = $parentId !== null ? $folderRepository->find($parentId) : null;
        }
        //var_dump($parent);
        $fileQueryBuilder->select('f')
            ->from(File::class, 'f')
            ->andWhere('f.user = :user')
            ->setParameter('user', $userId);

        if ($parent !== null) {
            $fileQueryBuilder->andWhere('f.parent = :parent')
                ->setParameter('parent', $parent);
        } else {
            $fileQueryBuilder->andWhere('f.parent IS NULL');
        }

        // Execute files query
        $files = $fileQueryBuilder->getQuery()->getResult();
        //var_dump($files);

        // Create query builder for folders
        $folderQueryBuilder = $entityManager->createQueryBuilder();
        $folderQueryBuilder->select('fo')
            ->from(Folder::class, 'fo')
            ->andWhere('fo.user = :user')
            ->setParameter('user', $userId);

        if ($parent !== null) {
            $folderQueryBuilder->andWhere('fo.parent = :parent')
                ->setParameter('parent', $parent);
        } else {
            $folderQueryBuilder->andWhere('fo.parent IS NULL');
        }

        // Execute folders query
        $folders = $folderQueryBuilder->getQuery()->getResult();
        //var_dump($folders);
        // Combine files and folders into one array
        $result = array_merge($files, $folders);
        $resultArray = array_map(function ($item) {
            return [
                'id' => $item->getId(),
                'name' => $item->getName(),
                'size' => get_class($item) === File::class ? $item->getSize() : null,
                'type' => get_class($item) === File::class ? 'file' : 'folder',
                'parent_id' => $item->getParent() !== null ? $item->getParent()->getId() : null,
                'created_at' => $item->getCreatedAt()->format('Y-m-d H:i:s'),
                // Add other properties you need
            ];
        }, $result);
        //var_dump($resultArray);
        // Return the response
        return new JsonResponse($resultArray, Response::HTTP_OK);
    }

    #[Route('/suitable-folders/{type}/{id}', name: 'suitable_folders', methods: ['GET'])]
    public function getSuitableFolders(string $type, string $id, Request $request, UserController $userController): Response
    {
        $bToken = $this->userService ->getBToken($request, $this->doctrine);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Not Authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if ($type === 'file') {
            $fileRepository = $this->doctrine->getRepository(File::class);
            $file = $fileRepository->find($id);

            // Get folders that belong to the user
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            $folders = $folderRepository->findBy(['user' => $user]);
            $folders[] = null;  // Adding the root

            // Filter out folders that already have a file with that name
            $suitableFolders = array_filter($folders, function ($folder) use ($file) {
                return !$this->doctrine->getRepository(File::class)->findOneBy(['name' => $file->getName(), 'parent' => $folder]);
            });

        } else if ($type === 'folder') {
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            $folder = $folderRepository->find($id);

            // Get folders that belong to the user
            $allFolders = $folderRepository->findBy(['user' => $user]);
            $allFolders[] = null;  // Adding the root

            // Exclude children and the folder itself
            $descendants = $folderRepository->getAllDescendants($folder);
            $descendants[] = $folder;

            // Filter out folders that are descendants of the folder or have a folder with the same name
            $suitableFolders = array_filter($allFolders, function ($otherFolder) use ($folder, $descendants) {
                return !in_array($otherFolder, $descendants) &&
                    !$this->doctrine->getRepository(Folder::class)->findOneBy(['name' => $folder->getName(), 'parent' => $otherFolder]);
            });

        } else {
            return new JsonResponse(['error' => 'Invalid Type'], Response::HTTP_BAD_REQUEST);
        }

        // Convert folders to a suitable response format
        $response = array_map(function ($folder) {
            return [
                'id' => $folder!==null?$folder->getId():null,
                'name' => $folder!==null?$folder->getName():null,
                'parent_id' => $folder!==null?$folder->getParent()?->getId():null,
                'created_at' => $folder!==null?$folder->getCreatedAt()->format('Y-m-d H:i:s'):null
            ];
        }, $suitableFolders);

        return new JsonResponse($response, Response::HTTP_OK);
    }
}
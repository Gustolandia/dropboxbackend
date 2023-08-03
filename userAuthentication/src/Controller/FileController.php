<?php
// src/Controller/FileController.php

namespace App\Controller;

use App\Entity\File;
use App\Service\FileService;
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
    private ParameterBagInterface $params;

    private ManagerRegistry $doctrine;

    private EntityManagerInterface $entityManager;

    public function __construct(FileService $fileService, ParameterBagInterface $params, EntityManagerInterface $entityManager, ManagerRegistry $doctrine)
    {
        $this->fileService = $fileService;
        $this->params = $params;
        $this->entityManager = $entityManager;
        $this->doctrine = $doctrine;
    }

    /**
     * @throws Exception
     */
    #[Route('/create', name: 'file_create', methods: ['POST'])]
    public function create(Request $request, Filesystem $filesystem): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if ($user === null) {
            // No user is authenticated - handle this case as needed
            throw new AccessDeniedHttpException('Not authenticated');
        }


        $data = json_decode($request->getContent());

        $name = $data->name;
        $type = $data->type;
        $parentId = $data->parent_id ?? null;
        if (isset($parentId)) {
            $parentFolder = $this->doctrine->getRepository(Folder::class)->find($parentId);
            if (!$parentFolder || $parentFolder->getUser() !== $user) {
                return new JsonResponse(['error' => 'Invalid Parent Folder'], Response::HTTP_FORBIDDEN);
            }
        }
        $content = $data->content ?? null;

        $entityManager = $this->doctrine->getManager();
        $file = null;
        $folder = null;

        if ($type === 'folder') {

            // Save to database
            $folder = new Folder();
            $folder->setName($name);
            if (isset($parentId)) {
                $folderRepository = $this->doctrine->getRepository(Folder::class);
                $folder = $folderRepository->find($parentId);
                $folder->setParent($folder);
            }
            $folderRepository = $this->doctrine->getRepository(Folder::class);

            // Check if a folder with the same name and parentId already exists
            $existingFolder = $folderRepository->findOneBy(['name' => $name, 'parent' => $parentId]);
            if ($existingFolder !== null) {
                return new JsonResponse(['error' => 'A folder with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
            }
            $folder->setCreatedAt(new \DateTimeImmutable());
            $folder->setUser($user);
            $folder->setType($type);
            $entityManager->persist($folder);
            $entityManager->flush();

        } else if ($type === 'file') {
            $entityManager = $this->doctrine->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            $decodedContent = base64_decode($content);
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $uniqueName = uniqid() . '.' . $extension;
            $baseDir = $this->params->get('ROOT_DIRECTORY');
            try {
                $fileRepository = $this->doctrine->getRepository(File::class);

                // Check if a file with the same name and parentId already exists
                $existingFile = $fileRepository->findOneBy(['name' => $name, 'parent' => $parentId]);
                if ($existingFile !== null) {
                    return new JsonResponse(['error' => 'A file with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
                }

                $filesystem->dumpFile($baseDir . '\\' . $uniqueName, $decodedContent);

                // Save to database
                $file = new File();
                $file->setName($name);
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
                if ($filesystem->exists($baseDir . '\\' . $uniqueName)) {
                    $filesystem->remove($baseDir . '\\' . $uniqueName);
                }
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } else {
            return new JsonResponse(['error' => 'Invalid Type'], Response::HTTP_FORBIDDEN);
        }


        return $this->json(['id' => $file->getId() ?? $folder->getId(), 'name' => $name, 'type' => $type, 'parent_id' => $parentId, 'created_at' => date('Y-m-d H:i:s')], Response::HTTP_CREATED);
    }

    /**
     * @throws Exception
     */
    #[Route('/download/{fileId}', name: 'file_download', methods: ['GET'])]
    public function download(string $fileId, FileService $fileService): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();

        $fileRepository = $this->doctrine->getRepository(File::class);
        $file = $fileRepository->find($fileId);
        $fileUser = $file->getUser();
        if ($fileUser !== $user) {
            return new Response(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }
        $fileUniqueName = $file->getUniqueName();
        $fileName = $file->getName();
        $baseDir = $this->params->get('ROOT_DIRECTORY');
        $filePath = $baseDir . '\\' . $fileUniqueName;

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
    }

    /**
     * @throws Exception
     */
    #[Route('/update', name: 'file_update', methods: ['PUT'])]
    public function update(Request $request, Filesystem $filesystem): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();


        // Get the request data
        $data = json_decode($request->getContent(), true);
        $id = $data['id'];
        $name = $data['name'];
        $type = $data['type'];
        $parentId = $data['parent_id'] ?? null;
        if (isset($parentId)) {
            $parentFolder = $this->doctrine->getRepository(Folder::class)->find($parentId);
            if (!$parentFolder || $parentFolder->getUser() !== $user) {
                return new JsonResponse(['error' => 'Invalid Parent Folder'], Response::HTTP_FORBIDDEN);
            }
        }
        $content = $data['content'] ?? null;


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
                $existingFile = $fileRepository->findOneBy(['name' => $name, 'parent' => $parentId]);
                if ($existingFile !== null) {
                    return new JsonResponse(['error' => 'A file with this name already exists in this parent folder'], Response::HTTP_CONFLICT);
                }
                if ($content) {
                    $decodedContent = base64_decode($content);
                    $filesystem->dumpFile($baseDir . '\\' . $uniqueName, $decodedContent);
                }

                // Update the file's metadata
                $originalFile->setName($name);
                if (isset($parentId)) {
                    $folderRepository = $this->doctrine->getRepository(Folder::class);
                    $folder = $folderRepository->find($parentId);
                    $originalFile->setParent($folder);
                }


                // Save to database
                $entityManager = $this->doctrine->getManager();
                $entityManager->persist($originalFile);
                $entityManager->flush();
                $entityManager->getConnection()->commit(); // commit transaction

                $result = [
                    'id' => $originalFile->getId(),
                    'name' => $originalFile->getName(),
                    'type' => 'file',
                    'parent_id' => $originalFile->getParent() !== null ? $originalFile->getParent()->getId() : null,
                    'created_at' => $originalFile->getCreatedAt()->format('Y-m-d H:i:s'),
                    // Add other properties you need
                ];

                return new JsonResponse($result, Response::HTTP_OK);
            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();

                // Delete the file from the filesystem
                if ($filesystem->exists($baseDir . '\\' . $uniqueName)) {
                    $filesystem->remove($baseDir . '\\' . $uniqueName);
                }
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else if ($type === 'folder') {
            $fileRepository = $this->doctrine->getRepository(Folder::class);
            $fileUser = $fileRepository->find($id)->getUser();
            if ($fileUser !== $user) {
                return new Response(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }
            $folderRepository = $this->doctrine->getRepository(Folder::class);
            $folder = $folderRepository->find($id);

            // Update the folder's metadata
            $folder->setName($name);
            if (isset($parentId)) {
                $folderRepository = $this->doctrine->getRepository(Folder::class);
                $folder = $folderRepository->find($parentId);
                $folder->setParent($folder);
            }

            $folderRepository = $this->doctrine->getRepository(Folder::class);

            // Check if a folder with the same name and parentId already exists
            $existingFolder = $folderRepository->findOneBy(['name' => $name, 'parent' => $parentId]);
            if ($existingFolder !== null) {
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
                'parent_id' => $folder->getParent() !== null ? $folder->getParent()->getId() : null,
                'created_at' => $folder->getCreatedAt()->format('Y-m-d H:i:s'),
                // Add other properties you need
            ];

            return new JsonResponse($result, Response::HTTP_OK);
        } else {

            return new JsonResponse(['error' => 'Invalid Type'], Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @throws Exception
     */
    #[Route('/delete/{type}/{fileId}', name: 'file_delete', methods: ['DELETE'])]
    public function delete(string $fileId, string $type, Filesystem $filesystem, FileService $fileService): JsonResponse
    {
        /** @var UserInterface|null $user */
        $user = $this->getUser();


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
                $message = $fileService->deleteFile($file->getUniqueName(), $filesystem);

                $entityManager->getConnection()->commit(); // commit transaction

            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();

                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
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
                $this->deleteFolderAndContents($folder, $filesystem, $fileService);
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

    private function deleteFolderAndContents(Folder $folder, Filesystem $filesystem, FileService $fileService): void
    {
        $entityManager = $this->doctrine->getManager();
        $baseDir = $this->params->get('ROOT_DIRECTORY');

        // Delete subfolders
        foreach ($folder->getSubfolders() as $subfolder) {
            $this->deleteFolderAndContents($subfolder, $filesystem, $fileService);
        }

        // Delete files in folder
        foreach ($folder->getFiles() as $file) {
            $fileService->deleteFile($baseDir . '\\' . $file->getUniqueName(), $filesystem);
            $entityManager->remove($file);
        }

        // Delete the folder from the database
        $entityManager->remove($folder);
        $entityManager->flush();
    }

    #[Route('/getMetadata/{parentId}', name: 'file_getMetadata', methods: ['GET'])]
    public function getMetadata(?string $parentId): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userId = $user->getId();

        // Get Doctrine manager
        $entityManager = $this->doctrine->getManager();

        // Create query builder for files
        $fileQueryBuilder = $entityManager->createQueryBuilder();
        $folderRepository = $this->doctrine->getRepository(Folder::class);
        $parent = $parentId !== null ? $folderRepository->find($parentId) : null;

        $fileQueryBuilder->select('f')
            ->from(File::class, 'f')
            ->where('f.parent = :parent')  //Correction needed here f.parent is an object
            ->andWhere('f.user = :user')
            ->setParameters([
                'parent' => $parent, // You should compare with $parent object not the ID
                'user' => $userId,
            ]);

        // Execute files query
        $files = $fileQueryBuilder->getQuery()->getResult();


        // Create query builder for folders
        $folderQueryBuilder = $entityManager->createQueryBuilder();
        $folderQueryBuilder->select('fo')
            ->from(Folder::class, 'fo')
            ->where('fo.parent = :parent')
            ->andWhere('fo.user = :user')  // Here you should also use 'user' not 'userId'
            ->setParameters([
                'parent' => $parent, // Here also, compare with $parent object not the ID
                'user' => $userId,   // Here 'user' and 'userId' should match
            ]);

        // Execute folders query
        $folders = $folderQueryBuilder->getQuery()->getResult();

        // Combine files and folders into one array
        $result = array_merge($files, $folders);
        $resultArray = array_map(function ($item) {
            return [
                'id' => $item->getId(),
                'name' => $item->getName(),
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
}
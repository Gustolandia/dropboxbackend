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
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use App\Entity\Folder;
use App\Entity\User;

/**
 * @Route("/rest/v1/file", name="file_")
 */
class FileController extends AbstractController
{
    private $fileService;

    public function __construct(FileService $fileService, ParameterBagInterface $params)
    {
        $this->fileService = $fileService;
        $this->params = $params;
    }

    /**
     * @throws Exception
     */
    #[Route('/rest/v1/file/create', name: 'file_create', methods: ['POST'])]
    public function create(Request $request, Filesystem $filesystem): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if ($user === null) {
            // No user is authenticated - handle this case as needed
            throw new AccessDeniedHttpException('Not authenticated');
        }
        $userID= $user->getId();

        $data = json_decode($request->getContent(), true);

        $name = $data['name'];
        $type = $data['type'];
        $parentId = $data['parent_id'] ?? null;
        $content = $data['content'] ?? null;

        $entityManager = $this->getDoctrine()->getManager();

        if ($type === 'folder') {

            // Save to database
            $folder = new Folder();
            $folder->setName($name);
            $folder->setParentId($parentId);
            $folder->setCreatedAt(new \DateTime());
            $folder->setUserId($userID);
            $entityManager->persist($folder);
            $entityManager->flush();

        } else if ($type === 'file') {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            try{
                $decodedContent = base64_decode($content);
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $uniqueName = uniqid() . '.' . $extension;
                $baseDir = $this->params->get('ROOT_DIRECTORY');
                $filesystem->dumpFile($baseDir . '\\' . $uniqueName, $decodedContent);

                // Save to database
                $file = new File();
                $file->setName($name);
                $file->setParentId($parentId);
                $file->setCreatedAt(new \DateTime());
                $file->setUserId($userID);
                $file->setUniqueName($uniqueName);

                $entityManager->persist($file);
                $entityManager->flush();
                $entityManager->getConnection()->commit(); // commit transaction
            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        }else{
            return new JsonResponse(['error' => 'Invalid Type' ], Response::HTTP_FORBIDDEN);
        }


        return $this->json(['id' => $file->getId() ?? $folder->getId(), 'name' => $name, 'type' => $type, 'parent_id' => $parentId, 'created_at' => date('Y-m-d H:i:s')], Response::HTTP_CREATED);
    }

    /**
     * @throws Exception
     */
    #[Route('/rest/v1/file/download', name: 'file_download', methods: ['GET'])]
    public function download(string $fileId, FileService $fileService): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userID= $user->getId();

        $fileRepository = $this->getDoctrine()->getRepository(File::class);
        $file = $fileRepository->find($fileId);
        $fileUserId = $file->getUserId();
        if($fileUserId!==$userID){
            return new Response(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
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
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"'
            ]
        );
    }

    /**
     * @throws Exception
     */
    #[Route('/rest/v1/file/update', name: 'file_update', methods: ['PUT'])]
    public function update(Request $request, Filesystem $filesystem, FileService $fileService, SerializerInterface $serializer): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userID= $user->getId();

        // Get the request data
        $data = json_decode($request->getContent(), true);
        $id = $data['id'];
        $name = $data['name'];
        $type = $data['type'];
        $parentId = $data['parent_id'] ?? null;
        $content = $data['content'] ?? null;


        // Update the file or folder
        if($type==='file') {
            $fileRepository = $this->getDoctrine()->getRepository(File::class);
            $fileUserId = $fileRepository->find($id)->getUserId();
            if($fileUserId!==$userID){
                return new Response(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
            }
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->getConnection()->beginTransaction(); // start transaction
            try{
                $fileRepository = $this->getDoctrine()->getRepository(File::class);
                $originalFile = $fileRepository->find($id);
                $uniqueName = $originalFile->getUniqueName();
                $baseDir = $this->params->get('ROOT_DIRECTORY');
                // Decode the content (if provided) and write it to the file
                if($content) {
                    $decodedContent = base64_decode($content);
                    $filesystem->dumpFile($baseDir . '\\' . $uniqueName, $decodedContent);
                }

                // Update the file's metadata
                $originalFile->setName($name);
                $originalFile->setParentId($parentId);

                // Save to database
                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->persist($originalFile);
                $entityManager->flush();
                $entityManager->getConnection()->commit(); // commit transaction

                return new JsonResponse($serializer->serialize($originalFile, 'json'), Response::HTTP_OK, [], true);
            } catch (Exception $e) {
                // rollback if there was an error
                $entityManager->getConnection()->rollBack();
                throw $e; // throw the exception again, handle it at a higher level or return a response
                return new JsonResponse(['message' => $e], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else if($type==='folder') {
            $fileRepository = $this->getDoctrine()->getRepository(Folder::class);
            $fileUserId = $fileRepository->find($id)->getUserId();
            if($fileUserId!==$userID){
                return new Response(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
            }
            $folderRepository = $this->getDoctrine()->getRepository(Folder::class);
            $folder = $folderRepository->find($id);

            // Update the folder's metadata
            $folder->setName($name);

            // Save to database
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($folder);
            $entityManager->flush();

            return new JsonResponse($serializer->serialize($folder, 'json'), Response::HTTP_OK, [], true);
        } else{

            return new JsonResponse(['error' => 'Invalid Type' ], Response::HTTP_FORBIDDEN);
        }
    }

    #[Route('/rest/v1/file/delete', name: 'file_delete', methods: ['DELETE'])]
    public function delete(string $fileId, string $type, Filesystem $filesystem, FileService $fileService): JsonResponse
    {
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userID = $user->getId();

        if ($type === 'file') {
            $fileRepository = $this->getDoctrine()->getRepository(File::class);
            $file = $fileRepository->find($fileId);

            // Check file exists and user owns it
            if (!$file || $file->getUserId() !== $userID) {
                return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }

            // Call the service to delete the file
            $message = $fileService->deleteFile($file->getUniqueName(), $filesystem);

            // Remove the file from the database
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($file);
            $entityManager->flush();
        } else if ($type === 'folder') {
            $folderRepository = $this->getDoctrine()->getRepository(Folder::class);
            $folder = $folderRepository->find($fileId);

            // Check folder exists and user owns it
            if (!$folder || $folder->getUserId() !== $userID) {
                return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
            }

            // Recursive delete
            $this->deleteFolderAndContents($folder, $filesystem, $fileService);

            $message = 'Folder Deleted';
        } else {
            // Return the response for file or folder not found
            return new JsonResponse(['message' => 'File or Folder not found'], Response::HTTP_NOT_FOUND);
        }

        // Return the response
        return new JsonResponse(['message' => $message], Response::HTTP_OK);
    }

    private function deleteFolderAndContents(Folder $folder, Filesystem $filesystem, FileService $fileService): void
    {
        $entityManager = $this->getDoctrine()->getManager();

        // Delete subfolders
        foreach ($folder->getSubfolders() as $subfolder) {
            $this->deleteFolderAndContents($subfolder, $filesystem, $fileService);
        }

        // Delete files in folder
        foreach ($folder->getFiles() as $file) {
            $fileService->deleteFile($file->getUniqueName(), $filesystem);
            $entityManager->remove($file);
        }

        // Delete the folder from the database
        $entityManager->remove($folder);
        $entityManager->flush();
    }

    #[Route('/rest/v1/file/getMetadata', name: 'file_getMetadata', methods: ['GET'])]
    public function getMetadata(?string $parentId): Response
    {
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $userID= $user->getId();

        // Get Doctrine manager
        $entityManager = $this->getDoctrine()->getManager();

        // Create query builder for files

        $fileQueryBuilder = $entityManager->createQueryBuilder();
        $fileQueryBuilder->select('f')
            ->from(File::class, 'f')
            ->where('f.parentId = :parentId')
            ->andWhere('f.userId = :userId')
            ->setParameters([
                'parentId' => $parentId,
                'userId' => $userID,
            ]);

        // Execute files query
        $files = $fileQueryBuilder->getQuery()->getResult();

        // Create query builder for folders
        $folderQueryBuilder = $entityManager->createQueryBuilder();
        $folderQueryBuilder->select('fo')
            ->from(Folder::class, 'fo')
            ->where('fo.parentId = :parentId')
            ->andWhere('fo.userId = :userId')
            ->setParameters([
                'parentId' => $parentId,
                'userId' => $userID,
            ]);

        // Execute folders query
        $folders = $folderQueryBuilder->getQuery()->getResult();

        // Combine files and folders into one array
        $result = array_merge($files, $folders);

        // Return the response
        return new JsonResponse($result, Response::HTTP_OK);
    }


}
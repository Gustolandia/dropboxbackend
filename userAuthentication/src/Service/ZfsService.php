<?php
namespace App\Service;

use App\Entity\Folder;
use App\Entity\File;
use App\Entity\BlacklistedToken;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpFoundation\Request;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class ZfsService
{
    /**
     * @throws Exception
     */
    public function clearUserData(int $userId, EntityManagerInterface $entityManager): void
    {
        $em = $entityManager;
        $conn = $em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeQuery('DELETE FROM file WHERE user_id = :userId', ['userId' => $userId]);

            //Make sure all folders are at the roort before deletion to respect mysql contraints
            $conn->executeQuery(
                'UPDATE folder f1 
                     JOIN folder f2 ON f1.parent_id = f2.id 
                     SET f1.parent_id = NULL 
                     WHERE f2.user_id = :userId',
                ['userId' => $userId]
            );
            $conn->executeQuery('DELETE FROM folder WHERE user_id = :userId', ['userId' => $userId]);

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    function reorderFolders(array $folders): array
    {
        $folderMap = [];
        foreach ($folders as $folder) {
            $folderMap[$folder['id']] = $folder;
        }

        $sortedFolders = [];
        $processed = [];

        function processFolder($folderId, &$folderMap, &$sortedFolders, &$processed) {
            if (isset($processed[$folderId])) {
                // Already processed this folder
                return;
            }

            $folder = $folderMap[$folderId];
            if ($folder['parent_id'] !== null && isset($folderMap[$folder['parent_id']])) {
                // Process parent folder first
                processFolder($folder['parent_id'], $folderMap, $sortedFolders, $processed);
            }

            // Add this folder to sorted list
            $sortedFolders[] = $folder;
            $processed[$folderId] = true;
        }

        foreach ($folderMap as $folderId => $folder) {
            processFolder($folderId, $folderMap, $sortedFolders, $processed);
        }

        return $sortedFolders;
    }



    /**
    * @throws Exception
    */
    public function populateUserData(array $jsonData, int $userId, EntityManagerInterface $entityManager): void
    {
        $em = $entityManager;
        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            // Reorder folders
            $sortedFolders = $this->reorderFolders($jsonData['folders']);

            // Insert reordered folders
            foreach ($sortedFolders as $folder) {
                $conn->executeQuery('INSERT INTO folder (id, name, type, parent_id, created_at, user_id) VALUES (:id, :name, :type, :parentId, :createdAt, :userId)', [
                    'id' => $folder['id'],
                    'name' => $folder['name'],
                    'type' => "folder",
                    'parentId' => $folder['parent_id'],
                    'createdAt' => $folder['created_at'],
                    'userId' => $userId,
                ]);
            }

            foreach ($jsonData['files'] as $file) {
                $conn->executeQuery('INSERT INTO file (id, name, type, parent_id, created_at, size, user_id, unique_name) VALUES (:id, :name, :type, :parentId, :createdAt, :size, :userId, :uniqueName)', [
                    'id' => $file['id'],
                    'name' => $file['name'],
                    'type' => "file",
                    'parentId' => $file['parent_id'],
                    'createdAt' => $file['created_at'],
                    'size' => $file['size'],
                    'userId' => $userId,
                    'uniqueName' => $file['unique_name'],
                ]);
            }


            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}
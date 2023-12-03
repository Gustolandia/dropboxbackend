<?php
namespace App\Service;

use App\Entity\Folder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class FileService
{


    /**
     * @throws \Exception
     */
    public function downloadFile(string $filePath): bool|string
    {
        // Check if the file exists
        if (!file_exists($filePath)) {
            throw new \Exception("File not found");
        }

        // Get the file content
        return file_get_contents($filePath);
    }

// Delete method
    public function deleteFile(string $uniqueName, string $userId, Filesystem $filesystem, ParameterBagInterface $params): string
    {
        // Validate if file exists
        $filePath = $this->getFilePath($uniqueName, $userId, $params);
        if (!$filesystem->exists($filePath)) {
            return 'File not found';
        }
        // Delete the file
        $filesystem->remove($filePath);

        return 'File deleted successfully';
    }

    public function getFilePath(string $uniqueName, string $userId, ParameterBagInterface $params): string
    {
        // Here, the baseDir can be a configuration setting, like in previous examples
        $baseDir = $params->get('ROOT_DIRECTORY');

        // Concatenate the base directory and the unique filename to form the full file path
        return $baseDir . '/'. $userId.'/'. $uniqueName;
    }

    public function deleteFolderAndContents(Folder $folder, string $userId, Filesystem $filesystem, FileService $fileService, ManagerRegistry $doctrine, ParameterBagInterface $params): void
    {
        $entityManager = $doctrine->getManager();

        // After deleting the files and folder from the database and filesystem,
        // also remove them from the JSON file
        $jsonFile = $params->get('ROOT_DIRECTORY').'/' . $userId . '/' . $userId . '.json';
        $jsonData = json_decode(file_get_contents($jsonFile), true);

        foreach ($folder->getFiles() as $file) {
            foreach ($jsonData['files'] as $key => $item) {
                if ($item['id'] == $file->getId()) {
                    unset($jsonData['files'][$key]);
                }
            }
        }

        foreach ($jsonData['folders'] as $key => $item) {
            if ($item['id'] == $folder->getId()) {
                unset($jsonData['folders'][$key]);
            }
        }

        file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));

        // Delete subfolders
        foreach ($folder->getSubfolders() as $subfolder) {
            $this->deleteFolderAndContents($subfolder, $userId, $filesystem, $fileService, $doctrine, $params);
        }

        // Delete files in folder
        foreach ($folder->getFiles() as $file) {
            $fileService->deleteFile($file->getUniqueName(), $userId, $filesystem, $params);
            $entityManager->remove($file);
        }

        // Delete the folder from the database
        $entityManager->remove($folder);
        $entityManager->flush();
    }


    /**
     * @throws Exception
     */
    public function addFolderToZip($folder, string $userId, $zip, $currentFolderPath = '', ParameterBagInterface $params): void
    {
        // First, create the folder in the ZIP (even if it's empty).
        if ($currentFolderPath) {
            $zip->addEmptyDir($currentFolderPath);
        }

        // Add files of the current folder to the ZIP
        foreach ($folder->getFiles() as $file) {
            $filePath = $params->get('ROOT_DIRECTORY') . '/' . $userId.'/' . $file->getUniqueName();
            $destinationPath = $currentFolderPath ? $currentFolderPath . '/' . $file->getName() : $file->getName();
            $zip->addFile($filePath, $destinationPath);
        }

        foreach ($folder->getSubfolders() as $subfolder) {
            $subfolderPath = $currentFolderPath ? $currentFolderPath . '/' . $subfolder->getName() : $subfolder->getName();
            $this->addFolderToZip($subfolder, $userId, $zip, $subfolderPath, $params);

        }
    }
}


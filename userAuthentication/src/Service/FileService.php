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
    public function deleteFile(string $uniqueName, Filesystem $filesystem, ParameterBagInterface $params): string
    {
        // Validate if file exists
        $filePath = $this->getFilePath($uniqueName, $params);
        if (!$filesystem->exists($filePath)) {
            return 'File not found';
        }
        // Delete the file
        $filesystem->remove($filePath);

        return 'File deleted successfully';
    }

    public function getFilePath(string $uniqueName, ParameterBagInterface $params): string
    {
        // Here, the baseDir can be a configuration setting, like in previous examples
        $baseDir = $params->get('ROOT_DIRECTORY');

        // Concatenate the base directory and the unique filename to form the full file path
        return $baseDir . '\\' . $uniqueName;
    }

    public function deleteFolderAndContents(Folder $folder, Filesystem $filesystem, FileService $fileService, ManagerRegistry $doctrine, ParameterBagInterface $params): void
    {
        $entityManager = $doctrine->getManager();


        // Delete subfolders
        foreach ($folder->getSubfolders() as $subfolder) {
            $this->deleteFolderAndContents($subfolder, $filesystem, $fileService, $doctrine, $params);
        }

        // Delete files in folder
        foreach ($folder->getFiles() as $file) {
            $fileService->deleteFile($file->getUniqueName(), $filesystem, $params);
            $entityManager->remove($file);
        }

        // Delete the folder from the database
        $entityManager->remove($folder);
        $entityManager->flush();
    }

    /**
     * @throws Exception
     */
    public function addFolderToZip($folder, $zip, $currentFolderPath = '', ParameterBagInterface $params): void
    {
        // First, create the folder in the ZIP (even if it's empty).
        if ($currentFolderPath) {
            $zip->addEmptyDir($currentFolderPath);
        }

        // Add files of the current folder to the ZIP
        foreach ($folder->getFiles() as $file) {
            $filePath = $params->get('ROOT_DIRECTORY') . '\\' . $file->getUniqueName();
            $destinationPath = $currentFolderPath ? $currentFolderPath . '/' . $file->getName() : $file->getName();
            $zip->addFile($filePath, $destinationPath);
        }

        foreach ($folder->getSubfolders() as $subfolder) {
            $subfolderPath = $currentFolderPath ? $currentFolderPath . '/' . $subfolder->getName() : $subfolder->getName();
            $this->addFolderToZip($subfolder, $zip, $subfolderPath, $params);
        }
    }
}


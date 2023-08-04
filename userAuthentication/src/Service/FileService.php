<?php
namespace App\Service;

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
}
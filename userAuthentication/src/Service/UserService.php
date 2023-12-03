<?php
namespace App\Service;

use App\Entity\Folder;
use App\Entity\File;
use App\Entity\BlacklistedToken;
use Symfony\Component\HttpFoundation\Request;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class UserService
{

    public function deleteFilesAndFoldersByUserId(int $userId, EntityManagerInterface $entityManager, ParameterBagInterface $params): void
    {
// Retrieve all files and folders associated with the user ID
        $files = $entityManager->getRepository(File::class)->findBy(['user' => $userId]);
        $folders = $entityManager->getRepository(Folder::class)->findBy(['user' => $userId]);

// Set the parent of all child folders to NULL
        foreach ($folders as $folder) {
            $childFolders = $entityManager->getRepository(Folder::class)->findBy(['parent' => $folder]);
            foreach ($childFolders as $childFolder) {
                $childFolder->setParent(null);
                $entityManager->persist($childFolder);
            }
        }

        $entityManager->flush();

// Delete each file and folder entry from the database
        foreach ($files as $file) {
            $entityManager->remove($file);
        }

        foreach ($folders as $folder) {
            $entityManager->remove($folder);
        }

// After removing all references in the database, flush the changes
        $entityManager->flush();

// Delete the actual ZFS dataset associated with the user ID
        $command = 'sudo /usr/sbin/zfs destroy -r ' . $params->get('ROOT_ZPOOL') . '/' . $userId;
//file_put_contents('/tmp/command.log', $command);
        exec($command, $output, $return_var);
        if ($return_var !== 0) {
// an error occurred
            error_log("Error destroying ZFS dataset: " . implode("\n", $output));
        }
    }

    /**
     * @param Request $request
     * @param ManagerRegistry $doctrine
     * @return float|int|mixed|string
     */
    public function getBToken(Request $request, ManagerRegistry $doctrine): mixed
    {
        $authorizationHeader = $request->headers->get('Authorization');
        $token = str_replace('Bearer ', '', $authorizationHeader);

        $bTokenRepository = $doctrine->getRepository(BlacklistedToken::class);
        $bToken = $bTokenRepository->createQueryBuilder('t')
            ->where('t.token LIKE :token')
            ->setParameter('token', '%' . $token . '%')
            ->getQuery()
            ->getResult();
        return $bToken;
    }
}
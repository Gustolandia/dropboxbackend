<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;


/**
 * @Route("/api/user", name="api_")
 */

class RegistrationController extends AbstractController
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params){
        $this->params = $params;
    }


    /**
     * @Route("/register", name="register", methods={"POST"})
     */
    public function index(ManagerRegistry $doctrine, Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {

        $em = $doctrine->getManager();
        $decoded = json_decode($request->getContent());
        $email = $decoded->email;
        $username = $decoded->username;
        $plaintextPassword = $decoded->password;

        $user = new User();
        $hashedPassword = $passwordHasher->hashPassword(
            $user,
            $plaintextPassword
        );
        $user->setPassword($hashedPassword);
        $user->setEmail($email);
        $user->setUsername($username);
        $em->persist($user);
        $em->flush();  // After this, $user will have its ID set if it's an auto-incremented field in the DB.

        $baseDir = $this->params->get('ROOT_DIRECTORY');
        $baseZ = $this->params->get('ROOT_ZPOOL');
        shell_exec('sudo /usr/sbin/zfs create ' . $baseZ . '/' . $user->getId());
        shell_exec('sudo /usr/local/bin/custom_chown.sh ' . escapeshellarg($baseDir . '/' . $user->getId()));

        $data = ['files' => [], 'folders' => []];
        $jsonData = json_encode($data);
        $path = $baseDir . '/' . $user->getId() . '/' . $user->getId() . '.json';
        file_put_contents($path, $jsonData);


        return $this->json(['message' => 'Registered Successfully']);
    }
}

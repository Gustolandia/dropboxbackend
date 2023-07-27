<?php

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\User;
use App\Entity\BlacklistedToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;



/**
 * @Route("/api/user", name="api_")
 */

class UserController extends AbstractController
{
    private $entityManager;

    private $doctrine;


    public function __construct(EntityManagerInterface $entityManager, ManagerRegistry $doctrine)
    {

        $this->entityManager = $entityManager;
        $this->doctrine = $doctrine;

    }

    /**
     * @Route("/me", name="me")
     */
    public function me(Request $request): Response
    {

        $bToken = $this->getBToken($request);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Access the authenticated user (if available)
        /** @var UserInterface|null $user */
        $user = $this->getUser();

        if ($user) {
            // You can now access the user information
            $username = $user->getUsername();
            $email = $user->getEmail();

            // You can return the user information as a JSON response or use it as needed
            return $this->json([
                'username' => $username,
                'email' => $email,
                'password' => '*******',
            ]);
        } else {
            // Handle unauthenticated requests
            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }
    }


    /**
     * Edit the current authenticated user.
     *
     * @param Request $request
     * @return Response
     *
     * * @Route("/edit", name="editUser", methods={"PUT"}))
     */
    public function editUser(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $bToken = $this->getBToken($request);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Ensure the authenticated user matches the user being edited

        /** @var UserInterface|null $user */
        $user = $this->getUser();
        $decoded = json_decode($request->getContent());

        // Perform validation and update the user entity properties as needed
        if (isset($decoded->email)) {
            $user->setEmail($decoded->email);
        }
        if (isset($decoded->username)) {
            $user->setUsername($decoded->username);
        }
        if (isset($decoded->password)) {
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $decoded->password
            );

            $user->setPassword($hashedPassword);
        }




        // Persist the changes in the database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Return a success response
        return new JsonResponse(['message' => 'User updated successfully']);
    }

    /**
     * Logout the current authenticated user and invalidate the JWT token.
     *
     * @Route("/logout", name="logout", methods={"POST"})
     */
    public function logout(Request $request)
    {
        $bToken = $this->getBToken($request);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }
        // Get the JWT token from the Authorization header in the request
        $authorizationHeader = $request->headers->get('Authorization');
        $token = str_replace('Bearer ', '', $authorizationHeader);

        if ($token !== null) {
            // Invalidate the user's current token by adding it to the blacklist
            $blacklistedToken = new BlacklistedToken();
            $blacklistedToken->setToken($token);

            $this->entityManager->persist($blacklistedToken);
            $this->entityManager->flush();
        }

        return $this->json(['message' => 'Logout successful']);
    }

    /**
     * @Route("/delete", name="delete", methods={"DELETE"})
     */
    public function delete(Request $request, UserInterface $user): JsonResponse
    {
        $bToken = $this->getBToken($request);
        if ($bToken !== []) {
            return new JsonResponse(['error' => 'Access denied' ], Response::HTTP_FORBIDDEN);
        }

        if ($user !== $this->getUser()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }


        // Remove the user entity
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // Return a success response
        return new JsonResponse(['message' => 'User deleted successfully']);
    }

    /**
     * @param Request $request
     * @return float|int|mixed|string
     */
    public function getBToken(Request $request): mixed
    {
        $authorizationHeader = $request->headers->get('Authorization');
        $token = str_replace('Bearer ', '', $authorizationHeader);

        $bTokenRepository = $this->doctrine->getRepository(BlacklistedToken::class);
        $bToken = $bTokenRepository->createQueryBuilder('t')
            ->where('t.token LIKE :token')
            ->setParameter('token', '%' . $token . '%')
            ->getQuery()
            ->getResult();
        return $bToken;
    }


}

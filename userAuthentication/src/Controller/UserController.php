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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;



/**
 * @Route("/api/user", name="api_")
 */

class UserController extends AbstractController
{
    private $entityManager;

    private $jwtTokenManager;
    private $tokenStorage;

    public function __construct(EntityManagerInterface $entityManager, JWTTokenManagerInterface $jwtTokenManager, TokenStorageInterface $tokenStorage)
    {

        $this->entityManager = $entityManager;
        $this->jwtTokenManager = $jwtTokenManager;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * @Route("/me", name="me")
     */
    public function me(Request $request): Response
    {

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
     * @param UserInterface $user
     * @return JsonResponse
     *
     * * @Route("/edit", name="editUser")
     */
    public function editUser(Request $request, UserInterface $user, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        // Ensure the authenticated user matches the user being edited
        if ($user !== $this->getUser()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get the data from the request body
        $requestData = json_decode($request->getContent(), true);

        // Perform validation and update the user entity properties as needed
        if (isset($requestData['username'])) {
            $user->setUsername($requestData['username']);
        }
        if (isset($requestData['email'])) {
            $user->setEmail($requestData['email']);
        }
        if (isset($requestData['password'])) {
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $requestData['password']
            );

            $user->setPassword($hashedPassword);
        }

        // Persist the changes in the database
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

        if ($user !== $this->getUser()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }


        // Remove the user entity
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // Return a success response
        return new JsonResponse(['message' => 'User deleted successfully']);
    }



}

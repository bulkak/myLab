<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AuthSessionService
{
    private const SESSION_USER_ID = 'auth_user_id';
    private const SESSION_EXPIRES = 'auth_expires_at';
    private const SESSION_LIFETIME = 604800; // 7 days

    private RequestStack $requestStack;
    private UserRepository $userRepository;

    public function __construct(RequestStack $requestStack, UserRepository $userRepository)
    {
        $this->requestStack = $requestStack;
        $this->userRepository = $userRepository;
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }

    /**
     * Login user - store in session
     */
    public function login(User $user): void
    {
        $session = $this->getSession();
        $session->set(self::SESSION_USER_ID, $user->getId());
        $session->set(self::SESSION_EXPIRES, time() + self::SESSION_LIFETIME);
        $session->migrate(true); // Regenerate session ID for security
    }

    /**
     * Logout user - clear session
     */
    public function logout(): void
    {
        $session = $this->getSession();
        $session->remove(self::SESSION_USER_ID);
        $session->remove(self::SESSION_EXPIRES);
        $session->invalidate();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool
    {
        $session = $this->getSession();
        
        if (!$session->has(self::SESSION_USER_ID)) {
            return false;
        }

        $expiresAt = $session->get(self::SESSION_EXPIRES, 0);
        if ($expiresAt < time()) {
            $this->logout();
            return false;
        }

        $userId = (int) $session->get(self::SESSION_USER_ID);
        if ($userId < 1 || $this->userRepository->find($userId) === null) {
            // Сессия после сброса БД или удаления пользователя — иначе петля / ↔ /auth/login
            $this->logout();
            return false;
        }

        // Extend session
        $session->set(self::SESSION_EXPIRES, time() + self::SESSION_LIFETIME);
        
        return true;
    }

    /**
     * Get current user ID from session
     */
    public function getUserId(): ?int
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return $this->getSession()->get(self::SESSION_USER_ID);
    }

    /**
     * Store temporary data during registration
     */
    public function setRegistrationData(string $username, string $secret): void
    {
        $session = $this->getSession();
        $session->set('registration_username', $username);
        $session->set('registration_secret', $secret);
        $session->set('registration_expires', time() + 600); // 10 minutes
    }

    /**
     * Get registration data
     *
     * @return array{username: string, secret: string}|null
     */
    public function getRegistrationData(): ?array
    {
        $session = $this->getSession();
        
        if (!$session->has('registration_username')) {
            return null;
        }

        $expires = $session->get('registration_expires', 0);
        if ($expires < time()) {
            $this->clearRegistrationData();
            return null;
        }

        return [
            'username' => $session->get('registration_username'),
            'secret' => $session->get('registration_secret'),
        ];
    }

    /**
     * Clear registration data
     */
    public function clearRegistrationData(): void
    {
        $session = $this->getSession();
        $session->remove('registration_username');
        $session->remove('registration_secret');
        $session->remove('registration_expires');
    }
}

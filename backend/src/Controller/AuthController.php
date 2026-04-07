<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthSessionService;
use App\Service\TOTPService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/auth')]
class AuthController extends AbstractController
{
    private TOTPService $totpService;
    private AuthSessionService $sessionService;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;
    private ValidatorInterface $validator;

    public function __construct(
        TOTPService $totpService,
        AuthSessionService $sessionService,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ) {
        $this->totpService = $totpService;
        $this->sessionService = $sessionService;
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
        $this->validator = $validator;
    }

    /**
     * Registration step 1 - enter username
     */
    #[Route('/register', name: 'auth_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        // Redirect if already logged in
        if ($this->sessionService->isLoggedIn()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;
        $username = '';

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            
            // Validate username
            if (empty($username)) {
                $error = 'Введите логин';
            } elseif (strlen($username) < 3) {
                $error = 'Логин должен содержать минимум 3 символа';
            } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
                $error = 'Логин может содержать только буквы, цифры, дефис и подчеркивание';
            } else {
                // Check if username exists
                $existingUser = $this->userRepository->findByUsername($username);
                if ($existingUser) {
                    $error = 'Этот логин уже занят';
                } else {
                    // Generate TOTP secret
                    $secret = $this->totpService->generateSecret();
                    
                    // Store in session for step 2
                    $this->sessionService->setRegistrationData($username, $secret);
                    
                    // Redirect to QR code page
                    return $this->redirectToRoute('auth_register_qr');
                }
            }
        }

        return $this->render('auth/register.html.twig', [
            'error' => $error,
            'username' => $username,
        ]);
    }

    /**
     * Registration step 2 - show QR code
     */
    #[Route('/register/qr', name: 'auth_register_qr', methods: ['GET'])]
    public function registerQR(): Response
    {
        // Redirect if already logged in
        if ($this->sessionService->isLoggedIn()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Get registration data from session
        $regData = $this->sessionService->getRegistrationData();
        if (!$regData) {
            return $this->redirectToRoute('auth_register');
        }

        $username = $regData['username'];
        $secret = $regData['secret'];

        // Generate QR code
        $qrCode = $this->totpService->generateQRCode($username, $secret);
        $secretDisplay = $this->totpService->getSecretForDisplay($secret);

        return $this->render('auth/register_qr.html.twig', [
            'username' => $username,
            'qrCode' => $qrCode,
            'secret' => $secretDisplay,
            'verifyUrl' => $this->generateUrl('auth_register_verify'),
        ]);
    }

    /**
     * Registration step 3 - verify code and complete
     */
    #[Route('/register/verify', name: 'auth_register_verify', methods: ['POST'])]
    public function registerVerify(Request $request): Response
    {
        // Redirect if already logged in
        if ($this->sessionService->isLoggedIn()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Get registration data from session
        $regData = $this->sessionService->getRegistrationData();
        if (!$regData) {
            return $this->redirectToRoute('auth_register');
        }

        $code = trim($request->request->get('code', ''));
        $error = null;

        // Validate code format
        if (!$this->totpService->isValidCodeFormat($code)) {
            $error = 'Введите 6-значный код';
        } else {
            // Verify code
            if (!$this->totpService->checkCode($regData['secret'], $code)) {
                $error = 'Неверный код. Попробуйте еще раз.';
            } else {
                // Create user
                $user = new User();
                $user->setUsername($regData['username']);
                $user->setTotpSecret($regData['secret']);
                $user->setStatus(User::STATUS_ACTIVE);

                $errors = $this->validator->validate($user);
                if (count($errors) > 0) {
                    $error = 'Ошибка валидации: ' . $errors[0]->getMessage();
                } else {
                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    // Clear registration data
                    $this->sessionService->clearRegistrationData();

                    // Login user
                    $this->sessionService->login($user);

                    return $this->redirectToRoute('app_dashboard');
                }
            }
        }

        // If we got here, verification failed
        $qrCode = $this->totpService->generateQRCode($regData['username'], $regData['secret']);
        $secretDisplay = $this->totpService->getSecretForDisplay($regData['secret']);

        return $this->render('auth/register_qr.html.twig', [
            'username' => $regData['username'],
            'qrCode' => $qrCode,
            'secret' => $secretDisplay,
            'verifyUrl' => $this->generateUrl('auth_register_verify'),
            'error' => $error,
        ]);
    }

    /**
     * Login page
     */
    #[Route('/login', name: 'auth_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        // Redirect if already logged in
        if ($this->sessionService->isLoggedIn()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;
        $username = '';

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            $code = trim($request->request->get('code', ''));

            if (empty($username)) {
                $error = 'Введите логин';
            } elseif (!$this->totpService->isValidCodeFormat($code)) {
                $error = 'Введите 6-значный код из приложения';
            } else {
                // Find user
                $user = $this->userRepository->findByUsername($username);
                
                if (!$user) {
                    $error = 'Пользователь не найден';
                } elseif ($user->getStatus() !== User::STATUS_ACTIVE) {
                    $error = 'Аккаунт деактивирован';
                } else {
                    // Verify TOTP code
                    if (!$this->totpService->checkCode($user->getTotpSecret(), $code)) {
                        $error = 'Неверный код. Попробуйте еще раз.';
                    } else {
                        // Login successful
                        $this->sessionService->login($user);
                        
                        return $this->redirectToRoute('app_dashboard');
                    }
                }
            }
        }

        return $this->render('auth/login.html.twig', [
            'error' => $error,
            'username' => $username,
        ]);
    }

    /**
     * Logout
     */
    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->sessionService->logout();
        
        return $this->redirectToRoute('auth_login');
    }
}

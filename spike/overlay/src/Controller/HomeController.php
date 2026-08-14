<?php

declare(strict_types=1);

namespace App\Controller;

use App\Native\WindowManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly WindowManager $windows)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        $window = [];
        $error = null;

        try {
            $window = $this->windows->get('main');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->render('home.html.twig', [
            'window' => $window,
            'error' => $error,
            'php' => \PHP_VERSION,
            'symfony' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'inRuntime' => filter_var($_ENV['NATIVEPHP_RUNNING'] ?? 'false', \FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    #[Route('/resize/{width}/{height}', name: 'resize', requirements: ['width' => '\d+', 'height' => '\d+'], methods: ['GET'])]
    public function resize(int $width, int $height): RedirectResponse
    {
        $this->windows->resize($width, $height);

        return $this->redirectToRoute('home');
    }

    #[Route('/retitle', name: 'retitle', methods: ['GET'])]
    public function retitle(): RedirectResponse
    {
        $this->windows->title('Retitled from Symfony at '.date('H:i:s'));

        return $this->redirectToRoute('home');
    }
}

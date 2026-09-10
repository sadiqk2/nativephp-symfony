<?php

namespace App\Controller;

use Native\Symfony\Desktop\Contract\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function __invoke(ClientInterface $native): Response
    {
        return $this->render('home.html.twig', [
            // isAvailable() is false for an ordinary `php -S` request and true once
            // the Electron runtime has set NATIVEPHP_API_URL — the one line an app
            // needs to tell "running natively" from "running in a browser".
            'runtimeConnected' => $native->isAvailable(),
        ]);
    }
}

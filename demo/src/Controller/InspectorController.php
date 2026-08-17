<?php

declare(strict_types=1);

namespace App\Controller;

use App\Note\NoteStore;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Window\WindowManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The second window's page.
 *
 * Two windows, one PHP app: the runtime runs a single `php -S` server and both
 * windows are just pages in it. What tells them apart is the `_windowId` query
 * parameter the runtime appends to every navigation it performs — so this page and
 * the dashboard render the same helper and print different answers.
 *
 * It also demonstrates the app→renderer direction: this window has no idea when a
 * note is saved in the other one, until the broadcast arrives.
 */
final class InspectorController extends AbstractController
{
    public function __construct(
        private readonly WindowManager $windows,
        private readonly ClientInterface $client,
        private readonly NoteStore $notes,
    ) {
    }

    #[Route('/inspector', name: 'inspector', methods: ['GET'])]
    public function index(): Response
    {
        $available = $this->client->isAvailable();

        return $this->render('inspector.html.twig', [
            'available' => $available,
            'windowId' => $this->windows->detectId(),
            'windows' => $available ? $this->windows->all() : [],
            'noteCount' => $available ? $this->notes->count() : 0,
        ]);
    }

    /**
     * Opening a window from a page rather than from boot().
     *
     * Same call the menu handler makes, and idempotent for the same reason: a second
     * click shows and focuses the existing window instead of stacking another.
     */
    #[Route('/inspector/open', name: 'inspector_open', methods: ['POST'])]
    public function open(): Response
    {
        $this->windows->open('inspector')
            ->url('/inspector')
            ->size(520, 620)
            ->position(870, 170)
            ->title('Deskpad — Inspector')
            ->showDevTools(false)
            ->open();

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/inspector/close', name: 'inspector_close', methods: ['POST'])]
    public function close(): Response
    {
        // An unknown id is a no-op rather than an error: the runtime uses optional
        // chaining on its window map, so closing a window that is already gone is
        // safe and a typo'd id is silent. Worth knowing before relying on the call.
        $this->windows->close('inspector');

        return $this->redirectToRoute('dashboard');
    }
}

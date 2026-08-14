<?php

declare(strict_types=1);

namespace App\Controller;

use App\Note\NoteStore;
use Native\Symfony\App\AppManager;
use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Screen\ScreenManager;
use Native\Symfony\Support\NativePaths;
use Native\Symfony\System\RuntimeInfo;
use Native\Symfony\System\SystemManager;
use Native\Symfony\Window\WindowManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The first screen: what the app knows about itself.
 *
 * Every value on this page is a live round trip to the Electron process over
 * localhost HTTP with a shared secret — there is no cached snapshot and no mock.
 * If the runtime is not there, `ClientInterface::isAvailable()` says so and the
 * page renders the same, which is the behaviour a real app wants: a native app
 * whose pages 500 outside the runtime cannot be developed in a browser.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly WindowManager $windows,
        private readonly AppManager $app,
        private readonly NativePaths $paths,
        private readonly SystemManager $system,
        private readonly ScreenManager $screen,
        private readonly RuntimeInfo $runtime,
        private readonly NoteStore $notes,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $available = $this->client->isAvailable();

        return $this->render('dashboard.html.twig', [
            'available' => $available,
            // detectId() reads ?_windowId= from the Referer first and the current
            // URL second. That order matters: a form POST carries the window id in
            // its Referer, never in its own URL.
            'windowId' => $this->windows->detectId(),
            'window' => $available ? $this->windows->get('main') : null,
            'windows' => $available ? $this->windows->all() : [],
            'noteCount' => $available ? $this->notes->count() : 0,
            'facts' => $available ? $this->facts() : [],
        ]);
    }

    /**
     * A handful of endpoints from different routers, called for real.
     *
     * Deliberately not one call per endpoint: the bundle's own test suite proves
     * all 116 are wired, and a screen with 116 rows on it demonstrates nothing a
     * reader could not read in CONTRACT.md.
     *
     * @return array<string, string>
     */
    private function facts(): array
    {
        $process = $this->runtime->get();
        $primary = $this->screen->primaryDisplay();

        return [
            'Electron app version' => $this->app->version(),
            'Electron process' => sprintf(
                'pid %d, %s/%s, up %ds',
                $process['pid'],
                $process['platform'],
                $process['arch'],
                (int) $process['uptime'],
            ),
            'System theme' => $this->system->theme()->value,
            'Safe storage' => $this->system->canEncrypt() ? 'available' : 'unavailable',
            'App locale' => $this->app->locale() ?: '—',
            'Primary display' => sprintf(
                '%s × %s, scale %s',
                $primary['size']['width'] ?? '?',
                $primary['size']['height'] ?? '?',
                $primary['scaleFactor'] ?? '1',
            ),
            'Displays' => (string) \count($this->screen->displays()),
            'userData path' => $this->paths->userData() ?? '—',
            'PHP' => \PHP_VERSION.' ('.\PHP_SAPI.')',
            'Symfony' => \Symfony\Component\HttpKernel\Kernel::VERSION,
        ];
    }
}

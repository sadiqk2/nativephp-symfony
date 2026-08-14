<?php

declare(strict_types=1);

namespace App\Controller;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Process\ChildProcessManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Background work, which on the desktop is a real child process rather than a queue.
 *
 * The runtime spawns it, keeps it, and streams its output back as events — so the
 * page can watch a process it did not start and will outlive. Three things are worth
 * noticing on this screen:
 *
 *  - `php()` runs the *runtime's own* PHP binary, with the whole NATIVEPHP_*
 *    environment injected, so a child can call back into the runtime as a peer.
 *  - `console()` is the same thing pointed at bin/console, which is how a desktop
 *    app runs its own long jobs without shipping a second entry point.
 *  - stdout arrives as MessageReceived, one event per chunk, in both PHP and the
 *    renderer. The live log below is the renderer half; var/log/dev.log is the PHP
 *    half, from the same events.
 */
final class JobController extends AbstractController
{
    public function __construct(
        private readonly ChildProcessManager $processes,
        private readonly ClientInterface $client,
    ) {
    }

    #[Route('/jobs', name: 'jobs', methods: ['GET'])]
    public function index(): Response
    {
        $available = $this->client->isAvailable();

        return $this->render('jobs.html.twig', [
            'available' => $available,
            'processes' => $available ? $this->processes->all() : [],
        ]);
    }

    #[Route('/jobs/counter', name: 'job_counter', methods: ['POST'])]
    public function counter(): Response
    {
        // A short PHP one-liner rather than a script file: it keeps what the child
        // does visible next to the call that starts it, and it proves the binary,
        // the ini flags and the stdout event path in one go.
        $this->processes->php('counter', ['-r', <<<'PHP'
            for ($i = 1; $i <= 5; $i++) {
                echo "tick {$i} of 5 from pid ", getmypid(), "\n";
                usleep(400000);
            }
            echo "done\n";
            PHP]);

        return $this->redirectToRoute('jobs');
    }

    #[Route('/jobs/report', name: 'job_report', methods: ['POST'])]
    public function report(): Response
    {
        // The app's own console command, run as a child process. `console()` is
        // `php()` with bin/console prepended — and the reason the bundle has to know
        // the CLI's name at all, since upstream hardcodes `artisan` in six places.
        $this->processes->console('report', ['app:report', '--rows=4']);

        return $this->redirectToRoute('jobs');
    }

    #[Route('/jobs/{alias}/stop', name: 'job_stop', methods: ['POST'])]
    public function stop(string $alias): Response
    {
        $this->processes->stop($alias);

        return $this->redirectToRoute('jobs');
    }
}

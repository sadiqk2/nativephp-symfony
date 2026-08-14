<?php

declare(strict_types=1);

namespace App\Controller;

use App\Event\NoteSaved;
use App\Note\NoteStore;
use Native\Symfony\Clipboard\ClipboardManager;
use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Dialog\DialogManager;
use Native\Symfony\Notification\NotificationManager;
use Native\Symfony\Window\WindowManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The app's one real feature, and the screen most of the desktop surface hangs off.
 *
 * Saving a note touches four parts of the runtime at once, which is the point:
 * the settings store (persistence), a notification, a broadcast that every other
 * window hears, and — on export — a native save dialog.
 */
final class NoteController extends AbstractController
{
    public function __construct(
        private readonly NoteStore $notes,
        private readonly ClientInterface $client,
        private readonly ClipboardManager $clipboard,
        private readonly NotificationManager $notifications,
        private readonly DialogManager $dialogs,
        private readonly WindowManager $windows,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    #[Route('/notes', name: 'notes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $available = $this->client->isAvailable();

        return $this->render('notes.html.twig', [
            'available' => $available,
            'notes' => $available ? $this->notes->all() : [],
            'windowId' => $this->windows->detectId(),
            // Set when the menu item navigated here, so the form can take focus:
            // the menu's only job is to say what happened, the page decides how to
            // look about it.
            'focusForm' => $request->query->has('new'),
            'flash' => $request->query->get('flash'),
        ]);
    }

    #[Route('/notes', name: 'note_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $title = trim((string) $request->request->get('title'));
        $body = trim((string) $request->request->get('body'));

        if ('' === $title) {
            return $this->redirectToRoute('notes', ['flash' => 'A note needs a title.']);
        }

        $note = $this->notes->add($title, $body);

        $this->notifications->create()
            ->title('Note saved')
            ->body($note->title)
            // A reference comes back on NotificationClicked, which is how a click on
            // a toast is traced to the thing it was about.
            ->reference('note:'.$note->id)
            ->show();

        // Reaches every open window's JavaScript, because NoteSaved implements
        // BroadcastsToRuntime and the bundle decorates the dispatcher. Open the
        // inspector window and watch it update while you type here.
        $this->dispatcher->dispatch(new NoteSaved($note->title, $this->notes->count()));

        return $this->redirectToRoute('notes', ['flash' => 'Saved “'.$note->title.'”.']);
    }

    #[Route('/notes/{id}/copy', name: 'note_copy', methods: ['POST'])]
    public function copy(string $id): Response
    {
        $note = $this->notes->find($id);

        if (null === $note) {
            return $this->redirectToRoute('notes', ['flash' => 'That note is gone.']);
        }

        $this->clipboard->setText($note->asText());

        // Read back through the runtime rather than trusting the write: the
        // clipboard is shared with every other process on the machine, so "did it
        // land" is a real question and the endpoint can answer it.
        $readBack = $this->clipboard->text();

        return $this->redirectToRoute('notes', [
            'flash' => sprintf('Clipboard now holds %d characters.', \strlen($readBack)),
        ]);
    }

    #[Route('/notes/{id}/delete', name: 'note_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $this->notes->remove($id);

        return $this->redirectToRoute('notes', ['flash' => 'Deleted.']);
    }

    /**
     * The one deliberately blocking call in the app.
     *
     * A native dialog runs on the runtime's main thread and does not return until a
     * human answers it, so this request stays open for as long as the sheet is up.
     * That is correct behaviour and also why nothing automated drives this route:
     * an unattended run would hang here rather than fail, which is the worse of the
     * two outcomes.
     */
    #[Route('/notes/{id}/export', name: 'note_export', methods: ['POST'])]
    public function export(string $id): Response
    {
        $note = $this->notes->find($id);

        if (null === $note) {
            return $this->redirectToRoute('notes', ['flash' => 'That note is gone.']);
        }

        $path = $this->dialogs->save()
            ->title('Export note')
            ->defaultPath($this->slug($note->title).'.txt')
            ->buttonLabel('Export')
            ->confirmOverwrite()
            ->save();

        if (null === $path) {
            // A cancelled dialog is a null, not an exception and not an empty
            // string — a distinction worth keeping, because "the user said no" and
            // "something went wrong" want different UI.
            return $this->redirectToRoute('notes', ['flash' => 'Export cancelled.']);
        }

        file_put_contents($path, $note->asText()."\n");

        return $this->redirectToRoute('notes', ['flash' => 'Exported to '.$path]);
    }

    private function slug(string $title): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? 'note');

        return trim($slug, '-') ?: 'note';
    }
}

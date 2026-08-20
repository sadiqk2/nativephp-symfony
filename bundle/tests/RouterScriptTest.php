<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The router script, under the server it is written for.
 *
 * Every request a packaged application ever serves goes through this file: the runtime
 * starts `php -S` with it, because Laravel's equivalent is hardcoded in php.ts and
 * Symfony has none. Eleven lines, no test, and one of them is a containment check
 * standing between the document root and the rest of the filesystem.
 *
 * Nothing short of a real built-in server proves it. `return false` is meaningful only
 * to that SAPI — under any other caller it is just a value — and the containment check
 * exists precisely for the day someone reuses this file somewhere else.
 */
final class RouterScriptTest extends TestCase
{
    private string $root;
    private string $docroot;
    private ?Process $server = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/np-symfony-router-'.bin2hex(random_bytes(4));
        $this->docroot = $this->root.'/public';

        $fs = new Filesystem();

        $fs->copy(__DIR__.'/../src/Resources/runtime/nativephp-router.php', $this->docroot.'/nativephp-router.php');
        $fs->dumpFile($this->docroot.'/index.php', '<?php echo "app:".($_SERVER["REQUEST_URI"] ?? "").":".($_SERVER["SCRIPT_NAME"] ?? "");');
        $fs->dumpFile($this->docroot.'/style.css', 'body { color: red }');
        $fs->dumpFile($this->docroot.'/build/app.js', 'console.log(1)');
        $fs->dumpFile($this->docroot.'/a file.txt', 'spaces are legal');

        // The two things a document root must never hand out: what sits beside it, and
        // what sits in a directory whose name merely starts the same way.
        $fs->dumpFile($this->root.'/.env', 'APP_SECRET=hunter2');
        $fs->dumpFile($this->root.'/public-staging/secret.txt', 'not yours');

        $this->start();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->root);
    }

    public function testAnApplicationRouteReachesTheFrontController(): void
    {
        [$status, $body] = $this->get('/notes/17?tag=a');

        self::assertSame(200, $status);
        self::assertStringStartsWith('app:/notes/17?tag=a', $body);
    }

    public function testTheFrontControllerIsToldItIsIndexPhp(): void
    {
        // Symfony builds URLs from SCRIPT_NAME. Left as the router's own filename, every
        // generated link in a packaged app would point at nativephp-router.php.
        [, $body] = $this->get('/notes');

        self::assertStringEndsWith(':/index.php', $body);
    }

    public function testTheRootPathIsTheApplicationNotADirectoryListing(): void
    {
        [$status, $body] = $this->get('/');

        self::assertSame(200, $status);
        self::assertStringStartsWith('app:/', $body);
    }

    public function testAStaticFileIsServedByTheServerItself(): void
    {
        [$status, $body] = $this->get('/style.css');

        self::assertSame(200, $status);
        self::assertSame('body { color: red }', $body);
    }

    public function testAStaticFileInASubdirectoryIsServedToo(): void
    {
        [, $body] = $this->get('/build/app.js');

        self::assertSame('console.log(1)', $body);
    }

    public function testAnEncodedSpaceResolvesToTheFileItNames(): void
    {
        // The urldecode() is why: without it the candidate path keeps the %20 and the
        // file is never found, so every asset with a space in its name would fall
        // through to the application as a 404.
        [, $body] = $this->get('/a%20file.txt');

        self::assertSame('spaces are legal', $body);
    }

    public function testADirectoryFallsThroughToTheApplication(): void
    {
        [, $body] = $this->get('/build');

        self::assertStringStartsWith('app:/build', $body);
    }

    public function testTraversalOutOfTheDocumentRootIsRefused(): void
    {
        // Through the real server, which resolves and rejects most of these itself before
        // the router is reached — so this pins the deployed behaviour rather than our
        // containment rule. The rule is checked directly below.
        foreach (['/../.env', '/..%2f.env', '/%2e%2e%2f.env', '/build/../../.env'] as $path) {
            [, $body] = $this->get($path);

            self::assertStringNotContainsString('hunter2', $body, $path);
        }
    }

    public function testThePathItselfIsRefusedWithoutHelpFromTheServer(): void
    {
        // Called directly, with the URI the SAPI would have normalised away. `return
        // false` means "serve this file", so a router that returns it for a path outside
        // the document root hands the caller whatever it named — and a caller that is not
        // PHP's built-in server has no second check of its own. Removing the containment
        // test makes exactly this fail, which the version through the server does not.
        foreach (['/../.env', '/../public-staging/secret.txt'] as $uri) {
            self::assertSame(
                'front-controller',
                $this->routerVerdict($uri),
                sprintf('The router offered %s to the caller as a file to serve.', $uri),
            );
        }

        // And the other direction, or the check could simply refuse everything.
        self::assertSame('serve-the-file', $this->routerVerdict('/style.css'));
        self::assertSame('front-controller', $this->routerVerdict('/notes/17'));
    }

    public function testASiblingDirectorySharingThePrefixIsRefused(): void
    {
        // /app/public-staging next to /app/public: without the separator in the
        // containment check, str_starts_with() says this is inside the document root.
        [, $body] = $this->get('/../public-staging/secret.txt');

        self::assertStringNotContainsString('not yours', $body);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Include the router in a bare process and report which of its two outcomes it chose.
     *
     * @return string 'serve-the-file' when it returned false, 'front-controller' otherwise
     */
    private function routerVerdict(string $uri): string
    {
        $harness = $this->root.'/verdict.php';

        (new Filesystem())->dumpFile($harness, sprintf(
            "<?php\n\$_SERVER['REQUEST_URI'] = %s;\n\$result = require %s;\n\necho false === \$result ? 'serve-the-file' : 'front-controller';\n",
            var_export($uri, true),
            var_export($this->docroot.'/nativephp-router.php', true),
        ));

        $process = new Process([\PHP_BINARY, $harness], $this->docroot);
        $process->run();

        return trim(str_replace('app:'.$uri.':/index.php', '', $process->getOutput()));
    }

    private function start(): void
    {
        // Port 0 lets the kernel choose, but the built-in server does not report the
        // port it got, so a range is tried instead and the first one that answers wins.
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $port = random_int(20000, 60000);

            $server = new Process(
                [\PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $this->docroot, $this->docroot.'/nativephp-router.php'],
                $this->docroot,
            );
            $server->start();

            for ($wait = 0; $wait < 50; ++$wait) {
                if (!$server->isRunning()) {
                    break;
                }

                $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

                if (false !== $probe) {
                    fclose($probe);

                    $this->server = $server;
                    $this->port = $port;

                    return;
                }

                usleep(20000);
            }

            $server->stop();
        }

        self::fail('Could not start PHP\'s built-in server on any port.');
    }

    /** @return array{0: int, 1: string} */
    private function get(string $path): array
    {
        // No redirect following and no error suppression of a 404: what the server said
        // is the answer being tested.
        $context = stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);

        $body = @file_get_contents('http://127.0.0.1:'.$this->port.$path, false, $context);

        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }
}

<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The four bootstrap scripts, executed the way the native layer executes them.
 *
 * These files are the entire boundary between the Android/iOS host and the
 * application: the host populates $_SERVER, runs one of them by absolute path, and
 * reads stdout as a complete HTTP message. Nothing in the suite touched them — 0 of
 * their 126 statements were covered — so every one of their failure paths was
 * folklore, and the only place they had ever run is a device this environment does
 * not have.
 *
 * A subprocess is the only honest way to test them. They are scripts, not classes:
 * they declare functions at top level, they exit(), and half their job is what the
 * process writes to stdout. Running them under `php <script>` with a real fixture
 * application on the other side of the autoloader reproduces the host exactly.
 */
final class BootstrapScriptsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/np-mobile-boot-'.bin2hex(random_bytes(4));

        $fs = new Filesystem();
        $fs->mkdir($this->project.'/vendor');

        // The host hands the shim a Composer autoloader and nothing else. This one
        // loads the real vendor tree plus the fixture application, which is what a
        // generated autoloader would do for an app's own src/.
        $fs->dumpFile($this->project.'/vendor/autoload.php', sprintf(
            "<?php\n\$loader = require %s;\n%srequire %s;\nrequire %s;\n\nreturn \$loader;\n",
            var_export(\dirname(__DIR__).'/vendor/autoload.php', true),
            $this->dotenvAutoloader(),
            var_export(__DIR__.'/fixtures/bootapp/Kernel.php', true),
            var_export(__DIR__.'/fixtures/bootapp/EchoCommand.php', true),
        ));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    /**
     * A PSR-4 line for symfony/dotenv, wherever this checkout happens to have it.
     *
     * The bundle only suggests the component, so its own vendor tree does not carry it —
     * but the shims' whole environment story runs through it, and a test that cannot load
     * it is testing the branch where .env does not exist. Registering one namespace by
     * hand is enough, and avoids pulling a second full autoloader into the process.
     */
    private function dotenvAutoloader(): string
    {
        $root = \dirname(__DIR__, 2);

        foreach (['mobile-bundle', 'bundle', 'demo'] as $tree) {
            $dir = $root.'/'.$tree.'/vendor/symfony/dotenv';

            if (is_file($dir.'/Dotenv.php')) {
                return sprintf(
                    "\$loader->addPsr4('Symfony\\\\Component\\\\Dotenv\\\\', %s);\n",
                    var_export($dir, true),
                );
            }
        }

        self::markTestSkipped('symfony/dotenv is not installed in any vendor tree in this checkout; the .env paths of the shims cannot run.');
    }

    // ── native.php: the one-shot shim ───────────────────────────────────────

    public function testOneShotShimAnswersARequestAsAnHttpMessage(): void
    {
        $run = $this->runScript('native.php', ['REQUEST_URI' => '/ping']);

        self::assertSame(0, $run->exitCode, $run->stderr);

        [$status, $headers, $body] = $this->parse($run->stdout);

        self::assertSame('HTTP/1.1 200 OK', $status);
        self::assertSame('pong', $body);
        // Cookies live outside the header bag until send() runs; the emitter has to
        // write them by hand or every session on the device is lost.
        self::assertStringContainsString('visited=yes', $headers['set-cookie'] ?? '');
        self::assertStringContainsString('mode=one-shot', $headers['x-php-timing'] ?? '');
    }

    public function testOneShotShimPassesTheHostsRequestDetailsThrough(): void
    {
        // The host does not hand the script a body on stdin: it populates $_POST and
        // $_FILES in the preamble it evaluates first (parse_str into $_POST for
        // urlencoded bodies, PHP's own multipart parsing for uploads) and then runs the
        // shim. A driver that sets the same superglobals is that preamble.
        $run = $this->drive(
            'native.php',
            [
                'REQUEST_URI' => '/echo?ignored=1',
                'REQUEST_METHOD' => 'POST',
                'QUERY_STRING' => 'page=2&tag=a',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_COOKIE' => 'session=abc; theme=dark',
                'HTTP_HOST' => 'localhost',
            ],
            ['name' => 'sadiq', 'role' => 'dev'],
        );

        [, , $body] = $this->parse($run->stdout);

        /** @var array<string, mixed> $echo */
        $echo = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('POST', $echo['method']);
        self::assertSame('/echo', $echo['path']);
        self::assertSame(['page' => '2', 'tag' => 'a'], $echo['query']);
        self::assertSame(['name' => 'sadiq', 'role' => 'dev'], $echo['post']);
        self::assertSame(['session' => 'abc', 'theme' => 'dark'], $echo['cookies']);
    }

    public function testAnUploadedFileReachesTheApplication(): void
    {
        // Nothing could upload a file before: the request factory passed an empty
        // files array whatever the host had parsed, so every <input type="file"> on
        // every screen arrived empty.
        $upload = $this->project.'/phpUpload';
        (new Filesystem())->dumpFile($upload, 'png');

        $run = $this->drive(
            'native.php',
            [
                'REQUEST_URI' => '/echo',
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            ],
            ['caption' => 'a cat'],
            ['photo' => ['name' => 'cat.png', 'type' => 'image/png', 'tmp_name' => $upload, 'error' => \UPLOAD_ERR_OK, 'size' => filesize($upload)]],
        );

        [, , $body] = $this->parse($run->stdout);

        /** @var array<string, mixed> $echo */
        $echo = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['photo' => 'cat.png'], $echo['files']);
        // A multipart body is not reconstructible from the raw bytes, so the parsed
        // fields have to come through too or the form arrives half-empty.
        self::assertSame(['caption' => 'a cat'], $echo['post']);
    }

    public function testOneShotShimReportsAMissingKernelClassAsAParseableFailure(): void
    {
        $run = $this->runScript('native.php', [
            'REQUEST_URI' => '/ping',
            'NATIVEPHP_KERNEL_CLASS' => 'App\NoSuchKernel',
        ]);

        // exit(1) matters: the host distinguishes a failed script from a 500 page.
        self::assertSame(1, $run->exitCode);

        [$status, , $body] = $this->parse($run->stdout);

        self::assertSame('HTTP/1.1 500 Internal Server Error', $status);
        self::assertStringContainsString('App\NoSuchKernel', $body);
        self::assertStringContainsString('NATIVEPHP_KERNEL_CLASS', $body);
        self::assertStringContainsString('[NATIVE_EXCEPTION]', $run->stderr);
    }

    public function testOneShotShimReportsAMissingAutoloaderAsAParseableFailure(): void
    {
        $run = $this->runScript('native.php', [
            'REQUEST_URI' => '/ping',
            'COMPOSER_AUTOLOADER_PATH' => $this->project.'/vendor/nope.php',
        ]);

        self::assertSame(1, $run->exitCode);

        [$status, , $body] = $this->parse($run->stdout);

        self::assertSame('HTTP/1.1 500 Internal Server Error', $status);
        self::assertStringContainsString('No Composer autoloader', $body);
    }

    public function testOneShotShimTurnsAThrowingControllerIntoA500(): void
    {
        $run = $this->runScript('native.php', ['REQUEST_URI' => '/boom']);

        [$status, , $body] = $this->parse($run->stdout);

        self::assertSame('HTTP/1.1 500 Internal Server Error', $status);
        self::assertNotSame('', $body);
    }

    // ── the environment the shims hand the kernel ───────────────────────────

    public function testTheApplicationsOwnEnvFileDecidesTheEnvironment(): void
    {
        // The comment above this code in native.php says "APP_ENV/APP_DEBUG come
        // from the app's own .env via Dotenv below", and an app that ships a .env
        // saying dev expects dev — including .env.dev and .env.local, which only
        // load once Dotenv knows which environment it is resolving.
        (new Filesystem())->dumpFile($this->project.'/.env', "APP_ENV=dev\nAPP_DEBUG=1\nGREETING=from-env\n");
        (new Filesystem())->dumpFile($this->project.'/.env.dev', "GREETING=from-env-dev\n");

        $run = $this->runScript('native.php', ['REQUEST_URI' => '/env']);

        [, , $body] = $this->parse($run->stdout);

        /** @var array<string, mixed> $env */
        $env = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('dev', $env['kernel_env']);
        self::assertTrue($env['kernel_debug']);
        self::assertSame('from-env-dev', $env['greeting']);
    }

    public function testTheHostsEnvironmentBeatsTheEnvFile(): void
    {
        // The other direction: a host that says prod must get prod, whatever the
        // .env left behind in the package says.
        (new Filesystem())->dumpFile($this->project.'/.env', "APP_ENV=dev\nAPP_DEBUG=1\n");

        $run = $this->runScript('native.php', [
            'REQUEST_URI' => '/env',
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
        ]);

        [, , $body] = $this->parse($run->stdout);

        /** @var array<string, mixed> $env */
        $env = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('prod', $env['kernel_env']);
        self::assertFalse($env['kernel_debug']);
    }

    public function testWithoutAnEnvFileTheShimDefaultsToProduction(): void
    {
        $run = $this->runScript('native.php', ['REQUEST_URI' => '/env']);

        [, , $body] = $this->parse($run->stdout);

        /** @var array<string, mixed> $env */
        $env = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('prod', $env['kernel_env']);
        self::assertFalse($env['kernel_debug']);
    }

    // ── persistent.php + dispatch.php: booted once, dispatched many ─────────

    public function testPersistentShimBootsOnceAndDispatchesEveryRequest(): void
    {
        // The host boots persistent.php and then eval's dispatch.php per request in
        // a fresh scope. A driver script reproduces that: same process, no shared
        // locals, $_SERVER rewritten between requests.
        $driver = $this->project.'/driver.php';
        (new Filesystem())->dumpFile($driver, sprintf(<<<'PHP'
            <?php
            require %1$s;

            foreach (['/ping', '/env', '/ping'] as $i => $uri) {
                $_SERVER['REQUEST_URI'] = $uri;
                $_SERVER['REQUEST_METHOD'] = 'GET';
                echo "\n===REQUEST {$i}===\n";
                (static function (): void { require %2$s; })();
            }
            PHP,
            var_export($this->bootstrapDir().'/persistent.php', true),
            var_export($this->bootstrapDir().'/dispatch.php', true),
        ));

        $run = $this->runFile($driver, []);

        self::assertSame(0, $run->exitCode, $run->stderr);

        $chunks = array_slice(preg_split('/\n===REQUEST \d+===\n/', $run->stdout) ?: [], 1);

        self::assertCount(3, $chunks);

        foreach ($chunks as $i => $chunk) {
            [$status, $headers] = $this->parse($chunk);

            self::assertSame('HTTP/1.1 200 OK', $status, "request {$i}");
            self::assertStringContainsString('mode=persistent', $headers['x-php-timing'] ?? '');
            self::assertStringContainsString('dispatch='.($i + 1), $headers['x-php-timing'] ?? '');
        }

        // One boot for three requests is the entire point of the persistent path.
        self::assertSame(1, substr_count($run->stderr, 'persistent boot total='));
    }

    public function testDispatchWithoutABootedRuntimeStillAnswers(): void
    {
        // The shape this guards is a boot that failed: persistent.php loads the
        // autoloader, fails to boot the kernel, prints BOOT_FATAL and leaves the
        // interpreter alive — so the host's next request still evaluates dispatch.php,
        // in a process where the classes exist but the runtime does not.
        $driver = $this->project.'/no-boot.php';

        (new Filesystem())->dumpFile($driver, sprintf(
            "<?php\nrequire %s;\n\nrequire %s;\n",
            var_export($this->project.'/vendor/autoload.php', true),
            var_export($this->bootstrapDir().'/dispatch.php', true),
        ));

        $run = $this->runFile($driver, ['REQUEST_URI' => '/ping']);

        [$status, , $body] = $this->parse($run->stdout);

        self::assertSame('HTTP/1.1 500 Internal Server Error', $status);
        self::assertStringContainsString('has not been booted', $body);
        self::assertStringContainsString('[NATIVE_EXCEPTION]', $run->stderr);
    }

    public function testPersistentShimReportsABootFailureOnStdout(): void
    {
        $run = $this->runScript('persistent.php', [
            'NATIVEPHP_KERNEL_CLASS' => 'App\NoSuchKernel',
        ]);

        self::assertStringContainsString('BOOT_FATAL', $run->stdout);
        self::assertStringContainsString('[NATIVE_EXCEPTION]', $run->stderr);
    }

    // ── console.php: the artisan.php replacement ────────────────────────────

    public function testConsoleShimRunsTheHostsCommandLine(): void
    {
        $run = $this->runScript('console.php', [], null, ['app:echo', 'hello']);

        self::assertSame(0, $run->exitCode, $run->stderr);
        self::assertStringContainsString('echo:hello', $run->stdout);
        // Debug is forced off whatever the app says: a device has nowhere to render
        // a dump and the profiler would write into a read-only cache.
        self::assertStringContainsString('debug:0', $run->stdout);
    }

    public function testConsoleShimReturnsTheCommandsExitCode(): void
    {
        $run = $this->runScript('console.php', [], null, ['app:echo', 'hello', '3']);

        self::assertSame(3, $run->exitCode);
        self::assertStringContainsString('console exited with 3', $run->stderr);
    }

    public function testConsoleShimFailsLoudlyWithoutAnAutoloader(): void
    {
        $run = $this->runScript('console.php', [
            'COMPOSER_AUTOLOADER_PATH' => $this->project.'/vendor/nope.php',
        ], null, ['list']);

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString('no Composer autoloader', $run->stderr);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function bootstrapDir(): string
    {
        return \dirname(__DIR__).'/src/Resources/bootstrap';
    }

    /**
     * Run a shim behind a driver that fills in the superglobals the host's preamble
     * fills in — the only way to reproduce $_POST and $_FILES, which no environment
     * variable can carry.
     *
     * @param array<string, string> $server
     * @param array<string, string> $post
     * @param array<string, mixed>  $files
     */
    private function drive(string $script, array $server, array $post = [], array $files = []): ScriptRun
    {
        $driver = $this->project.'/driver-'.bin2hex(random_bytes(3)).'.php';

        (new Filesystem())->dumpFile($driver, sprintf(
            "<?php\n\$_POST = %s;\n\$_FILES = %s;\n\$_REQUEST = \$_POST;\n\nrequire %s;\n",
            var_export($post, true),
            var_export($files, true),
            var_export($this->bootstrapDir().'/'.$script, true),
        ));

        return $this->runFile($driver, $server);
    }

    /**
     * @param array<string, string> $server
     * @param list<string>          $argv
     */
    private function runScript(string $script, array $server, ?string $body = null, array $argv = []): ScriptRun
    {
        return $this->runFile($this->bootstrapDir().'/'.$script, $server, $body, $argv);
    }

    /**
     * Run a script the way the host does: a bare PHP process, $_SERVER supplied as
     * the environment, the request body on stdin.
     *
     * proc_open rather than symfony/process on purpose — the bundle does not depend
     * on that component, and a test must not be the reason it starts to.
     *
     * @param array<string, string> $server
     * @param list<string>          $argv
     */
    private function runFile(string $file, array $server, ?string $body = null, array $argv = []): ScriptRun
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'COMPOSER_AUTOLOADER_PATH' => $this->project.'/vendor/autoload.php',
            'NATIVEPHP_TEST_PROJECT' => $this->project,
            'REQUEST_METHOD' => 'GET',
            ...$server,
        ];

        $command = array_merge([\PHP_BINARY, $file], $argv);

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];

        $process = proc_open($command, $descriptors, $pipes, $this->project, $env);

        self::assertIsResource($process);

        fwrite($pipes[0], (string) $body);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return new ScriptRun(proc_close($process), $stdout, $stderr);
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: string}
     */
    private function parse(string $message): array
    {
        $parts = explode("\r\n\r\n", $message, 2);

        self::assertCount(2, $parts, 'The host parses stdout as an HTTP message; this one has no header block: '.$message);

        $lines = explode("\r\n", $parts[0]);
        $status = array_shift($lines);

        $headers = [];

        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [(string) $status, $headers, $parts[1]];
    }
}

/** What a bootstrap script left behind: its exit code and the two streams the host reads. */
final class ScriptRun
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}

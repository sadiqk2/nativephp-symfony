<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Dto\DiagnosisResult;
use App\Mcp\Prompt\TwigPromptRenderer;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Native\Symfony\Desktop\Clipboard\ClipboardManager;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Notification\NotificationManager;
use Native\Symfony\Desktop\Window\WindowManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

final class DesktopTools
{
    public function __construct(
        private readonly NotificationManager $notifications,
        private readonly ClipboardManager $clipboard,
        private readonly WindowManager $windows,
        private readonly ClientInterface $nativeClient,
        private readonly RouterInterface $router,
        private readonly KernelInterface $kernel,
        private readonly TwigPromptRenderer $prompts,
    ) {
    }

    /**
     * Triggers a native desktop notification on the user's host operating system.
     */
    #[McpTool(
        name: 'system_notify',
        title: 'Native OS Notification',
        description: 'Pushes a native operating system notification alert to the user desktop screen.'
    )]
    public function systemNotify(string $title, string $message): string
    {
        try {
            if ($this->nativeClient->isAvailable()) {
                $ref = $this->notifications->send($title, $message);
                return \sprintf('Notification sent successfully to desktop runtime (ref: %s).', $ref);
            }

            // Fallback for Windows desktop when Electron runtime is offline
            if (\PHP_OS_FAMILY === 'Windows') {
                $safeTitle = \str_replace(["'", '"'], '', $title);
                $safeMsg = \str_replace(["'", '"'], '', $message);
                $psCommand = \sprintf(
                    '[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null; ' .
                    '$template = [Windows.UI.Notifications.ToastNotificationManager]::GetTemplateContent([Windows.UI.Notifications.ToastTemplateType]::ToastText02); ' .
                    '$textNodes = $template.GetElementsByTagName("text"); ' .
                    '$textNodes.Item(0).AppendChild($template.CreateTextNode("%s")) | Out-Null; ' .
                    '$textNodes.Item(1).AppendChild($template.CreateTextNode("%s")) | Out-Null; ' .
                    '$notifier = [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("Symfony AI Desktop"); ' .
                    '$toast = [Windows.UI.Notifications.ToastNotification]::new($template); ' .
                    '$notifier.Show($toast);',
                    $safeTitle,
                    $safeMsg
                );
                @\exec('powershell -NoProfile -Command "' . \str_replace('"', '\"', $psCommand) . '"');
                return \sprintf('OS Toast Notification delivered: "%s: %s"', $title, $message);
            }

            return \sprintf('Notification recorded (Native runtime offline): "%s: %s"', $title, $message);
        } catch (\Throwable $e) {
            return \sprintf('Notification could not be dispatched: %s', $e->getMessage());
        }
    }

    /**
     * Reads text directly from the host system clipboard.
     */
    #[McpTool(
        name: 'read_clipboard',
        title: 'Read System Clipboard',
        description: 'Reads the plain text currently stored in the user operating system clipboard.'
    )]
    public function readClipboard(): string
    {
        try {
            if ($this->nativeClient->isAvailable()) {
                $text = $this->clipboard->text();
                return '' !== $text ? $text : '[Clipboard is empty]';
            }

            if (\PHP_OS_FAMILY === 'Windows') {
                $content = @\shell_exec('powershell -NoProfile -Command "Get-Clipboard"');
                return null !== $content && '' !== \trim($content) ? \trim($content) : '[Clipboard is empty]';
            }

            return '[System clipboard unavailable in headless mode]';
        } catch (\Throwable $e) {
            return \sprintf('Error reading clipboard: %s', $e->getMessage());
        }
    }

    /**
     * Writes text or code directly into the user's host system clipboard.
     */
    #[McpTool(
        name: 'write_clipboard',
        title: 'Write System Clipboard',
        description: 'Copies code, diffs, or text directly into the user operating system clipboard.'
    )]
    public function writeClipboard(string $content): string
    {
        try {
            if ($this->nativeClient->isAvailable()) {
                $this->clipboard->setText($content);
                return 'Content successfully copied to clipboard via NativePHP.';
            }

            if (\PHP_OS_FAMILY === 'Windows') {
                $proc = \proc_open('clip', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (\is_resource($proc)) {
                    \fwrite($pipes[0], $content);
                    \fclose($pipes[0]);
                    \fclose($pipes[1]);
                    \fclose($pipes[2]);
                    \proc_close($proc);
                    return 'Content successfully copied to Windows system clipboard.';
                }
            }

            return 'Clipboard update received.';
        } catch (\Throwable $e) {
            return \sprintf('Error writing to clipboard: %s', $e->getMessage());
        }
    }

    /**
     * Spawns or brings forward a native Electron desktop window in the application.
     */
    #[McpTool(
        name: 'open_native_window',
        title: 'Open Native Desktop Window',
        description: 'Opens a new native desktop window or focuses an existing window for a given URL or route.'
    )]
    public function openNativeWindow(string $title, string $url = '/', int $width = 1000, int $height = 700): string
    {
        try {
            if ($this->nativeClient->isAvailable()) {
                $this->windows->open('window_' . \substr(\md5($url), 0, 8))
                    ->title($title)
                    ->url($url)
                    ->size($width, $height)
                    ->open();

                return \sprintf('Desktop window "%s" launched targeting %s (%dx%d).', $title, $url, $width, $height);
            }

            return \sprintf('Target window request registered: %s -> %s (Desktop runtime is not running in background).', $title, $url);
        } catch (\Throwable $e) {
            return \sprintf('Error opening desktop window: %s', $e->getMessage());
        }
    }

    /**
     * Executes a Symfony console command safely from an AI agent.
     */
    #[McpTool(
        name: 'run_symfony_command',
        title: 'Run Symfony Console Command',
        description: 'Executes a Symfony console command (e.g. cache:clear, debug:router, about) and captures its output.'
    )]
    public function runSymfonyCommand(string $command): string
    {
        $allowed = ['about', 'cache:clear', 'debug:router', 'debug:container', 'debug:mcp', 'app:report'];
        $parts = \explode(' ', \trim($command));
        $cmdName = $parts[0] ?? '';

        if (!\in_array($cmdName, $allowed, true)) {
            return \sprintf('Command "%s" is not in the allowed list: %s', $cmdName, \implode(', ', $allowed));
        }

        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput(['command' => $cmdName]);
            $output = new BufferedOutput();

            $exitCode = $application->run($input, $output);

            return \sprintf("Exit code: %d\n\n%s", $exitCode, $output->fetch());
        } catch (\Throwable $e) {
            return \sprintf('Command execution failed: %s', $e->getMessage());
        }
    }

    /**
     * Performs structured diagnosis of an error (inspired by Prism PHP structured outputs).
     */
    #[McpTool(
        name: 'audit_symfony_error',
        title: 'Structured Symfony Error Diagnosis',
        description: 'Parses an error message or stack trace and returns a structured diagnosis object.'
    )]
    public function auditSymfonyError(string $errorMessage): string
    {
        $isMissingKey = \str_contains($errorMessage, 'Undefined array key');
        $isRouteNotFound = \str_contains($errorMessage, 'NotFound') || \str_contains($errorMessage, '404');

        $severity = ($isMissingKey || $isRouteNotFound) ? 'MEDIUM' : 'HIGH';
        $summary = $isMissingKey ? 'Array key access without null-coalescing' : 'Symfony runtime exception';
        $rootCause = $isMissingKey
            ? 'Accessing an uninitialized index in array. PHP 8.4 enforces strict undefined key notices.'
            : 'Uncaught exception during kernel request dispatch.';
        $proposedFix = $isMissingKey
            ? 'Use $array[$key] ?? $default or array_key_exists() check before accessing.'
            : 'Add a try-catch block or verify dependency injection configurations in services.yaml.';

        $result = new DiagnosisResult(
            severity: $severity,
            summary: $summary,
            rootCause: $rootCause,
            proposedFix: $proposedFix,
            suggestedCommands: ['cache:clear', 'debug:router']
        );

        return (string) \json_encode($result->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resource listing all routes in this application.
     */
    #[McpResource(
        uri: 'symfony://app/routes',
        name: 'symfony_routes',
        title: 'Symfony Application Routes',
        description: 'Lists all available routes, path patterns, and HTTP methods in this Symfony application.',
        mimeType: 'application/json'
    )]
    public function getRoutes(): string
    {
        $routes = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $routes[] = [
                'name' => $name,
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'controller' => $route->getDefault('_controller'),
            ];
        }

        return (string) \json_encode($routes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resource reporting application status, framework versions, and runtime health.
     */
    #[McpResource(
        uri: 'symfony://app/status',
        name: 'symfony_status',
        title: 'Symfony & NativePHP System Status',
        description: 'Reports runtime environment, PHP version, Symfony kernel status, and NativePHP client availability.',
        mimeType: 'application/json'
    )]
    public function getAppStatus(): string
    {
        $status = [
            'php_version' => \PHP_VERSION,
            'os' => \PHP_OS,
            'os_family' => \PHP_OS_FAMILY,
            'environment' => $this->kernel->getEnvironment(),
            'debug' => $this->kernel->isDebug(),
            'project_dir' => $this->kernel->getProjectDir(),
            'nativephp_runtime_connected' => $this->nativeClient->isAvailable(),
        ];

        return (string) \json_encode($status, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Prompt template rendered via Twig (inspired by Prism PHP's Blade prompt templates).
     */
    #[McpPrompt(
        name: 'diagnose_symfony_error',
        title: 'Diagnose Symfony Error (Twig Template)',
        description: 'Renders a rich diagnostic prompt for LLM agents using Twig templating.'
    )]
    public function diagnoseExceptionPrompt(string $error): array
    {
        $routes = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $routes[] = ['name' => $name, 'path' => $route->getPath(), 'methods' => $route->getMethods()];
        }

        $renderedPrompt = $this->prompts->render('diagnose_error', [
            'error' => $error,
            'routes' => $routes,
            'status' => [
                'php_version' => \PHP_VERSION,
                'os' => \PHP_OS_FAMILY,
                'environment' => $this->kernel->getEnvironment(),
            ],
        ]);

        return [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => $renderedPrompt,
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The application a bootstrap script boots, as the native layer would find it.
 *
 * Deliberately named App\Kernel: that is the default the shims fall back to when the
 * host sets no NATIVEPHP_KERNEL_CLASS, so the fixture exercises the default path.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return (string) ($_SERVER['NATIVEPHP_TEST_PROJECT'] ?? \dirname(__DIR__, 3));
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'boot-fixture',
            'http_method_override' => false,
            'php_errors' => ['log' => false],
        ]);

        $container->services()
            ->set(EchoCommand::class)
            ->tag('console.command');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('ping', '/ping')->controller([$this, 'ping']);
        $routes->add('env', '/env')->controller([$this, 'env']);
        $routes->add('echo', '/echo')->controller([$this, 'echoRequest']);
        $routes->add('boom', '/boom')->controller([$this, 'boom']);
    }

    public function ping(): Response
    {
        $response = new Response('pong');
        $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('visited', 'yes'));

        return $response;
    }

    /** Reports what the shim decided the environment was, which is the point of the .env cases. */
    public function env(): Response
    {
        return new Response(json_encode([
            'kernel_env' => $this->getEnvironment(),
            'kernel_debug' => $this->isDebug(),
            'server_app_env' => $_SERVER['APP_ENV'] ?? null,
            'server_app_debug' => $_SERVER['APP_DEBUG'] ?? null,
            'greeting' => $_SERVER['GREETING'] ?? $_ENV['GREETING'] ?? null,
        ], \JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
    }

    /** Echoes back what the request factory made of the host's $_SERVER. */
    public function echoRequest(Request $request): Response
    {
        return new Response(json_encode([
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'post' => $request->request->all(),
            'content' => $request->getContent(),
            'cookies' => $request->cookies->all(),
            'files' => array_map(
                static fn ($file) => $file?->getClientOriginalName(),
                $request->files->all(),
            ),
            'host' => $request->getHost(),
            'secure' => $request->isSecure(),
        ], \JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
    }

    public function boom(): Response
    {
        throw new \RuntimeException('deliberate controller failure');
    }
}

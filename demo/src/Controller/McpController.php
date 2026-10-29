<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\DesktopTools;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class McpController extends AbstractController
{
    public function __construct(
        private readonly DesktopTools $tools,
        private readonly ClientInterface $nativeClient,
    ) {
    }

    #[Route('/ai-mcp', name: 'mcp_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $statusJson = $this->tools->getAppStatus();
        $status = \json_decode($statusJson, true) ?? [];
        $routes = \json_decode($this->tools->getRoutes(), true) ?? [];

        return $this->render('mcp.html.twig', [
            'status' => $status,
            'routes' => $routes,
            'runtimeAvailable' => $this->nativeClient->isAvailable(),
        ]);
    }

    #[Route('/ai-mcp/test-tool', name: 'mcp_test_tool', methods: ['POST'])]
    public function testTool(Request $request): Response
    {
        $tool = $request->request->getString('tool');
        $result = '';

        if ('notify' === $tool) {
            $result = $this->tools->systemNotify(
                'Symfony AI Studio',
                'Native desktop alert executed via Model Context Protocol!'
            );
        } elseif ('clipboard' === $tool) {
            $result = $this->tools->writeClipboard(
                "// Generated via Symfony AI MCP\n" .
                "function greetFromClaude(): string {\n" .
                "    return 'Desktop MCP Tooling in PHP 8.4 is live!';\n" .
                "}"
            );
        } elseif ('command' === $tool) {
            $result = $this->tools->runSymfonyCommand('about');
        } elseif ('routes' === $tool) {
            $result = $this->tools->getRoutes();
        }

        $this->addFlash('mcp_result', $result);

        return $this->redirectToRoute('mcp_dashboard');
    }
}

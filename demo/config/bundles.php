<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    // Both halves of the project, in one app. Nothing about them conflicts —
    // proving that is part of what this demo is for.
    Native\Symfony\NativeDesktopBundle::class => ['all' => true],
    Native\Symfony\Mobile\NativeMobileBundle::class => ['all' => true],
];

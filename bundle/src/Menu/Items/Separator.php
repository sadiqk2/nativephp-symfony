<?php

declare(strict_types=1);

namespace Native\Symfony\Menu\Items;

final class Separator implements MenuItem
{
    public function toArray(): array
    {
        return ['type' => 'separator'];
    }
}

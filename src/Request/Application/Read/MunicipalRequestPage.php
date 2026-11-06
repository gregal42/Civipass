<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

final readonly class MunicipalRequestPage
{
    public int $pages;
    public int $from;
    public int $to;

    /**
     * @param list<MunicipalRequestRow> $items
     */
    public function __construct(public array $items, public int $total, public int $page)
    {
        $this->pages = max(1, (int) ceil($total / MunicipalRequestQuery::PAGE_SIZE));
        $this->from = [] === $items ? 0 : ($page - 1) * MunicipalRequestQuery::PAGE_SIZE + 1;
        $this->to = $this->from + max(0, \count($items) - 1);
    }
}

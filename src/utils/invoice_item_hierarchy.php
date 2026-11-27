<?php
declare(strict_types=1);

/** Keep each child beside its invoice's top-level item, without changing amounts. */
function invoice_items_in_display_order(array $items): array
{
    $roots = [];
    foreach ($items as $item) {
        if (empty($item['parent_item_id'])) {
            $roots[(int)$item['id']] = true;
        }
    }

    $topLevel = [];
    $children = [];
    foreach ($items as $item) {
        $parentId = (int)($item['parent_item_id'] ?? 0);
        if (isset($roots[$parentId])) {
            $children[$parentId][] = $item;
        } else {
            $item['parent_item_id'] = null;
            $topLevel[] = $item;
        }
    }

    $ordered = [];
    foreach ($topLevel as $item) {
        $ordered[] = $item;
        foreach ($children[(int)$item['id']] ?? [] as $child) {
            $ordered[] = $child;
        }
    }
    return $ordered;
}

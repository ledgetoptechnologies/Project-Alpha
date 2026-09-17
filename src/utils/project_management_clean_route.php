<?php

declare(strict_types=1);

/**
 * Select PA's existing Projects list for the one reviewed clean URL.
 *
 * A null result means this is not the alias. A 405 result leaves routing to
 * the caller so it can send the standard Allow header without entering any
 * page controller.
 *
 * @param array<string,mixed> $query
 */
function project_management_clean_route(string $requestPath, string $method, array &$query): ?int
{
    if ($requestPath !== '/projects' && $requestPath !== '/projects/') {
        return null;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return 405;
    }

    // Do not allow a query-string `page` to turn the reviewed alias into a
    // different controller. Other existing list filters remain intact.
    $query['page'] = 'project/projects-list';

    return 200;
}

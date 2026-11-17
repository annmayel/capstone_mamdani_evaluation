<?php

declare(strict_types=1);

function fuzzy_project_link_is_valid(string $url, ?array $allowedHosts = null): bool
{
    if ($url === '' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    if ($allowedHosts === null) {
        return true;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return in_array($host, $allowedHosts, true);
}

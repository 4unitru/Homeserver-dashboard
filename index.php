<?php
$db_file = 'links.json';
$default_row_id = '_default';

function load_dashboard_data(string $db_file): array {
    $needs_save = false;
    $raw = file_exists($db_file) ? json_decode((string)file_get_contents($db_file), true) : [];
    if (!is_array($raw)) {
        $raw = [];
    }

    // Backward compatibility: older format stored only links.
    $looks_like_old_links = !isset($raw['rows']) || !isset($raw['links']);
    if ($looks_like_old_links) {
        $needs_save = true;
        return [
            'rows' => [
                '_default' => ['name' => '', 'order' => 0, 'collapsed' => false]
            ],
            'links' => is_array($raw) ? $raw : [],
            'needs_save' => $needs_save
        ];
    }

    $rows = is_array($raw['rows']) ? $raw['rows'] : [];
    $links = is_array($raw['links']) ? $raw['links'] : [];
    if (!isset($rows['_default'])) {
        $rows['_default'] = ['name' => '', 'order' => 0, 'collapsed' => false];
        $needs_save = true;
    }
    foreach ($rows as $rid => $row) {
        if (!is_array($row)) {
            $rows[$rid] = ['name' => '', 'order' => 1, 'collapsed' => false];
            $needs_save = true;
            continue;
        }
        if (!array_key_exists('collapsed', $row)) {
            $rows[$rid]['collapsed'] = false;
            $needs_save = true;
        }
        if (!array_key_exists('order', $row)) {
            $rows[$rid]['order'] = ($rid === '_default') ? 0 : 1;
            $needs_save = true;
        }
    }

    foreach ($links as $id => $link) {
        if (!is_array($link)) {
            unset($links[$id]);
            continue;
        }
        if (empty($link['row_id']) || !isset($rows[$link['row_id']])) {
            $links[$id]['row_id'] = '_default';
            $needs_save = true;
        }
        if (!array_key_exists('order', $link) || !is_numeric($link['order'])) {
            $links[$id]['order'] = 0;
            $needs_save = true;
        }
    }

    return ['rows' => $rows, 'links' => $links, 'needs_save' => $needs_save];
}

function save_dashboard_data(string $db_file, array $rows, array $links): void {
    $payload = ['rows' => $rows, 'links' => $links];
    file_put_contents($db_file, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function next_link_order(array $links, string $row_id): int {
    $max_order = -1;
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (($link['row_id'] ?? '_default') !== $row_id) {
            continue;
        }
        $max_order = max($max_order, (int)($link['order'] ?? 0));
    }
    return $max_order + 1;
}

$data = load_dashboard_data($db_file);
$rows = $data['rows'];
$links = $data['links'];
if (!empty($data['needs_save'])) {
    save_dashboard_data($db_file, $rows, $links);
}

function normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }
    return rtrim($url, '/');
}

function join_url(string $base, string $relative): string {
    if ($relative === '') {
        return $base;
    }

    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }

    if (strpos($relative, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'http';
        return $scheme . ':' . $relative;
    }

    $base_parts = parse_url($base);
    if (!$base_parts || empty($base_parts['host'])) {
        return $relative;
    }

    $scheme = $base_parts['scheme'] ?? 'http';
    $host = $base_parts['host'];
    $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    if (strpos($relative, '/') === 0) {
        return $origin . $relative;
    }

    $base_path = $base_parts['path'] ?? '/';
    $base_dir = rtrim(str_replace('\\', '/', dirname($base_path)), '/');
    $base_dir = $base_dir === '' ? '' : '/' . ltrim($base_dir, '/');
    $full = $origin . $base_dir . '/' . $relative;

    $parsed = parse_url($full);
    $path = $parsed['path'] ?? '/';
    $segments = explode('/', $path);
    $resolved = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $segment;
    }

    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
    return $origin . '/' . implode('/', $resolved) . $query . $fragment;
}

function pick_best_icon_from_manifest(array $manifest, string $base_url): ?string {
    if (empty($manifest['icons']) || !is_array($manifest['icons'])) {
        return null;
    }

    $best_icon = null;
    $best_size = -1;
    foreach ($manifest['icons'] as $icon) {
        if (!is_array($icon) || empty($icon['src'])) {
            continue;
        }
        $size_score = 0;
        if (!empty($icon['sizes']) && is_string($icon['sizes'])) {
            $parts = preg_split('/\s+/', trim($icon['sizes']));
            foreach ($parts as $part) {
                if (preg_match('/^(\d+)x(\d+)$/', $part, $m)) {
                    $size_score = max($size_score, (int)$m[1] * (int)$m[2]);
                }
            }
        }
        if ($size_score >= $best_size) {
            $best_size = $size_score;
            $best_icon = $icon['src'];
        }
    }

    return $best_icon ? join_url($base_url, $best_icon) : null;
}

function is_reachable_icon_url(string $icon_url): bool {
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 4,
            "ignore_errors" => true
        ]
    ];
    $context = stream_context_create($opts);
    $headers = @get_headers($icon_url, true, $context);
    if (!$headers || !isset($headers[0])) {
        return false;
    }

    if (!preg_match('/\s(\d{3})\s/', (string)$headers[0], $status_match)) {
        return false;
    }

    $status_code = (int)$status_match[1];
    if ($status_code >= 200 && $status_code < 400) {
        return true;
    }
    // Some local services may protect static assets with auth and return 401/403.
    return $status_code === 401 || $status_code === 403;
}

function icon_cache_relative_dir(): string {
    return 'icons-cache';
}

function icon_cache_absolute_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . icon_cache_relative_dir();
}

function ensure_icon_cache_dir(): bool {
    $dir = icon_cache_absolute_dir();
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0777, true);
}

function icon_extension_from_url(string $url): string {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
    if ($ext === 'jpg') {
        $ext = 'jpeg';
    }
    if (!in_array($ext, $allowed, true)) {
        $ext = 'ico';
    }
    return $ext;
}

function allowed_icon_extensions(): array {
    return ['ico', 'png', 'svg', 'jpeg', 'webp', 'gif'];
}

function allowed_icon_mime_map(): array {
    return [
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/jpeg' => 'jpeg',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
}

function detect_mime_type(string $file_path): ?string {
    if (!function_exists('finfo_open')) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return null;
    }
    $mime = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    return $mime ?: null;
}

function has_valid_icon_signature(string $binary, string $ext): bool {
    if ($binary === '') {
        return false;
    }

    $start4 = substr($binary, 0, 4);
    $start8 = substr($binary, 0, 8);

    if ($ext === 'png') {
        return $start8 === "\x89PNG\x0D\x0A\x1A\x0A";
    }
    if ($ext === 'gif') {
        return substr($binary, 0, 3) === 'GIF';
    }
    if ($ext === 'webp') {
        return substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP';
    }
    if ($ext === 'jpeg') {
        return substr($binary, 0, 2) === "\xFF\xD8";
    }
    if ($ext === 'ico') {
        return $start4 === "\x00\x00\x01\x00" || $start4 === "\x00\x00\x02\x00";
    }
    if ($ext === 'svg') {
        return stripos(substr($binary, 0, 512), '<svg') !== false;
    }

    return false;
}

function cache_icon_locally(string $icon_url, string $site_url): ?string {
    if ($icon_url === '' || stripos($icon_url, 'data:image/') === 0) {
        return null;
    }

    if (!ensure_icon_cache_dir()) {
        return null;
    }

    $ext = icon_extension_from_url($icon_url);
    $hash = sha1($site_url . '|' . $icon_url);
    $file_name = $hash . '.' . $ext;
    $relative_path = icon_cache_relative_dir() . '/' . $file_name;
    $absolute_path = icon_cache_absolute_dir() . DIRECTORY_SEPARATOR . $file_name;

    if (file_exists($absolute_path) && filesize($absolute_path) > 0) {
        return $relative_path;
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 6,
            "ignore_errors" => true
        ]
    ];
    $context = stream_context_create($opts);
    $binary = @file_get_contents($icon_url, false, $context);
    if ($binary === false || strlen($binary) === 0) {
        return null;
    }

    if (@file_put_contents($absolute_path, $binary) === false) {
        return null;
    }

    return $relative_path;
}

function cache_icon_binary(string $binary, string $site_url, string $source_hint = ''): ?string {
    if ($binary === '' || !ensure_icon_cache_dir()) {
        return null;
    }

    $ext = icon_extension_from_url($source_hint);
    $hash = sha1($site_url . '|' . $source_hint . '|' . $binary);
    $file_name = $hash . '.' . $ext;
    $relative_path = icon_cache_relative_dir() . '/' . $file_name;
    $absolute_path = icon_cache_absolute_dir() . DIRECTORY_SEPARATOR . $file_name;

    if (@file_put_contents($absolute_path, $binary) === false) {
        return null;
    }

    return $relative_path;
}

function extension_from_uploaded_file(array $file): string {
    $original = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
    if ($original === 'jpg') {
        $original = 'jpeg';
    }
    if (in_array($original, $allowed, true)) {
        return $original;
    }

    if (isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        $mime = detect_mime_type($file['tmp_name']);
        $map = allowed_icon_mime_map();
        if ($mime && isset($map[$mime])) {
            return $map[$mime];
        }
    }

    return 'png';
}

function cache_uploaded_icon(array $file, string $site_url): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return null;
    }
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    if (!ensure_icon_cache_dir()) {
        return null;
    }

    $binary = @file_get_contents($file['tmp_name']);
    if ($binary === false || $binary === '') {
        return null;
    }

    $ext = extension_from_uploaded_file($file);
    if (!in_array($ext, allowed_icon_extensions(), true)) {
        return null;
    }
    $mime = detect_mime_type($file['tmp_name']);
    $mime_map = allowed_icon_mime_map();
    if ($mime !== null && !isset($mime_map[$mime])) {
        return null;
    }
    if (!has_valid_icon_signature($binary, $ext)) {
        return null;
    }

    $hash = sha1($site_url . '|upload|' . $binary);
    $file_name = $hash . '.' . $ext;
    $absolute_path = icon_cache_absolute_dir() . DIRECTORY_SEPARATOR . $file_name;
    $relative_path = icon_cache_relative_dir() . '/' . $file_name;

    if (@move_uploaded_file($file['tmp_name'], $absolute_path)) {
        return $relative_path;
    }
    if (@file_put_contents($absolute_path, $binary) !== false) {
        return $relative_path;
    }
    return null;
}

function detect_site_icon(string $url): string {
    $url = normalize_url($url);
    if ($url === '') {
        return '';
    }

    $opts = ["http" => ["header" => "User-Agent: Mozilla/5.0\r\n", "timeout" => 5]];
    $context = stream_context_create($opts);
    $html = @file_get_contents($url, false, $context);

    $candidates = [];
    if ($html) {
        if (preg_match_all('/<link[^>]+>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                preg_match('/rel=["\']([^"\']+)["\']/i', $tag, $rel_match);
                preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href_match);
                if (empty($href_match[1])) {
                    continue;
                }
                $rel = strtolower($rel_match[1] ?? '');
                $href = trim($href_match[1]);
                $is_icon = preg_match('/(^|\s)(icon|shortcut icon|apple-touch-icon|apple-touch-icon-precomposed|mask-icon)(\s|$)/i', $rel);
                if ($is_icon) {
                    $candidates[] = join_url($url, $href);
                }

                $is_manifest = preg_match('/(^|\s)manifest(\s|$)/i', $rel);
                if ($is_manifest) {
                    $manifest_url = join_url($url, $href);
                    $manifest_content = @file_get_contents($manifest_url, false, $context);
                    if ($manifest_content) {
                        $manifest = json_decode($manifest_content, true);
                        if (is_array($manifest)) {
                            $manifest_icon = pick_best_icon_from_manifest($manifest, $url);
                            if ($manifest_icon) {
                                $candidates[] = $manifest_icon;
                            }
                        }
                    }
                }
            }
        }

        if (preg_match_all('/<meta[^>]+>/i', $html, $meta_matches)) {
            foreach ($meta_matches[0] as $meta_tag) {
                preg_match('/(?:property|name)=["\']([^"\']+)["\']/i', $meta_tag, $name_match);
                preg_match('/content=["\']([^"\']+)["\']/i', $meta_tag, $content_match);
                if (empty($name_match[1]) || empty($content_match[1])) {
                    continue;
                }
                $meta_name = strtolower(trim($name_match[1]));
                if (in_array($meta_name, ['og:image', 'twitter:image', 'msapplication-tileimage'], true)) {
                    $candidates[] = join_url($url, trim($content_match[1]));
                }
            }
        }
    }

    $parsed_url = parse_url($url);
    $scheme = $parsed_url['scheme'] ?? 'http';
    $host = $parsed_url['host'] ?? '';
    $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
    $origin = $scheme . '://' . $host . $port;
    $path = $parsed_url['path'] ?? '';
    $path = trim($path, '/');
    $first_path_segment = $path !== '' ? explode('/', $path)[0] : '';

    $standard_paths = [
        '/favicon.ico',
        '/favicon.png',
        '/apple-touch-icon.png',
        '/apple-touch-icon-precomposed.png',
        '/android-chrome-192x192.png',
        '/android-chrome-512x512.png',
        '/mstile-150x150.png',
        '/web/favicon.ico',
        '/web/icon.png',
        '/web/icon-192.png',
        '/web/assets/img/favicon.ico',
        '/static/favicon.ico',
        '/img/favicon.ico'
    ];
    if ($first_path_segment !== '') {
        $standard_paths[] = '/' . $first_path_segment . '/favicon.ico';
        $standard_paths[] = '/' . $first_path_segment . '/apple-touch-icon.png';
    }
    foreach ($standard_paths as $path) {
        $candidates[] = $origin . $path;
    }

    $candidates = array_values(array_unique(array_filter($candidates)));
    foreach ($candidates as $candidate) {
        if (is_reachable_icon_url($candidate)) {
            return $candidate;
        }
    }

    return $origin . '/favicon.ico';
}

// 1. Добавление (с поиском иконки)
if (isset($_POST['url']) && !isset($_POST['delete_id']) && !isset($_POST['update_id']) && !isset($_POST['create_row'])) {
    $url = normalize_url($_POST['url']);
    if ($url === '') {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    $target_row_id = $_POST['row_id'] ?? $default_row_id;
    if (!isset($rows[$target_row_id])) {
        $target_row_id = $default_row_id;
    }
    $detected_icon = detect_site_icon($url);
    $icon = cache_icon_locally($detected_icon, $url) ?? $detected_icon;

    $links[uniqid()] = [
        'url' => $url,
        'description' => 'Новый сервис',
        'icon' => $icon,
        'row_id' => $target_row_id,
        'order' => next_link_order($links, $target_row_id)
    ];
    save_dashboard_data($db_file, $rows, $links);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 2. Удаление
if (isset($_POST['delete_id'])) {
    unset($links[$_POST['delete_id']]);
    save_dashboard_data($db_file, $rows, $links);
    exit;
}

// 3. Обновление описания
if (isset($_POST['update_id'])) {
    $id = $_POST['update_id'];
    if (isset($links[$id])) {
        $links[$id]['description'] = $_POST['new_val'];
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 4. Обновление иконки (загрузка файла или ссылка)
if (isset($_POST['update_icon_id'])) {
    $id = $_POST['update_icon_id'];
    if (isset($links[$id])) {
        $site_url = $links[$id]['url'] ?? '';
        $new_icon = null;

        if (!empty($_POST['reset_icon']) && $_POST['reset_icon'] === '1') {
            $detected_icon = detect_site_icon($site_url);
            $new_icon = cache_icon_locally($detected_icon, $site_url) ?? $detected_icon;
        } elseif (!empty($_FILES['icon_file']) && ($_FILES['icon_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $new_icon = cache_uploaded_icon($_FILES['icon_file'], $site_url);
        } elseif (!empty($_POST['icon_source_url'])) {
            $icon_url = trim($_POST['icon_source_url']);
            $new_icon = cache_icon_locally($icon_url, $site_url) ?? $icon_url;
        } elseif (!empty($_POST['icon_source_data'])) {
            $source_data = trim($_POST['icon_source_data']);
            if (stripos($source_data, 'data:image/') === 0) {
                $comma_pos = strpos($source_data, ',');
                if ($comma_pos !== false) {
                    $meta = substr($source_data, 0, $comma_pos);
                    $payload = substr($source_data, $comma_pos + 1);
                    $is_base64 = stripos($meta, ';base64') !== false;
                    $binary = $is_base64 ? base64_decode($payload, true) : urldecode($payload);
                    if ($binary !== false && $binary !== '') {
                        $ext_hint = 'icon.png';
                        if (stripos($meta, 'image/svg+xml') !== false) {
                            $ext_hint = 'icon.svg';
                        } elseif (stripos($meta, 'image/webp') !== false) {
                            $ext_hint = 'icon.webp';
                        } elseif (stripos($meta, 'image/gif') !== false) {
                            $ext_hint = 'icon.gif';
                        } elseif (stripos($meta, 'image/x-icon') !== false || stripos($meta, 'image/vnd.microsoft.icon') !== false) {
                            $ext_hint = 'icon.ico';
                        } elseif (stripos($meta, 'image/jpeg') !== false) {
                            $ext_hint = 'icon.jpeg';
                        }
                        $new_icon = cache_icon_binary($binary, $site_url, $ext_hint) ?? $source_data;
                    }
                }
            }
        }

        if ($new_icon) {
            $links[$id]['icon'] = $new_icon;
            save_dashboard_data($db_file, $rows, $links);
        }
    }
    exit;
}

// 5. Создание нового row
if (isset($_POST['create_row'])) {
    $row_name = trim((string)($_POST['row_name'] ?? ''));
    if ($row_name !== '') {
        $next_order = 1;
        foreach ($rows as $rid => $row) {
            $order = (int)($row['order'] ?? 0);
            $next_order = max($next_order, $order + 1);
        }
        $rows[uniqid('row_')] = [
            'name' => $row_name,
            'order' => $next_order,
            'collapsed' => false
        ];
        save_dashboard_data($db_file, $rows, $links);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 6. Переименование row
if (isset($_POST['rename_row_id'])) {
    $row_id = $_POST['rename_row_id'];
    $row_name = trim((string)($_POST['row_name'] ?? ''));
    if ($row_id !== $default_row_id && isset($rows[$row_id])) {
        $rows[$row_id]['name'] = $row_name;
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 7. Удаление row (карточки -> default row)
if (isset($_POST['delete_row_id'])) {
    $row_id = $_POST['delete_row_id'];
    if ($row_id !== $default_row_id && isset($rows[$row_id])) {
        unset($rows[$row_id]);
        foreach ($links as $link_id => $link) {
            if (($link['row_id'] ?? $default_row_id) === $row_id) {
                $links[$link_id]['row_id'] = $default_row_id;
            }
        }
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 8. Перемещение карточки между rows
if (isset($_POST['move_card_id'], $_POST['target_row_id'])) {
    $card_id = $_POST['move_card_id'];
    $target_row_id = $_POST['target_row_id'];
    if (isset($links[$card_id]) && isset($rows[$target_row_id])) {
        $links[$card_id]['row_id'] = $target_row_id;
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 8b. Сохранение полного порядка карточек в категориях
if (isset($_POST['save_cards_layout'])) {
    $raw_layout = $_POST['cards_layout'] ?? '[]';
    $layout = json_decode((string)$raw_layout, true);
    if (is_array($layout)) {
        foreach ($layout as $row_layout) {
            if (!is_array($row_layout)) {
                continue;
            }
            $row_id = $row_layout['row_id'] ?? '';
            $card_ids = $row_layout['card_ids'] ?? [];
            if (!is_string($row_id) || !isset($rows[$row_id]) || !is_array($card_ids)) {
                continue;
            }
            $order = 0;
            foreach ($card_ids as $card_id) {
                if (!is_string($card_id) || !isset($links[$card_id])) {
                    continue;
                }
                $links[$card_id]['row_id'] = $row_id;
                $links[$card_id]['order'] = $order++;
            }
        }
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 9. Сохранение порядка rows
if (isset($_POST['save_row_order'])) {
    $raw_order = $_POST['row_order'] ?? '[]';
    $row_order = json_decode((string)$raw_order, true);
    if (is_array($row_order)) {
        $order = 1;
        foreach ($row_order as $row_id) {
            if (!is_string($row_id) || $row_id === $default_row_id || !isset($rows[$row_id])) {
                continue;
            }
            $rows[$row_id]['order'] = $order++;
        }
        $rows[$default_row_id]['order'] = 0;
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

// 10. Сохранение состояния свернутости row
if (isset($_POST['set_row_collapsed_id'])) {
    $row_id = $_POST['set_row_collapsed_id'];
    $collapsed = ($_POST['collapsed'] ?? '0') === '1';
    if (isset($rows[$row_id])) {
        $rows[$row_id]['collapsed'] = $collapsed;
        save_dashboard_data($db_file, $rows, $links);
    }
    exit;
}

$row_keys = array_keys($rows);
usort($row_keys, function ($a, $b) use ($rows, $default_row_id) {
    if ($a === $default_row_id) return -1;
    if ($b === $default_row_id) return 1;
    $ao = (int)($rows[$a]['order'] ?? 0);
    $bo = (int)($rows[$b]['order'] ?? 0);
    if ($ao === $bo) {
        return strcmp((string)$a, (string)$b);
    }
    return $ao <=> $bo;
});
$rows_sorted = [];
foreach ($row_keys as $rk) {
    $rows_sorted[$rk] = $rows[$rk];
}

$links_by_row = [];
foreach ($rows_sorted as $rid => $row_meta) {
    $links_by_row[$rid] = [];
}
foreach ($links as $id => $link) {
    $rid = $link['row_id'] ?? $default_row_id;
    if (!isset($links_by_row[$rid])) {
        $links_by_row[$default_row_id][] = ['id' => $id, 'link' => $link];
    } else {
        $links_by_row[$rid][] = ['id' => $id, 'link' => $link];
    }
}
foreach ($links_by_row as $rid => &$entries) {
    usort($entries, function ($a, $b) {
        $ao = (int)($a['link']['order'] ?? 0);
        $bo = (int)($b['link']['order'] ?? 0);
        if ($ao === $bo) {
            return strcmp((string)$a['id'], (string)$b['id']);
        }
        return $ao <=> $bo;
    });
}
unset($entries);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: radial-gradient(circle at top, #1e293b 0%, #0f172a 55%); color: #f8fafc; font-family: Inter, system-ui, sans-serif; min-height: 100vh; }
        @media (min-width: 768px) { body { padding: 40px; } }
        .dashboard { max-width: 1200px; margin: 0 auto; }
        .dashboard-header { display: flex; flex-direction: column; justify-content: space-between; align-items: stretch; gap: 12px; margin-bottom: 24px; }
        @media (min-width: 1024px) { .dashboard-header { flex-direction: row; align-items: center; } }
        .subtitle { color: #94a3b8; margin: 4px 0 0; font-size: 14px; }
        .add-form { display: flex; gap: 8px; width: 100%; }
        @media (min-width: 1024px) { .add-form { width: auto; } }
        .row-tools { display: flex; gap: 8px; width: 100%; margin-top: 10px; align-items: center; }
        .url-input, .search-input { width: 100%; background: rgba(15, 23, 42, 0.9); border: 1px solid #334155; color: #f8fafc; border-radius: 12px; outline: none; }
        .url-input { padding: 12px 16px; min-width: 220px; font-size: 14px; }
        .row-name-input { min-width: 0; }
        .search-input { padding: 10px 16px; font-size: 14px; }
        .row-select { background: rgba(15, 23, 42, 0.9); border: 1px solid #334155; color: #f8fafc; border-radius: 12px; padding: 10px 12px; font-size: 14px; min-width: 150px; }
        .url-input:focus, .search-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.35); }
        .add-btn { border: 0; background: #2563eb; color: #fff; border-radius: 12px; padding: 12px 24px; font-weight: 700; cursor: pointer; }
        .row-tools .add-btn { white-space: nowrap; }
        .add-btn:hover { background: #3b82f6; }
        .search-wrap { margin-bottom: 20px; }
        .rows-wrap { display: flex; flex-direction: column; gap: 20px; }
        .row-block { border: 1px solid rgba(148,163,184,0.25); border-radius: 14px; padding: 12px; background: rgba(15,23,42,0.35); }
        .row-block.dragging-row { opacity: 0.45; }
        .row-block.row-drop-target { outline: 2px dashed #60a5fa; outline-offset: 3px; }
        .row-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; }
        .row-header-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .row-handle { user-select: none; cursor: grab; color: #94a3b8; font-size: 16px; line-height: 1; }
        .row-block[data-row-id="_default"] .row-handle { opacity: 0.35; cursor: default; }
        .row-title { color: #e2e8f0; font-weight: 600; font-size: 14px; }
        .row-actions { display: flex; gap: 6px; }
        .small-btn { border: 1px solid rgba(148,163,184,0.4); background: rgba(30,41,59,0.6); color: #cbd5e1; border-radius: 8px; padding: 4px 8px; cursor: pointer; font-size: 12px; }
        .small-btn:hover { border-color: #60a5fa; color: #fff; }
        .small-btn.danger:hover { border-color: #f87171; color: #fecaca; }
        .dropzone-active { outline: 2px dashed #60a5fa; outline-offset: 3px; }
        .row-block.is-collapsed .row-dropzone { display: none; }
        .cards-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 640px) { .cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .cards-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .cards-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .glass { background: rgba(15, 23, 42, 0.65); border: 1px solid rgba(148,163,184,0.25); backdrop-filter: blur(8px); }
        .service-card { padding: 20px; border-radius: 16px; display: flex; align-items: center; gap: 16px; position: relative; overflow: hidden; transition: transform .16s ease, border-color .16s ease; cursor: pointer; }
        .service-card:hover { transform: translateY(-2px); border-color: rgba(96,165,250,0.55); }
        .service-icon { width: 48px; height: 48px; border-radius: 12px; object-fit: contain; background: rgba(255, 255, 255, 0.05); padding: 4px; flex-shrink: 0; }
        .service-main { min-width: 0; flex-grow: 1; padding-right: 32px; }
        .service-title { color: #fff; font-size: 18px; line-height: 1.2; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .service-url { color: #94a3b8; font-size: 12px; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; text-decoration: none; }
        .service-url:hover { color: #60a5fa; }
        .card-actions { position: absolute; right: 12px; top: 12px; display: flex; flex-direction: column; gap: 12px; }
        .icon-btn { border: 0; background: transparent; color: #64748b; cursor: pointer; padding: 0; }
        .icon-btn svg { width: 20px; height: 20px; }
        .icon-btn:hover { color: #60a5fa; }
        .icon-btn.danger { color: #64748b; }
        .icon-btn.danger:hover { color: #f87171; }
        .hidden { display: none; }
        .hidden-by-filter { display: none; }
        .empty-state { text-align: center; margin-top: 40px; color: #94a3b8; border-radius: 16px; padding: 32px; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.75); display: none; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .icon-modal { width: min(860px, 100%); max-height: 90vh; overflow: auto; border-radius: 16px; padding: 18px; }
        .modal-header { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 12px; }
        .modal-title { font-size: 18px; font-weight: 600; margin: 0; }
        .close-btn { border: 0; background: transparent; color: #94a3b8; font-size: 22px; cursor: pointer; line-height: 1; }
        .modal-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 900px) { .modal-grid { grid-template-columns: 1fr 1.4fr; } }
        .panel { border: 1px solid rgba(148,163,184,0.25); border-radius: 12px; padding: 12px; background: rgba(15, 23, 42, 0.5); }
        .panel h3 { margin: 0 0 8px; font-size: 14px; color: #cbd5e1; }
        .file-input { width: 100%; color: #cbd5e1; margin-bottom: 8px; }
        .small-text { font-size: 12px; color: #94a3b8; margin: 4px 0 0; }
        .action-btn { border: 0; background: #2563eb; color: #fff; border-radius: 10px; padding: 8px 12px; font-weight: 600; cursor: pointer; }
        .action-btn:hover { background: #3b82f6; }
        .action-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .search-row { display: flex; gap: 8px; }
        .icon-results { margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 8px; max-height: 48vh; overflow: auto; }
        .icon-tile { border: 1px solid rgba(148,163,184,0.25); border-radius: 10px; background: rgba(30,41,59,0.6); cursor: pointer; padding: 8px; text-align: center; }
        .icon-tile:hover { border-color: rgba(96,165,250,0.75); transform: translateY(-1px); }
        .icon-tile img { width: 36px; height: 36px; object-fit: contain; }
        .icon-tile-label { margin-top: 6px; font-size: 10px; color: #cbd5e1; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
        .modal-status { margin-top: 8px; font-size: 12px; color: #94a3b8; min-height: 16px; }
        .title-input { width: 100%; margin-bottom: 8px; background: rgba(15, 23, 42, 0.9); border: 1px solid #334155; color: #f8fafc; border-radius: 10px; outline: none; padding: 10px 12px; font-size: 14px; }
        .title-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.35); }
        .row-dropzone-empty { min-height: 92px; align-content: start; }
        .row-dropzone-empty::before {
            content: "Перетащите карточку в эту категорию";
            display: block;
            font-size: 12px;
            color: #94a3b8;
            border: 1px dashed rgba(148,163,184,0.45);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            margin-bottom: 8px;
        }
        .icon-stack { display: flex; flex-direction: column; gap: 12px; }
        .panel-subtitle { font-size: 12px; color: #94a3b8; margin: -2px 0 6px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <header class="dashboard-header">
            <div>
                <h1>🏠 Dashboard</h1>
                <p class="subtitle">Локальные сервисы в одном месте</p>
            </div>
            <form method="POST" class="add-form" autocomplete="off">
                <input type="url" name="url" placeholder="http://192.168.1.10" required 
                       class="url-input">
                <select name="row_id" class="row-select">
                    <?php foreach ($rows_sorted as $row_id => $row_meta): ?>
                        <option value="<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(($row_meta['name'] ?? '') !== '' ? $row_meta['name'] : 'Без категории', ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="add-btn">Добавить</button>
            </form>
            <form method="POST" class="row-tools" autocomplete="off">
                <input type="hidden" name="create_row" value="1">
                <input type="text" name="row_name" class="url-input row-name-input" placeholder="Название новой категории" required>
                <button type="submit" class="add-btn">Создать категорию</button>
            </form>
        </header>

        <div class="search-wrap">
            <input id="searchInput" type="search" placeholder="Поиск по названию или адресу..."
                   class="search-input">
        </div>

        <div id="rowsWrap" class="rows-wrap">
            <?php foreach ($rows_sorted as $row_id => $row_meta): ?>
                <section class="row-block <?= !empty($row_meta['collapsed']) ? 'is-collapsed' : '' ?>" data-row-id="<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>" <?= $row_id !== $default_row_id ? 'draggable="true"' : '' ?>>
                    <div class="row-header">
                        <div class="row-header-left">
                            <span class="row-handle" title="Перетащить категорию">⋮⋮</span>
                            <div class="row-title"><?= htmlspecialchars(($row_meta['name'] ?? '') !== '' ? $row_meta['name'] : ($row_id === $default_row_id ? '' : 'Без названия'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="row-actions">
                            <button type="button" class="small-btn" onclick="toggleRowCollapse('<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>')"><?= !empty($row_meta['collapsed']) ? 'Развернуть' : 'Свернуть' ?></button>
                            <?php if ($row_id !== $default_row_id): ?>
                                <button type="button" class="small-btn" onclick="renameRow('<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>')">Переименовать</button>
                                <button type="button" class="small-btn danger" onclick="deleteRow('<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>')">Удалить категорию</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cards-grid row-dropzone <?= empty($links_by_row[$row_id]) ? 'row-dropzone-empty' : '' ?>" data-row-id="<?= htmlspecialchars($row_id, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($links_by_row[$row_id] as $entry): $id = $entry['id']; $link = $entry['link']; ?>
                        <div id="card-<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                             data-card-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                             data-search="<?= htmlspecialchars(strtolower(($link['description'] ?? '') . ' ' . ($link['url'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                             data-url="<?= htmlspecialchars($link['url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>"
                             data-row-id="<?= htmlspecialchars($link['row_id'] ?? $default_row_id, ENT_QUOTES, 'UTF-8') ?>"
                             draggable="true"
                             class="service-card glass">
                    
                            <img src="<?= htmlspecialchars($link['icon'] ?? '', ENT_QUOTES, 'UTF-8') ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2264%22 height=%2264%22 viewBox=%220 0 64 64%22%3E%3Crect width=%2264%22 height=%2264%22 rx=%2212%22 fill=%22%231e293b%22/%3E%3Cpath d=%22M18 32h28M32 18v28%22 stroke=%22%2394a3b8%22 stroke-width=%224%22 stroke-linecap=%22round%22/%3E%3C/svg%3E'" 
                                 class="service-icon">
                    
                            <div class="service-main">
                                <div>
                                    <div id="desc-<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="service-title">
                                        <?= htmlspecialchars($link['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars($link['url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" class="service-url">
                                    <?= htmlspecialchars(str_replace(['http://', 'https://'], '', $link['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </div>

                            <div class="card-actions">
                                <button onclick="event.stopPropagation(); openIconModal('<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>')" aria-label="Редактировать карточку" class="icon-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                </button>
                        
                                <button onclick="event.stopPropagation(); deleteLink('<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>')" id="btn-del-<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" aria-label="Удалить карточку" class="icon-btn danger">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div id="emptyState" class="hidden empty-state glass">
            Ничего не найдено. Попробуйте другой запрос или добавьте новый сервис.
        </div>
    </div>

    <div id="iconModalBackdrop" class="modal-backdrop" onclick="closeIconModal(event)">
        <div class="icon-modal glass" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Редактирование карточки</h2>
                <button class="close-btn" onclick="closeIconModal()">&times;</button>
            </div>
            <div class="modal-grid">
                <div class="panel">
                    <h3>Название сервиса</h3>
                    <form id="titleEditForm">
                        <input type="hidden" id="titleEditId" value="">
                        <input type="text" id="titleEditInput" class="title-input" placeholder="Введите название" required>
                        <button type="submit" class="action-btn">Сохранить название</button>
                    </form>
                    <p class="small-text">Изменение применяется сразу к карточке.</p>
                </div>
                <div class="panel">
                    <h3>Иконка сервиса</h3>
                    <div class="panel-subtitle">Загрузите файл или выберите через поиск по "Iconify"</div>
                    <div class="icon-stack">
                        <form id="iconUploadForm" enctype="multipart/form-data">
                            <input type="hidden" id="uploadIconId" name="update_icon_id" value="">
                            <input type="file" name="icon_file" accept=".png,.ico,.svg,.jpg,.jpeg,.webp,.gif,image/*" class="file-input" required>
                            <button type="submit" class="action-btn">Загрузить и применить</button>
                            <p class="small-text">Поддерживается до 2MB. Иконка сохраняется локально.</p>
                        </form>
                        <div class="search-row">
                            <input id="iconSearchInput" type="search" class="search-input" placeholder="Например: jellyfin, movie, server">
                            <button id="iconSearchBtn" class="action-btn" type="button">Найти</button>
                        </div>
                        <button id="resetIconBtn" type="button" class="action-btn" style="background:#475569;">Сбросить на автоопределение</button>
                    </div>
                    <div id="iconSearchStatus" class="modal-status"></div>
                    <div id="iconResults" class="icon-results"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let activeIconEditId = null;

    function saveChanges(id, newVal) {
        const formData = new FormData();
        formData.append('update_id', id);
        formData.append('new_val', newVal);
        fetch('', { method: 'POST', body: formData }).catch(() => {
            alert('Не удалось сохранить изменения');
        });
    }

    function deleteLink(id) {
        if (!confirm('Удалить этот сервис?')) return;
        const formData = new FormData();
        formData.append('delete_id', id);
        fetch('', { method: 'POST', body: formData }).then(() => {
            const card = document.getElementById('card-' + id);
            const zone = card ? card.closest('.row-dropzone') : null;
            if (card) card.remove();
            if (zone && !zone.querySelector('.service-card')) {
                zone.classList.add('row-dropzone-empty');
            }
            updateEmptyState();
        });
    }

    function renameRow(rowId) {
        const titleNode = document.querySelector(`.row-block[data-row-id="${rowId}"] .row-title`);
        const currentName = titleNode ? titleNode.textContent.trim() : '';
        const nextName = prompt('Новое имя категории:', currentName || '');
        if (nextName === null) return;
        const formData = new FormData();
        formData.append('rename_row_id', rowId);
        formData.append('row_name', nextName.trim());
        fetch('', { method: 'POST', body: formData }).then(() => window.location.reload());
    }

    function deleteRow(rowId) {
        if (!confirm('Удалить категорию? Карточки будут перенесены в верхнюю категорию без имени.')) return;
        const formData = new FormData();
        formData.append('delete_row_id', rowId);
        fetch('', { method: 'POST', body: formData }).then(() => window.location.reload());
    }

    function toggleRowCollapse(rowId) {
        const rowBlock = document.querySelector(`.row-block[data-row-id="${rowId}"]`);
        if (!rowBlock) return;
        const willCollapse = !rowBlock.classList.contains('is-collapsed');
        rowBlock.classList.toggle('is-collapsed', willCollapse);

        const button = rowBlock.querySelector('.row-actions .small-btn');
        if (button) {
            button.textContent = willCollapse ? 'Развернуть' : 'Свернуть';
        }

        const formData = new FormData();
        formData.append('set_row_collapsed_id', rowId);
        formData.append('collapsed', willCollapse ? '1' : '0');
        fetch('', { method: 'POST', body: formData }).catch(() => {});
    }

    function openIconModal(id) {
        activeIconEditId = id;
        document.getElementById('uploadIconId').value = id;
        document.getElementById('titleEditId').value = id;
        const titleEl = document.getElementById('desc-' + id);
        document.getElementById('titleEditInput').value = titleEl ? titleEl.textContent.trim() : '';
        document.getElementById('iconModalBackdrop').classList.add('open');
        document.getElementById('titleEditInput').focus();
    }

    function closeIconModal(event) {
        if (event && event.target && event.target.id !== 'iconModalBackdrop') {
            return;
        }
        activeIconEditId = null;
        document.getElementById('iconModalBackdrop').classList.remove('open');
    }

    async function applyIconSelection({ iconUrl = '', iconData = '' }) {
        if (!activeIconEditId) return;
        const formData = new FormData();
        formData.append('update_icon_id', activeIconEditId);
        if (iconUrl) formData.append('icon_source_url', iconUrl);
        if (iconData) formData.append('icon_source_data', iconData);

        const response = await fetch('', { method: 'POST', body: formData });
        if (!response.ok) throw new Error('Не удалось обновить иконку');
        window.location.reload();
    }

    const searchInput = document.getElementById('searchInput');
    const emptyState = document.getElementById('emptyState');
    const iconSearchInput = document.getElementById('iconSearchInput');
    const iconSearchBtn = document.getElementById('iconSearchBtn');
    const iconSearchStatus = document.getElementById('iconSearchStatus');
    const iconResults = document.getElementById('iconResults');
    const iconUploadForm = document.getElementById('iconUploadForm');
    const resetIconBtn = document.getElementById('resetIconBtn');
    const titleEditForm = document.getElementById('titleEditForm');
    const titleEditInput = document.getElementById('titleEditInput');
    let draggedCardId = null;

    function updateEmptyState() {
        const cards = Array.from(document.querySelectorAll('.service-card'));
        const visibleCount = cards.filter((card) => card && !card.classList.contains('hidden-by-filter')).length;
        emptyState.classList.toggle('hidden', visibleCount > 0);
    }

    searchInput.addEventListener('input', (event) => {
        const query = event.target.value.trim().toLowerCase();
        const cards = Array.from(document.querySelectorAll('.service-card'));
        cards.forEach((card) => {
            if (!card || !card.dataset.search) return;
            const isMatch = card.dataset.search.includes(query);
            card.classList.toggle('hidden-by-filter', !isMatch);
        });
        updateEmptyState();
    });

    titleEditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = document.getElementById('titleEditId').value;
        const newTitle = titleEditInput.value.trim() || 'Без названия';
        try {
            saveChanges(id, newTitle);
            const titleEl = document.getElementById('desc-' + id);
            if (titleEl) titleEl.textContent = newTitle;
            closeIconModal();
        } catch (e) {
            iconSearchStatus.textContent = 'Не удалось сохранить название.';
        }
    });

    iconUploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeIconEditId) return;

        const fileInput = iconUploadForm.querySelector('input[name="icon_file"]');
        const file = fileInput && fileInput.files ? fileInput.files[0] : null;
        if (!file) {
            iconSearchStatus.textContent = 'Выберите файл иконки.';
            return;
        }
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const allowedExt = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!allowedExt.includes(ext)) {
            iconSearchStatus.textContent = 'Недопустимое расширение файла.';
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            iconSearchStatus.textContent = 'Файл слишком большой (макс. 2MB).';
            return;
        }

        iconSearchStatus.textContent = 'Загружаю иконку...';
        const formData = new FormData(iconUploadForm);
        formData.set('update_icon_id', activeIconEditId);
        try {
            const response = await fetch('', { method: 'POST', body: formData });
            if (!response.ok) throw new Error();
            window.location.reload();
        } catch (e) {
            iconSearchStatus.textContent = 'Ошибка загрузки иконки.';
        }
    });

    resetIconBtn.addEventListener('click', async () => {
        if (!activeIconEditId) return;
        iconSearchStatus.textContent = 'Восстанавливаю автоиконку...';
        try {
            const formData = new FormData();
            formData.append('update_icon_id', activeIconEditId);
            formData.append('reset_icon', '1');
            const response = await fetch('', { method: 'POST', body: formData });
            if (!response.ok) throw new Error();
            window.location.reload();
        } catch (e) {
            iconSearchStatus.textContent = 'Не удалось сбросить иконку.';
        }
    });

    async function searchIconify() {
        const query = iconSearchInput.value.trim();
        if (!query) return;
        iconSearchStatus.textContent = 'Ищу иконки...';
        iconResults.innerHTML = '';

        try {
            const searchUrl = `https://api.iconify.design/search?query=${encodeURIComponent(query)}&limit=36`;
            const res = await fetch(searchUrl);
            if (!res.ok) throw new Error();
            const data = await res.json();
            const icons = Array.isArray(data.icons) ? data.icons : [];
            if (!icons.length) {
                iconSearchStatus.textContent = 'Ничего не найдено.';
                return;
            }

            iconSearchStatus.textContent = `Найдено: ${icons.length}. Нажмите на иконку для установки.`;
            icons.forEach((fullName) => {
                const [prefix, name] = String(fullName).split(':');
                if (!prefix || !name) return;
                const svgUrl = `https://api.iconify.design/${encodeURIComponent(prefix)}/${encodeURIComponent(name)}.svg`;

                const tile = document.createElement('button');
                tile.type = 'button';
                tile.className = 'icon-tile';
                tile.innerHTML = `<img src="${svgUrl}" alt="${fullName}"><div class="icon-tile-label">${fullName}</div>`;
                tile.addEventListener('click', async () => {
                    try {
                        iconSearchStatus.textContent = `Устанавливаю ${fullName}...`;
                        await applyIconSelection({ iconUrl: svgUrl });
                    } catch (e) {
                        iconSearchStatus.textContent = 'Не удалось установить иконку.';
                    }
                });
                iconResults.appendChild(tile);
            });
        } catch (e) {
            iconSearchStatus.textContent = 'Ошибка поиска. Проверьте интернет-соединение.';
        }
    }

    iconSearchBtn.addEventListener('click', searchIconify);
    iconSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchIconify();
        }
    });

    async function saveCurrentRowOrder() {
        const rowIds = Array.from(document.querySelectorAll('.row-block'))
            .map((row) => row.dataset.rowId)
            .filter((id) => id && id !== '_default');
        const formData = new FormData();
        formData.append('save_row_order', '1');
        formData.append('row_order', JSON.stringify(rowIds));
        await fetch('', { method: 'POST', body: formData });
    }

    async function saveCardsLayout() {
        const cardsLayout = Array.from(document.querySelectorAll('.row-dropzone')).map((zone) => {
            const rowId = zone.dataset.rowId || '';
            const cardIds = Array.from(zone.querySelectorAll('.service-card')).map((card) => card.dataset.cardId).filter(Boolean);
            return { row_id: rowId, card_ids: cardIds };
        });
        const formData = new FormData();
        formData.append('save_cards_layout', '1');
        formData.append('cards_layout', JSON.stringify(cardsLayout));
        const response = await fetch('', { method: 'POST', body: formData });
        if (!response.ok) {
            throw new Error('layout save failed');
        }
    }

    document.querySelectorAll('.service-card').forEach((card) => {
        card.addEventListener('click', () => {
            const url = card.getAttribute('data-url');
            if (!url) return;
            window.open(url, '_blank', 'noopener,noreferrer');
        });
        card.addEventListener('dragstart', (event) => {
            draggedCardId = card.dataset.cardId || null;
            card.style.opacity = '0.5';
            if (event.dataTransfer) {
                event.dataTransfer.setData('text/plain', draggedCardId || '');
                event.dataTransfer.effectAllowed = 'move';
            }
        });
        card.addEventListener('dragend', () => {
            card.style.opacity = '1';
            draggedCardId = null;
            document.querySelectorAll('.row-dropzone').forEach((z) => z.classList.remove('dropzone-active'));
        });
        card.addEventListener('dragover', (event) => {
            if (!draggedCardId) return;
            event.preventDefault();
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        });
        card.addEventListener('drop', async (event) => {
            event.preventDefault();
            const targetCard = card;
            const draggedId = draggedCardId || (event.dataTransfer ? event.dataTransfer.getData('text/plain') : '');
            if (!draggedId || draggedId === targetCard.dataset.cardId) return;
            const draggedCard = document.querySelector(`.service-card[data-card-id="${draggedId}"]`);
            if (!draggedCard) return;

            const targetZone = targetCard.closest('.row-dropzone');
            const oldZone = draggedCard.closest('.row-dropzone');
            if (!targetZone) return;

            const targetRect = targetCard.getBoundingClientRect();
            const placeAfter = event.clientY > targetRect.top + targetRect.height / 2;
            if (placeAfter) {
                targetCard.insertAdjacentElement('afterend', draggedCard);
            } else {
                targetCard.insertAdjacentElement('beforebegin', draggedCard);
            }

            targetZone.classList.remove('row-dropzone-empty');
            if (oldZone && !oldZone.querySelector('.service-card')) {
                oldZone.classList.add('row-dropzone-empty');
            }

            try {
                await saveCardsLayout();
            } catch (e) {
                iconSearchStatus.textContent = 'Не удалось сохранить порядок карточек.';
            }
        });
    });

    let draggedRowId = null;
    const rowsWrap = document.getElementById('rowsWrap');
    const rowBlocks = Array.from(document.querySelectorAll('.row-block'));

    rowBlocks.forEach((row) => {
        const rowId = row.dataset.rowId;
        if (!rowId || rowId === '_default') return;

        row.addEventListener('dragstart', (event) => {
            draggedRowId = rowId;
            row.classList.add('dragging-row');
            if (event.dataTransfer) {
                event.dataTransfer.setData('text/plain', rowId);
                event.dataTransfer.effectAllowed = 'move';
            }
        });
        row.addEventListener('dragend', async () => {
            row.classList.remove('dragging-row');
            document.querySelectorAll('.row-block').forEach((r) => r.classList.remove('row-drop-target'));
            if (draggedRowId) {
                try {
                    await saveCurrentRowOrder();
                } catch (e) {
                    iconSearchStatus.textContent = 'Не удалось сохранить порядок категорий.';
                }
            }
            draggedRowId = null;
        });
        row.addEventListener('dragover', (event) => {
            if (!draggedRowId || draggedRowId === rowId) return;
            event.preventDefault();
            row.classList.add('row-drop-target');
        });
        row.addEventListener('dragleave', () => {
            row.classList.remove('row-drop-target');
        });
        row.addEventListener('drop', (event) => {
            event.preventDefault();
            row.classList.remove('row-drop-target');
            if (!draggedRowId || draggedRowId === rowId) return;

            const dragged = document.querySelector(`.row-block[data-row-id="${draggedRowId}"]`);
            if (!dragged || !rowsWrap) return;
            rowsWrap.insertBefore(dragged, row);
        });
    });

    if (rowsWrap) {
        rowsWrap.addEventListener('dragover', (event) => {
            if (!draggedRowId) return;
            event.preventDefault();
        });
        rowsWrap.addEventListener('drop', (event) => {
            if (!draggedRowId || !rowsWrap) return;
            if (event.target && event.target.closest('.row-block')) return;
            const dragged = document.querySelector(`.row-block[data-row-id="${draggedRowId}"]`);
            if (!dragged) return;
            rowsWrap.appendChild(dragged);
        });
    }

    document.querySelectorAll('.row-dropzone').forEach((zone) => {
        zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('dropzone-active');
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        });
        zone.addEventListener('dragleave', () => {
            zone.classList.remove('dropzone-active');
        });
        zone.addEventListener('drop', async (event) => {
            event.preventDefault();
            zone.classList.remove('dropzone-active');
            if (event.target && event.target.closest('.service-card')) {
                return;
            }
            const cardId = draggedCardId || (event.dataTransfer ? event.dataTransfer.getData('text/plain') : '');
            const targetRowId = zone.dataset.rowId;
            if (!cardId || !targetRowId) return;

            const cardEl = document.querySelector(`.service-card[data-card-id="${cardId}"]`);
            if (!cardEl) return;
            const currentRowId = cardEl.dataset.rowId || '';
            if (currentRowId === targetRowId) return;

            try {
                const oldZone = cardEl.closest('.row-dropzone');
                zone.appendChild(cardEl);
                zone.classList.remove('row-dropzone-empty');
                if (oldZone && oldZone !== zone && !oldZone.querySelector('.service-card')) {
                    oldZone.classList.add('row-dropzone-empty');
                }
                await saveCardsLayout();
            } catch (e) {
                iconSearchStatus.textContent = 'Не удалось сохранить порядок карточек.';
            }
        });
    });

    updateEmptyState();
    </script>
</body>
</html>

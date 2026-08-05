<?php

function sanitizeDivisionCssDeclarations(string $css): string
{
    $safe = [];
    foreach (explode(';', $css) as $declaration) {
        if (strpos($declaration, ':') === false) {
            continue;
        }
        [$property, $value] = array_map('trim', explode(':', $declaration, 2));
        $property = strtolower($property);
        if (!preg_match('/^(--[a-z0-9_-]+|[a-z-]{2,40})$/', $property)) {
            continue;
        }
        if (preg_match('/url\s*\(|expression\s*\(|javascript:|@import|behavior\s*:|-moz-binding/i', $value)) {
            continue;
        }
        if ($property === 'position' && preg_match('/\b(fixed|sticky)\b/i', $value)) {
            continue;
        }
        if ($property === 'z-index' && abs((int)$value) > 100) {
            $value = '100';
        }
        $safe[] = $property . ':' . mb_substr($value, 0, 500);
    }
    return implode(';', $safe);
}

function sanitizeDivisionCss(string $css): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
    $css = preg_replace('/@(import|charset|namespace|page|font-face|keyframes|supports)[^{;]*(?:;|\{.*?\})/is', '', $css) ?? '';
    $result = [];
    // Keep simple responsive media queries while sanitizing and scoping every
    // rule inside them exactly like a normal division CSS rule.
    $css = preg_replace_callback(
        '/@media\s*([^{}]+)\{((?:[^{}]+|\{[^{}]*\})*)\}/is',
        static function (array $match) use (&$result): string {
            $condition = trim($match[1]);
            if (!preg_match('/^(?:screen\s+and\s+)?\((?:min|max)-width:\s*\d{2,4}px\)$/i', $condition)) {
                return '';
            }
            $rules = sanitizeDivisionCss($match[2]);
            if ($rules !== '') {
                $result[] = '@media ' . $condition . '{' . $rules . '}';
            }
            return '';
        },
        $css
    ) ?? $css;
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) {
            $declarations = sanitizeDivisionCssDeclarations($rule[2]);
            if ($declarations === '') {
                continue;
            }
            $selectors = [];
            foreach (explode(',', trim($rule[1])) as $selector) {
                $selector = trim($selector);
                if ($selector === '' || preg_match('/[<>@]/', $selector)) {
                    continue;
                }
                // Every custom rule is confined to this division's content.
                // Keep the sanitizer idempotent: saved content is sanitized on
                // write and once more on render. Do not prefix an already
                // scoped selector a second time.
                $selectors[] = preg_match('/^\.division-content(?:\b|\s|[>+~:.#\[])/', $selector)
                    ? $selector
                    : '.division-content ' . $selector;
            }
            if ($selectors) {
                $result[] = implode(',', $selectors) . '{' . $declarations . '}';
            }
        }
    }
    return implode("\n", $result);
}

function sanitizeDivisionHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="utf-8" ?><div id="vfn-division-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $allowedTags = [
        'div', 'main', 'header', 'footer', 'nav', 'aside', 'section', 'article',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br',
        'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'hr', 'a',
        'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot',
        'tr', 'th', 'td', 'span', 'small', 'mark', 'sub', 'sup', 's', 'code',
        'pre', 'figure', 'figcaption', 'picture', 'img', 'details', 'summary',
        'dl', 'dt', 'dd', 'time', 'progress', 'meter', 'button', 'style'
    ];
    $allowedAttributes = [
        'href', 'src', 'alt', 'title', 'target', 'rel', 'class', 'id', 'style',
        'width', 'height', 'loading', 'colspan', 'rowspan', 'scope', 'open',
        'datetime', 'value', 'min', 'max', 'type', 'disabled', 'aria-label',
        'aria-hidden', 'role'
    ];
    $walk = static function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttributes): void {
        if (!$node->hasChildNodes() || $node->childNodes === null) {
            return;
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'link', 'meta', 'base', 'video', 'audio', 'canvas', 'svg', 'math'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, $allowedTags, true) && $child->getAttribute('id') !== 'vfn-division-root') {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $attributeName = strtolower($attribute->name);
                    if (strpos($attributeName, 'on') === 0 || !in_array($attributeName, $allowedAttributes, true)) {
                        $child->removeAttribute($attribute->name);
                    }
                }
                if ($child->hasAttribute('style')) {
                    $child->setAttribute('style', sanitizeDivisionCssDeclarations($child->getAttribute('style')));
                }
                if ($tag === 'a') {
                    $href = trim($child->getAttribute('href'));
                    if (!preg_match('~^(https?://|/|division\.php|profile\.php|#)~i', $href)) {
                        $child->removeAttribute('href');
                    }
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                if ($tag === 'img') {
                    $src = trim($child->getAttribute('src'));
                    if (!preg_match('~^(https://|/|images/)~i', $src)) {
                        $child->removeAttribute('src');
                    }
                    $child->setAttribute('loading', 'lazy');
                }
                if ($tag === 'button') {
                    $child->setAttribute('type', 'button');
                }
                if ($tag === 'style') {
                    $child->nodeValue = sanitizeDivisionCss($child->textContent);
                    continue;
                }
            }
            $walk($child);
        }
    };
    $root = $document->getElementById('vfn-division-root');
    if (!$root) {
        return '';
    }
    $walk($root);
    $result = '';
    foreach ($root->childNodes as $child) {
        $result .= $document->saveHTML($child);
    }
    return trim($result);
}

function divisionStatistics(PDO $pdo, string $code): array
{
    $memberStmt = $pdo->prepare(
        "SELECT COUNT(*) AS member_total,
                COALESCE(SUM(total_flight_seconds), 0) AS total_seconds,
                COALESCE(SUM(total_flight_miles), 0) AS total_nm,
                COALESCE(SUM(total_landings), 0) AS total_landings
         FROM users WHERE division_code = :code AND is_active = 1"
    );
    $memberStmt->execute(['code' => $code]);
    $totals = $memberStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $flightStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pilot_flights f
         INNER JOIN users u ON u.id = f.user_id
         WHERE u.division_code = :code AND f.status IN ('completed', 'wrong_destination')"
    );
    $flightStmt->execute(['code' => $code]);

    $activeStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.user_id) FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE u.division_code = :code AND s.is_active = 1 AND s.is_spectator = 0"
    );
    $activeStmt->execute(['code' => $code]);

    $staffStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM division_staff WHERE division_code = :code AND is_active = 1"
    );
    $staffStmt->execute(['code' => $code]);

    $topStmt = $pdo->prepare(
        "SELECT id, username, real_name, total_flight_seconds, total_flight_miles
         FROM users WHERE division_code = :code AND is_active = 1
         ORDER BY total_flight_seconds DESC, total_flight_miles DESC LIMIT 1"
    );
    $topStmt->execute(['code' => $code]);
    $top = $topStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'member_total' => (int)($totals['member_total'] ?? 0),
        'active_pilots' => (int)$activeStmt->fetchColumn(),
        'staff_total' => (int)$staffStmt->fetchColumn(),
        'flights_total' => (int)$flightStmt->fetchColumn(),
        'flight_hours_total' => number_format(((int)($totals['total_seconds'] ?? 0)) / 3600, 1, '.', ''),
        'flight_nm_total' => number_format((float)($totals['total_nm'] ?? 0), 1, '.', ''),
        'landings_total' => (int)($totals['total_landings'] ?? 0),
        'top_pilot_name' => (string)($top['real_name'] ?: ($top['username'] ?? '-')),
        'top_pilot_hours' => number_format(((int)($top['total_flight_seconds'] ?? 0)) / 3600, 1, '.', ''),
        'top_pilot_id' => (int)($top['id'] ?? 0)
    ];
}

function divisionMembersTable(PDO $pdo, string $code): string
{
    $stmt = $pdo->prepare(
        "SELECT id, username, real_name, country_code, total_flight_seconds
         FROM users WHERE division_code = :code AND is_active = 1
         ORDER BY real_name, username LIMIT 250"
    );
    $stmt->execute(['code' => $code]);
    $html = '<div class="division-table-wrap"><table class="division-table"><thead><tr><th>Name</th><th>Country</th><th>Hours</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $name = (string)($member['real_name'] ?: $member['username']);
        $html .= '<tr><td><a href="profile.php?id=' . (int)$member['id'] . '">' . htmlspecialchars($name) . '</a></td><td>' . htmlspecialchars(strtoupper((string)$member['country_code'])) . '</td><td>' . number_format(((int)$member['total_flight_seconds']) / 3600, 1) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function divisionStaffTable(PDO $pdo, string $code): string
{
    $stmt = $pdo->prepare(
        "SELECT ds.role_code, ds.role_title, u.id, u.username, u.real_name
         FROM division_staff ds INNER JOIN users u ON u.id = ds.user_id
         WHERE ds.division_code = :code AND ds.is_active = 1
         ORDER BY ds.sort_order, ds.role_code, u.real_name"
    );
    $stmt->execute(['code' => $code]);
    $html = '<div class="division-staff-grid">';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $staff) {
        $name = (string)($staff['real_name'] ?: $staff['username']);
        $title = (string)($staff['role_title'] ?: $staff['role_code']);
        $html .= '<article class="division-stat-card"><strong>' . htmlspecialchars($title) . '</strong><a href="profile.php?id=' . (int)$staff['id'] . '">' . htmlspecialchars($name) . '</a></article>';
    }
    return $html . '</div>';
}

function renderDivisionContent(PDO $pdo, array $division, string $content): string
{
    $stats = divisionStatistics($pdo, (string)$division['code']);
    $values = [
        '%division_code%' => htmlspecialchars((string)$division['code']),
        '%division_name%' => htmlspecialchars((string)$division['name']),
        '%division_short_name%' => htmlspecialchars((string)($division['short_name'] ?? '')),
        '%division_description%' => nl2br(htmlspecialchars((string)($division['description'] ?? ''))),
        '%division_member_total%' => (string)$stats['member_total'],
        '%division_active_pilots%' => (string)$stats['active_pilots'],
        '%division_staff_total%' => (string)$stats['staff_total'],
        '%division_flights_total%' => (string)$stats['flights_total'],
        '%division_flight_hours_total%' => (string)$stats['flight_hours_total'],
        '%division_flight_nm_total%' => (string)$stats['flight_nm_total'],
        '%division_landings_total%' => (string)$stats['landings_total'],
        '%division_top_pilot_name%' => htmlspecialchars((string)$stats['top_pilot_name']),
        '%division_top_pilot_hours%' => (string)$stats['top_pilot_hours'],
        '%division_members%' => divisionMembersTable($pdo, (string)$division['code']),
        '%division_staff%' => divisionStaffTable($pdo, (string)$division['code'])
    ];
    return strtr($content, $values);
}

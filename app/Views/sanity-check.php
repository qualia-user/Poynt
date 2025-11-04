<?php
/**
 * @return string
 * @var string $query
 */

$escape = static function (mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$stringLength = static function (string $value): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
};

$stringSlice = static function (string $value, int $offset, int $length) {
    if (function_exists('mb_substr')) {
        return mb_substr($value, $offset, $length, 'UTF-8');
    }

    return substr($value, $offset, $length);
};

$formatLargeValue = static function (string $column, string $value, bool $isJson = false) use ($escape, $stringLength, $stringSlice): string {
    $length = $stringLength($value);
    $previewLimit = $isJson ? 1200 : 600;
    $preview = $stringSlice($value, 0, $previewLimit);
    $ellipsis = $length > $previewLimit ? '…' : '';
    $columnLabel = trim(str_replace('_', ' ', $column));
    if ($columnLabel === '') {
        $columnLabel = 'value';
    }
    $columnLabel = ucwords($columnLabel);
    $label = sprintf(
        'Expand %s (%d characters%s)',
        $columnLabel,
        $length,
        $isJson ? ', JSON preview' : ''
    );

    return sprintf(
        '<details class="payload-preview"><summary>%s</summary><pre>%s%s</pre></details>',
        $escape($label),
        $escape($preview),
        $escape($ellipsis)
    );
};

$formatValue = static function (string $column, mixed $value) use (&$formatValue, $escape, $formatLargeValue, $stringLength, $stringSlice): string {
    if ($value === null) {
        return '<span class="muted">null</span>';
    }

    if (is_bool($value)) {
        return $value ? '<span class="badge badge-true">true</span>' : '<span class="badge badge-false">false</span>';
    }

    if ($value === '') {
        return '<span class="muted">(empty)</span>';
    }

    if (is_array($value)) {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && $encoded !== null) {
            if ($stringLength($encoded) > 1400) {
                return $formatLargeValue($column, $encoded, true);
            }

            return '<pre>' . $escape($encoded) . '</pre>';
        }

        return '<pre>[]</pre>';
    }

    if (is_object($value)) {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && $encoded !== null) {
            if ($stringLength($encoded) > 1400) {
                return $formatLargeValue($column, $encoded, true);
            }

            return '<pre>' . $escape($encoded) . '</pre>';
        }

        return '<pre>{}</pre>';
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $formatValue($column, $decoded);
            }
        }

        $length = $stringLength($value);
        $columnLower = strtolower($column);
        $looksLikePayload = str_contains($columnLower, 'payload') || str_contains($columnLower, 'raw');

        if ($length > 1400 || ($looksLikePayload && $length > 400)) {
            return $formatLargeValue($column, $value, false);
        }
    }

    return $escape($value);
};

$humanize = static function (string $table) use ($escape): string {
    $table = str_replace('_', ' ', $table);
    return ucfirst($escape($table));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Business Sanity Check</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #0f172a;
            --card-bg: rgba(15, 23, 42, 0.55);
            --panel-bg: rgba(15, 23, 42, 0.75);
            --text: #e2e8f0;
            --accent: #38bdf8;
            --border: rgba(148, 163, 184, 0.35);
            --muted: rgba(226, 232, 240, 0.7);
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top, rgba(56, 189, 248, 0.35), transparent 60%),
            radial-gradient(circle at bottom, rgba(129, 140, 248, 0.35), transparent 65%),
            var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        a {
            color: var(--accent);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 1.5rem 4rem;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.03em;
        }

        .subtitle {
            max-width: 720px;
            line-height: 1.6;
            color: var(--muted);
            margin-bottom: 2.5rem;
        }

        .search-form {
            background: var(--panel-bg);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.5rem;
            display: grid;
            gap: 0.75rem;
            margin-bottom: 2rem;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(12px);
        }

        .search-form label {
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .search-row input[type="text"] {
            flex: 1 1 240px;
            padding: 0.9rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.45);
            color: var(--text);
            font-size: 1rem;
        }

        .search-row button,
        .search-row .link-button {
            padding: 0.85rem 1.5rem;
            border-radius: 0.75rem;
            border: none;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            font-size: 0.85rem;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .search-row button {
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0f172a;
            box-shadow: 0 12px 30px rgba(56, 189, 248, 0.35);
        }

        .search-row button:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 40px rgba(56, 189, 248, 0.45);
        }

        .search-row .link-button {
            background: rgba(15, 23, 42, 0.6);
            color: var(--text);
            border: 1px solid var(--border);
            text-decoration: none;
        }

        .search-row .link-button:hover {
            background: rgba(30, 41, 59, 0.9);
        }

        .alert {
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-error {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.4);
            color: #fecaca;
        }

        .business-card {
            background: var(--panel-bg);
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            padding: 2rem;
            margin-bottom: 2.5rem;
            box-shadow: 0 40px 70px rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(14px);
        }

        .business-header {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1.5rem;
        }

        .business-header h2 {
            margin: 0;
            font-size: 1.8rem;
            letter-spacing: -0.02em;
        }

        .business-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            color: var(--muted);
        }

        .business-meta span {
            background: rgba(30, 41, 59, 0.7);
            border-radius: 999px;
            padding: 0.4rem 0.85rem;
            font-size: 0.85rem;
        }

        .business-flags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-bottom: 0.75rem;
        }

        .match-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            border: 1px solid rgba(56, 189, 248, 0.4);
            background: rgba(56, 189, 248, 0.18);
            color: #bae6fd;
        }

        .match-badge-alt {
            border-color: rgba(129, 140, 248, 0.35);
            background: rgba(129, 140, 248, 0.18);
            color: #c7d2fe;
        }

        .matched-stores {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            margin-bottom: 1.25rem;
        }

        .matched-stores strong {
            font-size: 0.9rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .matched-store-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .store-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.35);
            font-size: 0.85rem;
        }

        details {
            background: rgba(15, 23, 42, 0.55);
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 1rem;
            margin-bottom: 1.2rem;
            overflow: hidden;
        }

        details[open] {
            box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.18);
        }

        summary {
            list-style: none;
            cursor: pointer;
            padding: 1.1rem 1.4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        summary::-webkit-details-marker {
            display: none;
        }

        .table-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .record-count {
            background: rgba(56, 189, 248, 0.2);
            color: #bae6fd;
            border-radius: 999px;
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
        }

        .table-tools {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1.5rem 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .table-tools label {
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        .table-tools input[type="search"] {
            flex: 1 1 200px;
            padding: 0.6rem 0.85rem;
            border-radius: 0.75rem;
            border: 1px solid var(--border);
            background: rgba(15, 23, 42, 0.6);
            color: var(--text);
        }

        .table-wrapper {
            max-height: 420px;
            overflow: auto;
            margin: 0 1.5rem 1.5rem;
            border-radius: 0.85rem;
            border: 1px solid rgba(148, 163, 184, 0.15);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
            background: rgba(15, 23, 42, 0.7);
        }

        th,
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.15);
            vertical-align: top;
        }

        th {
            position: sticky;
            top: 0;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(8px);
            z-index: 2;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        tbody tr:nth-child(even) td {
            background: rgba(30, 41, 59, 0.55);
        }

        tbody tr.row-match td {
            background: rgba(56, 189, 248, 0.12);
        }

        tbody tr.row-match-store td {
            background: rgba(167, 139, 250, 0.16);
        }

        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.82rem;
            line-height: 1.4;
        }

        .muted {
            color: var(--muted);
            font-style: italic;
        }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
        }

        .badge-true {
            background: rgba(74, 222, 128, 0.15);
            color: #bbf7d0;
        }

        .badge-false {
            background: rgba(248, 113, 113, 0.15);
            color: #fecaca;
        }

        .payload-preview {
            margin: 0;
        }

        .payload-preview summary {
            cursor: pointer;
            color: var(--accent);
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .payload-preview[open] summary {
            color: var(--text);
        }

        .payload-preview pre {
            margin-top: 0.75rem;
            padding: 0.75rem;
            background: rgba(15, 23, 42, 0.6);
            border-radius: 0.75rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 2.25rem 1rem 3rem;
            }

            .business-card {
                padding: 1.5rem;
            }

            .table-wrapper {
                margin: 0 1rem 1.25rem;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Business Sanity Check</h1>
    <p class="subtitle">
        Quickly audit everything pulled for a business or store. Search by business name or identifier to surface every
        related record across the integration schema, and filter within each dataset to verify onboarding quality.
    </p>

    <form class="search-form" method="get" action="/sanity-check">
        <label for="query">Business identifier or name</label>
        <div class="search-row">
            <input type="text" id="query" name="query" value="<?= $escape($query) ?>" placeholder="e.g. Poynt Market or biz_123" autofocus />
            <button type="submit">Run Audit</button>
            <?php if ($query !== ''): ?>
                <a class="link-button" href="/sanity-check">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= $escape($error) ?></div>
    <?php endforeach; ?>

    <?php if ($query !== '' && empty($businessSummaries) && empty($errors)): ?>
        <div class="alert alert-error">No data found for the provided query.</div>
    <?php endif; ?>

    <?php foreach ($businessSummaries as $summaryIndex => $summary):
        $business = $summary['business'];
        $tables = $summary['tables'];
        $businessName = $business['name'] ?? '';
        $businessId = $business['business_id'] ?? '';
        $matchedStores = $summary['matchedStores'] ?? [];
        $matchedStoreIds = $summary['matchedStoreIds'] ?? [];
        $matchedDirectly = !empty($summary['matchedDirectly']);
        ?>
        <section class="business-card">
            <header class="business-header">
                <h2><?= $escape($businessName !== '' ? $businessName : $businessId) ?></h2>
                <div class="business-flags">
                    <?php if ($matchedDirectly): ?>
                        <span class="match-badge">Direct business match</span>
                    <?php endif; ?>
                    <?php if (!$matchedDirectly && !empty($matchedStores)): ?>
                        <span class="match-badge match-badge-alt">Found via store search</span>
                    <?php endif; ?>
                </div>
                <div class="business-meta">
                    <?php if ($businessId !== ''): ?>
                        <span>Business ID: <?= $escape($businessId) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($business['created_at'])): ?>
                        <span>Created: <?= $escape($business['created_at']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($business['updated_at'])): ?>
                        <span>Updated: <?= $escape($business['updated_at']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($matchedStores)): ?>
                    <div class="matched-stores">
                        <strong>Matched store<?= count($matchedStores) === 1 ? '' : 's' ?> for this search</strong>
                        <div class="matched-store-list">
                            <?php foreach ($matchedStores as $store):
                                $storeName = trim((string) ($store['name'] ?? ''));
                                $storeId = (string) ($store['store_id'] ?? '');
                                $storeNumber = trim((string) ($store['store_number'] ?? ''));
                                $storeStatus = trim((string) ($store['status'] ?? ''));
                                $parts = [];
                                if ($storeName !== '') {
                                    $parts[] = $storeName;
                                }
                                if ($storeNumber !== '') {
                                    $parts[] = 'Store #' . $storeNumber;
                                }
                                if ($storeId !== '') {
                                    $parts[] = 'ID ' . $storeId;
                                }
                                if ($storeStatus !== '') {
                                    $parts[] = ucfirst($storeStatus);
                                }
                                $label = implode(' • ', array_unique(array_filter($parts)));
                                if ($label === '') {
                                    $label = $storeId !== '' ? 'ID ' . $storeId : 'Store';
                                }
                                ?>
                                <span class="store-chip"><?= $escape($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </header>

            <?php foreach ($tables as $tableName => $rows):
                $tableId = sprintf('table-%d-%s', $summaryIndex, preg_replace('/[^a-z0-9_-]/i', '-', $tableName));
                $columns = !empty($rows) ? array_keys($rows[0]) : [];
                $recordCount = count($rows);
                $defaultOpen = in_array($tableName, ['business', 'store'], true);
                ?>
                <details<?= $defaultOpen ? ' open' : '' ?>>
                    <summary>
                        <span class="table-summary">
                            <span><?= $humanize($tableName) ?></span>
                            <span class="record-count"><?= $recordCount ?> row<?= $recordCount === 1 ? '' : 's' ?></span>
                        </span>
                        <span aria-hidden="true">⌄</span>
                    </summary>

                    <?php if (!empty($rows)): ?>
                        <div class="table-tools">
                            <label for="filter-<?= $tableId ?>">Filter rows</label>
                            <input type="search" id="filter-<?= $tableId ?>" data-target="<?= $tableId ?>" placeholder="Type to filter this table" />
                        </div>
                        <div class="table-wrapper">
                            <table id="<?= $tableId ?>" data-table-name="<?= $escape($tableName) ?>">
                                <thead>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <th scope="col"><?= $escape($column) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row):
                                    $rowClasses = [];
                                    if ($matchedDirectly && $tableName === 'business') {
                                        $rowClasses[] = 'row-match';
                                    }
                                    $rowStoreId = isset($row['store_id']) ? (string) $row['store_id'] : null;
                                    $matchesStore = $rowStoreId !== null && in_array($rowStoreId, $matchedStoreIds, true);
                                    if ($matchesStore) {
                                        $rowClasses[] = 'row-match';
                                        $rowClasses[] = 'row-match-store';
                                    }
                                    $rowClassAttr = empty($rowClasses) ? '' : ' class="' . implode(' ', $rowClasses) . '"';
                                    ?>
                                    <tr<?= $rowClassAttr ?>>
                                        <?php foreach ($columns as $column): ?>
                                            <td><?= $formatValue($column, $row[$column] ?? null) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="table-tools" style="padding-bottom: 1.5rem;">
                            <span class="muted">No records for this table.</span>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<script>
    (() => {
        const filterInputs = document.querySelectorAll('input[data-target]');
        filterInputs.forEach(input => {
            const tableId = input.dataset.target;
            const table = document.getElementById(tableId);
            if (!table) {
                return;
            }

            input.addEventListener('input', () => {
                const term = input.value.trim().toLowerCase();
                table.querySelectorAll('tbody tr').forEach(row => {
                    if (!row.dataset.searchText) {
                        row.dataset.searchText = row.textContent.toLowerCase();
                    }

                    row.style.display = term === '' || row.dataset.searchText.includes(term) ? '' : 'none';
                });
            });
        });
    })();
</script>
</body>
</html>

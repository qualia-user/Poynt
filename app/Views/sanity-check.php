<?php
/**
 * @var string $query
 * @var array<int, string> $errors
 * @var array<int, array{business: array<string, mixed>, tables: array<string, array<int, array<string, mixed>>>}> $businessSummaries
 */

$escape = static function (mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$formatValue = static function (mixed $value) use (&$formatValue, $escape): string {
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
        return '<pre>' . $escape($encoded ?: '[]') . '</pre>';
    }

    if (is_object($value)) {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '<pre>' . $escape($encoded ?: '{}') . '</pre>';
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $formatValue($decoded);
            }
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
    ?>
        <section class="business-card">
            <header class="business-header">
                <h2><?= $escape($businessName !== '' ? $businessName : $businessId) ?></h2>
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
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <?php foreach ($columns as $column): ?>
                                                <td><?= $formatValue($row[$column] ?? null) ?></td>
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

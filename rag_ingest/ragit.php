<?php
/** @var \Stanford\REDCapChatBot\REDCapChatBot $module */

// Basic access control (tighten if needed)
if (!SUPER_USER && !USERID) {
    exit("Access denied");
}

$rag               = $module->getRedcapRAGInstance();

$current_pid = isset($_GET['pid']) ? $_GET['pid'] : null;
$projectIdentifier = $module->getProjectSetting("project_rag_project_identifier", $current_pid);
if (!$projectIdentifier) {
    echo "<h2>No RAG project identifier configured. $config_pid</h2>";
    exit;
}

$action    = $_POST['action'] ?? null;
$results   = null;
$rows      = [];
$ingestLog = "";

// Heat-map helper for scores
function ragScoreClass(?float $val): string {
    if ($val === null) return '';
    if ($val >= 0.85)   return 'score-high';
    if ($val >= 0.7)    return 'score-mid';
    return 'score-low';
}

// Decide default active tab
$activeTab = 'ingest';
if ($action === 'search') {
    $activeTab = 'search';
} elseif (in_array($action, ['delete', 'purge'], true)) {
    $activeTab = 'docs';
} elseif ($action === 'ingest') {
    $activeTab = 'ingest';
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ingest JSON files into RAG
    if ($action === 'ingest' && isset($_FILES['rag_files'])) {
        set_time_limit(300);

        $ingestLog .= "Processing Uploaded Files...\n\n";

        if (!$rag) {
            $ingestLog .= "Error: Could not load RedcapRAG EM instance.\n";
        } else {
            $files = $_FILES['rag_files'];

            $maxFiles = 5;
            $count = is_array($files['name']) ? count($files['name']) : 0;

            if ($count > $maxFiles) {
                $ingestLog .= "You uploaded {$count} files. Only the first {$maxFiles} will be processed.\n\n";
                $count = $maxFiles;
            }
            
            for ($i = 0; $i < $count; $i++) {
                $name = $files['name'][$i];
                $tmp  = $files['tmp_name'][$i];

                if (!is_uploaded_file($tmp)) {
                    $ingestLog .= "File: {$name}\n  ❌ Upload error. Skipping.\n\n";
                    continue;
                }

                $ingestLog .= "File: {$name}\n";

                $raw = file_get_contents($tmp);
                $json = json_decode($raw, true);

                if (!$json) {
                    $ingestLog .= "  ❌ Failed to parse JSON. Skipping.\n\n";
                    continue;
                }

                // Support new rpp.v1 structure
                $documents = $json['documents'] ?? [];

                if (empty($documents)) {
                    $ingestLog .= "  ❌ No 'documents' array found in JSON. Skipping.\n\n";
                    continue;
                }

                $sectionCount = 0;
                $successCount = 0;
                $failCount = 0;

                // Process each document
                foreach ($documents as $document) {
                    $doc_id     = $document['doc_id'] ?? 'unknown';
                    $source     = $document['source'] ?? [];
                    $source_uri = $source['uri'] ?? '';
                    $source_type = $source['type'] ?? 'unknown';
                    $sections   = $document['sections'] ?? [];

                    // Process each section as a RAG document
                    foreach ($sections as $section) {
                        $section_id = $section['section_id'] ?? 'unknown';
                        $text       = $section['text'] ?? '';

                        if (empty($text)) {
                            continue; // Skip empty sections
                        }

                        $sectionCount++;

                        // Use section_id as title (more descriptive than doc_id)
                        $title = $section_id;

                        // Build metadata
                        $meta = [
                            'doc_id'      => $doc_id,
                            'section_id'  => $section_id,
                            'source_type' => $source_type,
                            'source_uri'  => $source_uri,
                            'file'        => $name,
                        ];

                        // Add location info if present
                        if (!empty($section['location'])) {
                            $meta['location'] = $section['location'];
                        }

                        $doc = $text . "\n\n(Metadata: " . json_encode($meta) . ")";

                        // Attempt to store with retry logic for rate limiting
                        $errorMsg = null;
                        $success = false;
                        $maxRetries = 4; // Increased from 3 since we have time

                        for ($retry = 0; $retry < $maxRetries; $retry++) {
                            $success = $rag->storeDocument($projectIdentifier, $title, $doc, null, $errorMsg);

                            if ($success) {
                                break;
                            }

                            // If rate limited, wait longer before retry
                            if ($retry < $maxRetries - 1 && $errorMsg && stripos($errorMsg, 'network') !== false) {
                                $waitTime = (int)pow(2, $retry + 1) * 5; // Exponential backoff: 10s, 20s, 40s
                                $ingestLog .= "    ⏳ Rate limit hit on {$section_id}, waiting {$waitTime}s before retry " . ($retry + 2) . "/{$maxRetries}\n";
                                sleep($waitTime);
                            }
                        }

                        if ($success) {
                            $successCount++;
                        } else {
                            $failCount++;
                            $textLen = strlen($text);
                            $docLen = strlen($doc);
                            $tokenEstimate = (int)($docLen / 4); // Rough estimate: 1 token ≈ 4 chars
                            $ingestLog .= "    ⚠️  Failed to ingest section: {$section_id} (after {$maxRetries} attempts)\n";
                            $ingestLog .= "       Length: {$textLen} chars (+ metadata = {$docLen} chars, ~{$tokenEstimate} tokens)\n";
                            if ($errorMsg) {
                                $ingestLog .= "       Error: {$errorMsg}\n";
                            }
                        }

                        // Add delay between API calls to prevent rate limiting
                        // 4000ms = 4 seconds (conservative for occasional bulk ingestion)
                        usleep(4000000);
                    }
                }

                if ($failCount > 0) {
                    $ingestLog .= "  ⚠️  Partially completed {$name}: {$successCount}/{$sectionCount} sections succeeded, {$failCount} failed\n\n";
                } else {
                    $ingestLog .= "  ✅ Finished ingesting {$name} ({$successCount}/{$sectionCount} sections)\n\n";
                }
            }
        }

        $ingestLog .= "All ingestion complete.\n";
    }

    // Delete single doc
    if ($action === 'delete' && !empty($_POST['doc_id'])) {
        $rag->deleteContextDocument($projectIdentifier, $_POST['doc_id']);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Purge namespace
    if (
        $action === 'purge' &&
        !empty($_POST['confirm']) &&
        $_POST['confirm'] === $projectIdentifier
    ) {
        $rag->purgeContextNamespace($projectIdentifier);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Search (hybrid dense+sparse)
    if ($action === 'search' && !empty($_POST['query'])) {
        $results = $rag->debugSearchContext($projectIdentifier, $_POST['query'], 3);
    }
}

// Always refresh docs for Docs tab
$rows = $rag->listContextDocuments($projectIdentifier);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Cappy RAG Tools</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <style>
        body {
            padding: 20px;
        }
        .score-high { background: #d4edda; }
        .score-mid  { background: #fff3cd; }
        .score-low  { background: #f8d7da; }

        th[data-sort-key] {
            cursor: pointer;
            white-space: nowrap;
        }
        th[data-sort-key]::after {
            content: ' ⇅';
            font-size: 0.75rem;
            color: #999;
        }
        .table-fixed-header thead th {
            position: sticky;
            top: 0;
            background-color: #f8f9fa;
            z-index: 2;
        }
        .content-preview {
            font-family: monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 480px;
        }
        pre.small-pre {
            font-size: 0.8rem;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-0">Cappy RAG Tools</h2>
            <div class="text-muted small">
                Namespace: <code><?= htmlspecialchars($projectIdentifier) ?></code>
            </div>
        </div>
        <span class="badge bg-secondary align-self-start mt-2">Hybrid Dense + Sparse</span>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="ragTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link <?= $activeTab === 'ingest' ? 'active' : '' ?>"
                id="tab-ingest"
                data-bs-toggle="tab"
                data-bs-target="#pane-ingest"
                type="button"
                role="tab"
                aria-controls="pane-ingest"
                aria-selected="<?= $activeTab === 'ingest' ? 'true' : 'false' ?>"
            >
                Ingest JSON
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link <?= $activeTab === 'search' ? 'active' : '' ?>"
                id="tab-search"
                data-bs-toggle="tab"
                data-bs-target="#pane-search"
                type="button"
                role="tab"
                aria-controls="pane-search"
                aria-selected="<?= $activeTab === 'search' ? 'true' : 'false' ?>"
            >
                Search / Debug
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link <?= $activeTab === 'docs' ? 'active' : '' ?>"
                id="tab-docs"
                data-bs-toggle="tab"
                data-bs-target="#pane-docs"
                type="button"
                role="tab"
                aria-controls="pane-docs"
                aria-selected="<?= $activeTab === 'docs' ? 'true' : 'false' ?>"
            >
                Stored Docs / Purge
            </button>
        </li>
    </ul>

    <div class="tab-content" id="ragTabContent">

        <!-- Ingest Tab -->
        <div
            class="tab-pane fade <?= $activeTab === 'ingest' ? 'show active' : '' ?>"
            id="pane-ingest"
            role="tabpanel"
            aria-labelledby="tab-ingest"
        >
            <div class="card shadow-sm mb-3">
                <div class="card-header">
                    <strong>Cappy RAG Ingestion</strong>
                </div>
                <div class="card-body">
                    <p>
                        This ingests JSON RAG documents into the scope of this project:
                    </p>
                    <ul>
                        <li>
                            <strong>Project Identifier:</strong>
                            <code><?= htmlspecialchars($projectIdentifier) ?></code>
                        </li>
                    </ul>

                    <form method="POST" enctype="multipart/form-data" class="mb-3">
                        <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                        <input type="hidden" name="action" value="ingest">
                        <div class="mb-2">
                            <label class="form-label">
                                Select one or more <code>.json</code> files to ingest:
                            </label>
                            <input
                                type="file"
                                name="rag_files[]"
                                accept=".json"
                                multiple
                                required
                                class="form-control"
                            >
                        </div>
                        <button type="submit" class="btn btn-primary">
                            Upload &amp; Ingest
                        </button>
                    </form>

                    <h6>JSON File Template (Minimal Structure)</h6>
                    <pre class="border rounded p-2 bg-light small-pre">
{
  "documents": [
    {
      "doc_id": "doc_manual_001",
      "source": {
        "type": "url",
        "uri": "https://example.com/page.html"
      },
      "sections": [
        {
          "section_id": "sec_001",
          "text": "Your section content goes here..."
        },
        {
          "section_id": "sec_002",
          "text": "Another section with more content..."
        }
      ]
    }
  ]
}
                    </pre>
                    <p class="small text-muted mb-0">
                        <strong>Note:</strong> Each section becomes a separate RAG document.
                        You can include multiple documents in the "documents" array.
                    </p>

                    <?php if (!empty($ingestLog)): ?>
                        <div class="alert alert-secondary mt-3 mb-0">
                            <pre class="small-pre mb-0"><?= htmlspecialchars($ingestLog) ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Search / Debug Tab -->
        <div
            class="tab-pane fade <?= $activeTab === 'search' ? 'show active' : '' ?>"
            id="pane-search"
            role="tabpanel"
            aria-labelledby="tab-search"
        >
            <div class="card shadow-sm">
                <div class="card-header">
                    <strong>Vector Search Test</strong>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-2 align-items-center mb-2">
                        <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                        <input type="hidden" name="action" value="search">
                        <div class="col-12 col-md-9">
                            <input
                                type="text"
                                name="query"
                                class="form-control"
                                placeholder="Enter test query text"
                                value="<?= isset($_POST['query']) && $action === 'search'
                                    ? htmlspecialchars($_POST['query'])
                                    : '' ?>"
                            >
                        </div>
                        <div class="col-12 col-md-3 d-grid">
                            <button type="submit" class="btn btn-primary">
                                Run Search
                            </button>
                        </div>
                    </form>

                    <?php if (is_array($results)): ?>
                        <hr class="mt-3 mb-2">
                        <h6 class="fw-semibold">
                            Search Results
                            <?php if (!empty($_POST['query']) && $action === 'search'): ?>
                                <small class="text-muted">
                                    for "<?= htmlspecialchars($_POST['query']) ?>"
                                </small>
                            <?php endif; ?>
                        </h6>

                        <?php if (count($results)): ?>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm table-hover table-fixed-header align-middle mb-0" id="results-table">
                                    <thead>
                                    <tr>
                                        <th data-sort-key="id" data-sort-type="string">ID</th>
                                        <th data-sort-key="dense" data-sort-type="number">Dense</th>
                                        <th data-sort-key="sparse" data-sort-type="number">Sparse</th>
                                        <th data-sort-key="similarity" data-sort-type="number">Similarity</th>
                                        <th>Content (first 140 chars)</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($results as $r): ?>
                                        <?php
                                        $dense      = isset($r['dense']) ? (float)$r['dense'] : null;
                                        $sparse     = isset($r['sparse']) ? (float)$r['sparse'] : null;
                                        $similarity = isset($r['similarity']) ? (float)$r['similarity'] : null;
                                        ?>
                                        <tr
                                            data-id="<?= htmlspecialchars($r['id']) ?>"
                                            data-dense="<?= $dense !== null ? $dense : '' ?>"
                                            data-sparse="<?= $sparse !== null ? $sparse : '' ?>"
                                            data-similarity="<?= $similarity !== null ? $similarity : '' ?>"
                                        >
                                            <td class="small text-monospace">
                                                <?= htmlspecialchars($r['id']) ?>
                                            </td>
                                            <td class="<?= ragScoreClass($dense) ?>">
                                                <?= $dense !== null ? round($dense, 4) : '-' ?>
                                            </td>
                                            <td class="<?= ragScoreClass($sparse) ?>">
                                                <?= $sparse !== null ? round($sparse, 4) : '-' ?>
                                            </td>
                                            <td class="<?= ragScoreClass($similarity) ?>">
                                                <?= $similarity !== null ? round($similarity, 4) : '-' ?>
                                            </td>
                                            <td class="small">
                                                <?= htmlspecialchars(substr($r['content'], 0, 140)) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary mt-3 mb-0">No matches.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Docs / Purge Tab -->
        <div
            class="tab-pane fade <?= $activeTab === 'docs' ? 'show active' : '' ?>"
            id="pane-docs"
            role="tabpanel"
            aria-labelledby="tab-docs"
        >
            <div class="row g-3">
                <div class="col-12">
                    <div class="card shadow-sm mb-3">
                        <div class="card-header">
                            <strong>Namespace Maintenance</strong>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-2 align-items-center">
                                <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                                <input type="hidden" name="action" value="purge">
                                <div class="col-12 col-md-7 small">
                                    <label class="form-label mb-1">
                                        Type the namespace to purge all vectors:
                                    </label>
                                    <input
                                        type="text"
                                        name="confirm"
                                        class="form-control form-control-sm"
                                        placeholder="<?= htmlspecialchars($projectIdentifier) ?>"
                                    >
                                </div>
                                <div class="col-12 col-md-5 d-grid mt-3 mt-md-4">
                                    <button
                                        type="submit"
                                        class="btn btn-outline-danger btn-sm"
                                        onclick="return confirm('This will delete all vectors in this namespace. Continue?');"
                                    >
                                        Purge Namespace
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>Stored Documents</strong>
                            <span class="badge bg-light text-dark">
                                <?= count($rows) ?> records
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($rows)): ?>
                                <div class="table-responsive" style="max-height: 420px;">
                                    <table class="table table-sm table-hover table-fixed-header align-middle mb-0" id="docs-table">
                                        <thead>
                                        <tr>
                                            <th data-sort-key="id" data-sort-type="string">ID</th>
                                            <th data-sort-key="source" data-sort-type="string">Source</th>
                                            <th>Content</th>
                                            <th style="width:130px;">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($rows as $idx => $r): ?>
                                            <?php
                                            $rowId      = htmlspecialchars($r['id']);
                                            $collapseId = 'docDetails_' . $idx;
                                            ?>
                                            <tr
                                                data-id="<?= $rowId ?>"
                                                data-source="<?= htmlspecialchars($r['source'] ?? '') ?>"
                                            >
                                                <td class="small text-monospace"><?= $rowId ?></td>
                                                <td><?= htmlspecialchars($r['source'] ?? '') ?></td>
                                                <td class="content-preview small">
                                                    <?= htmlspecialchars(substr($r['content'], 0, 120)) ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-secondary toggle-details"
                                                            data-bs-target="#<?= $collapseId ?>"
                                                        >
                                                            View
                                                        </button>
                                                        <form method="post" style="display:inline;">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="redcap_csrf_token" value="<?= $module->getCSRFToken() ?>">
                                                            <input type="hidden" name="doc_id" value="<?= $rowId ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-danger"
                                                                onclick="return confirm('Delete this document from the namespace?');"
                                                            >
                                                                Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="table-light">
                                                <td colspan="4" class="p-0">
                                                    <div
                                                        id="<?= $collapseId ?>"
                                                        class="collapse border-top small px-3 py-2"
                                                    >
                                                        <div class="fw-semibold mb-1">Full Content</div>
                                                        <pre class="mb-2" style="white-space:pre-wrap; font-size:11px;"><?= htmlspecialchars($r['content']) ?></pre>
                                                        <div class="text-muted small">
                                                            <strong>Source:</strong> <?= htmlspecialchars($r['source'] ?? '') ?>
                                                            <?php if (!empty($r['meta_timestamp'])): ?>
                                                                &nbsp; | &nbsp;
                                                                <strong>Timestamp:</strong> <?= htmlspecialchars($r['meta_timestamp']) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="p-3">
                                    <em class="text-muted">No stored context documents.</em>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.tab-content -->
</div><!-- /.container-fluid -->

<!-- Bootstrap JS bundle -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>

<script>
// Tab persistence via URL hash
document.addEventListener('DOMContentLoaded', function () {
    const hash = window.location.hash;
    if (hash) {
        const trigger = document.querySelector(`#ragTab [data-bs-target="${hash}"]`);
        if (trigger) {
            const tab = new bootstrap.Tab(trigger);
            tab.show();
        }
    }

    document.querySelectorAll('#ragTab [data-bs-toggle="tab"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function (event) {
            const target = event.target.getAttribute('data-bs-target');
            if (target) {
                history.replaceState(null, '', target);
            }
        });
    });

    // Simple table sorting for any table with th[data-sort-key]
    document.querySelectorAll('th[data-sort-key]').forEach(function (th) {
        th.addEventListener('click', function () {
            const table   = th.closest('table');
            const tbody   = table.querySelector('tbody');
            const key     = th.dataset.sortKey;
            const isNumeric = th.dataset.sortType === 'number';
            const currentDir = th.dataset.sortDir || 'asc';
            const newDir     = currentDir === 'asc' ? 'desc' : 'asc';
            th.dataset.sortDir = newDir;

            const rows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
                // Skip detail rows in docs table
                return !row.querySelector('.collapse');
            });

            rows.sort(function (a, b) {
                const aVal = a.dataset[key] || '';
                const bVal = b.dataset[key] || '';

                let cmp;
                if (isNumeric) {
                    const aNum = parseFloat(aVal) || 0;
                    const bNum = parseFloat(bVal) || 0;
                    cmp = aNum - bNum;
                } else {
                    cmp = aVal.localeCompare(bVal);
                }

                return newDir === 'asc' ? cmp : -cmp;
            });

            rows.forEach(function (row) {
                tbody.insertBefore(row, tbody.firstChild);
            });
        });
    });

    // Collapsible "View" buttons for stored docs
    document.querySelectorAll('.toggle-details').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-bs-target');
            if (!targetId) return;
            const el = document.querySelector(targetId);
            if (!el) return;
            const c = bootstrap.Collapse.getOrCreateInstance(el);
            c.toggle();
        });
    });
});
</script>

</body>
</html>

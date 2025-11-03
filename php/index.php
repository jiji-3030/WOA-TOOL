<?php
// === Load Configuration ===
$config = require __DIR__ . '/config.php';
// === File Upload Configuration ===
$upload_dir = __DIR__ . '/test_uploads';
// Create folder if not present
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Ensure writable (so PHP can move_uploaded_file)
if (!is_writable($upload_dir)) {
    // Try changing permissions - may fail depending on server setup
    @chmod($upload_dir, 0777);
}

// === Utility Helpers ===
function get_workdir()
{
    global $config;
    return $config['workdir'];
}


function build_predict_cmd($imagePath)
{
    global $config;
    $python = escapeshellcmd($config['python_path']);
    $workdir = escapeshellarg($config['workdir']);

    // Assuming model_ewoa.json is the correct model for the main prediction
    $model = escapeshellarg($config['workdir'] . '/models/model_ewoa.json');

    $image = escapeshellarg($imagePath);
    // Ensure the module path is correct if your script is inside woa_tool/cli.py
    return "PYTHONPATH=$workdir $python -m woa_tool.cli predict --model $model --image $image";
}

// === Pretty Names (Keep this updated!) ===
$pretty_names = [
    // === TEXTURE FEATURES (GLCM) ===
    "glcm_ASM" => "GLCM Angular Second Moment (Homogeneity)",
    "glcm_contrast" => "GLCM Contrast (Texture Roughness)",
    "glcm_correlation" => "GLCM Correlation (Pixel Dependency)",
    "glcm_variance" => "GLCM Variance (Gray-Level Spread)",
    "glcm_IDM" => "GLCM Inverse Difference Moment (Uniformity)",
    "glcm_sum_avg" => "GLCM Sum Average", // Added based on zscores
    "glcm_sum_var" => "GLCM Sum Variance", // Added
    "glcm_sum_entropy" => "GLCM Sum Entropy (Complexity)",
    "glcm_entropy" => "GLCM Entropy (Randomness)",
    "glcm_diff_var" => "GLCM Difference Variance", // Added
    "glcm_diff_entropy" => "GLCM Difference Entropy", // Added
    "glcm_IMC1" => "GLCM Info Measure of Correlation 1",
    "glcm_IMC2" => "GLCM Info Measure of Correlation 2",
    "glcm_direction_var" => "GLCM Directional Variance", // Added

    // === HISTOGRAM INTENSITY FEATURES ===
    "hist_mean" => "Histogram Mean Intensity (μ)",
    "hist_std" => "Histogram Standard Deviation (σ)",
    "hist_skew" => "Histogram Skewness (Asymmetry)",
    "hist_kurtosis" => "Histogram Kurtosis (Peak Sharpness)",
    "hist_q25" => "Histogram 25th Percentile (Q1)",
    "hist_q50" => "Histogram Median (Q2)",
    "hist_q75" => "Histogram 75th Percentile (Q3)",
    "density_index" => "Tissue Density Index",

    // === EDGE AND GRADIENT FEATURES ===
    "edge_sobel_mean" => "Mean Edge Strength (Sobel)",
    "edge_sobel_std" => "Edge Strength Variability (Sobel)",
    "edge_ratio" => "Edge Ratio", // Added
    "grad_coherence_mean" => "Gradient Coherence Mean",
    "grad_coherence_std" => "Gradient Coherence Std",
    "sharp_lap_var" => "Laplacian Variance (Sharpness)", // Added

    // === SHAPE & ASYMMETRY FEATURES ===
    "shape_area" => "Shape Area (pixels²)",
    "shape_perimeter" => "Shape Perimeter (pixels)",
    "shape_circularity" => "Shape Circularity",
    "shape_eccentricity" => "Shape Eccentricity (Elongation)",
    "shape_solidity" => "Shape Solidity",
    "shape_extent" => "Shape Extent Ratio",
    "shape_norm_area" => "Normalized Shape Area", // Added
    "asym_absdiff_mean" => "Asymmetry Abs Diff Mean",
    "asym_absdiff_std" => "Asymmetry Abs Diff Std",
    "asym_mean_diff" => "Asymmetry Mean Difference",

    // === MASS & BLOB CHARACTERISTICS ===
    "blob_count" => "Detected Blob Count",
    "blob_density" => "Blob Density", // Added
    "blob_radius_mean" => "Average Blob Radius",
    "blob_radius_std" => "Blob Radius Variability",

    // === SPICULATION FEATURES ===
    "spic_edge_density" => "Spiculation Edge Density",
    "spic_edge_ring_ratio" => "Spiculation Ring Ratio",
    "spic_orient_dispersion" => "Spiculation Orientation Dispersion", // Added

    // === ABNORMALITY SCORES (Specific to your output) ===
    "texture_disorder" => "Texture Disorder Score",
    "shape_irregularity" => "Shape Irregularity Score",
    "spiculation_index" => "Spiculation Index Score",
    // "density_index" => "Density Index Score", // Already defined above

    // === Additional Synthesized Features (Optional - keep if needed) ===
    "texture_variance" => "Texture Variance (Derived)",
    "asymmetry_index" => "Global Asymmetry Index",
    "compactness" => "Lesion Compactness",
    "roughness" => "Surface Roughness Estimate",
];

// === Standard PHP Setup ===
ob_start();
if (!empty($_POST['ajax'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ERROR | E_PARSE);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

$result = null;
$error = null;
$uploadedImageWebPath = null;
$isDebug = isset($_GET['debug']);
$debug_pack = null; // Initialize debug pack

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {

    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error code: " . $_FILES['image']['error'];
    } elseif ($_FILES['image']['size'] == 0) {
        $error = "Uploaded file is empty.";
    } elseif (!is_uploaded_file($_FILES['image']['tmp_name'])) {
        $error = "Possible file upload attack.";
    } else {
        $fileName = uniqid('img_', true) . '-' . preg_replace('/[^A-Za-z0-9\.\-\_]/', '', basename($_FILES['image']['name']));
        $targetPath = $upload_dir . '/' . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $uploadedImageWebPath = 'test_uploads/' . basename($targetPath);

            // --- Real Prediction Logic ---
            if (empty($_POST['mock'])) {
                $cmd = build_predict_cmd($targetPath);
                $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],];
                $proc = proc_open($cmd, $desc, $pipes, get_workdir());

                if (is_resource($proc)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code = proc_close($proc);

                    $decoded = json_decode($stdout, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        // --- Normalize abnormality_type (Seems okay based on your JSON example) ---
                        if (!isset($decoded['abnormality_type'])) {
                            if (isset($decoded['abnormality']['type'])) { $decoded['abnormality_type'] = $decoded['abnormality']['type']; }
                            elseif (isset($decoded['abnormality'])) { $decoded['abnormality_type'] = is_array($decoded['abnormality']) ? ($decoded['abnormality']['label'] ?? null) : $decoded['abnormality']; }
                            elseif (isset($decoded['lesion_type'])) { $decoded['abnormality_type'] = $decoded['lesion_type']; }
                        }
                        // --- end normalization ---
                        $result = $decoded;
                    } else {
                        $jsonErrorMsg = json_last_error_msg();
                        $error = "Model did not return valid JSON (Error: $jsonErrorMsg).";
                        if (!empty($stderr) || $code !== 0 || !empty($stdout)) {
                         $error .= "<br>Exit Code: " . htmlspecialchars($code);
                         if (!empty($stderr)) { $error .= "<br>Stderr: <pre>" . htmlspecialchars($stderr) . "</pre>"; }
                             if (!empty($stdout) && json_last_error() !== JSON_ERROR_NONE) { $error .= "<br>Raw Stdout: <pre>" . htmlspecialchars($stdout) . "</pre>"; }
                        }
                    }

                    if ($isDebug) {
                        $model_path = $config['workdir'] . '/models/model_ewoa.json';
                        $debug_pack = [ /* ... (keep your debug pack fields) ... */ ];
                    }

                } else {
                    $error = "proc_open failed — shell execution issue? Check PHP configuration (e.g., disable_functions), server permissions, or if the Python path is correct.";
                }
            }
            // --- End Real Prediction ---

        } else {
            $error = "Failed to move uploaded file. Check permissions for '$upload_dir'. Error code: " . ($_FILES['image']['error'] ?? 'unknown');
        }
    }
}

// === AJAX Response (Real or Error) ===
if (!empty($_POST['ajax'])) {
    $noise = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
    'ok'     => (bool) $result && !$error,
    'result' => $result,
    'image'  => $uploadedImageWebPath ?: null,
    'error'  => $error,
    'noise'  => $isDebug ? ($noise ?: null) : null,
    'debug'  => $isDebug ? ($debug_pack ?? null) : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
}
// === END AJAX Handling ===

$jsonData = $result ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
$jsonPrettyNames = json_encode($pretty_names); // Pass pretty names to JS
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
    <title>WOA & EWOA Breast Cancer Feature Detection</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=30"> <!-- Increment version -->
    
    <!-- NEW Styles for sticky column and legend -->
    <style>
        /* === Color Palette (from comparison.php) === */
        :root {
            --color-benign-text: #064E3B; /* dark green */
            --color-benign-bg: #D1FAE5; /* pastel green */
            --color-malignant-text: #991B1B; /* dark red */
            --color-malignant-bg: #FEE2E2; /* pastel red */
        }
        
        .left-column.is-sticky {
            position: sticky;
            top: 2rem; /* Adjust as needed */
            align-self: flex-start; /* Important for sticky to work in flex */
        }
        .sticky-controls {
            display: flex;
            justify-content: flex-end;
            margin-bottom: -1rem; /* Pull it closer to the card */
            padding-right: 1rem;
            position: relative;
            z-index: 10;
        }
        #toggle-sticky-btn {
            padding: 0.35rem 0.6rem;
            display: inline-flex;
            align-items: center;
        }
        #toggle-sticky-btn svg {
            margin-right: 0.4rem;
        }
        
        .birads-legend {
            margin-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
        }
        .legend-title {
            font-size: 0.875rem; /* text-sm */
            font-weight: 600; /* semibold */
            color: var(--text-dark);
            margin-bottom: 0.75rem;
        }
        .legend-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }
        .legend-item .birads-badge {
            flex-shrink: 0;
            margin-top: 0.1rem;
            margin-right: 0.75rem;
        }
        .legend-item p {
            font-size: 0.8rem; /* smaller */
            color: var(--text-dark);
            line-height: 1.4;
        }
        .legend-item p strong {
            font-weight: 600;
            color: var(--text-main);
        }

        /* === NEW BI-RADS T-STYLES (Pastel Colors) === */
        .birads-badge.birads-t1 {
            background-color: #E0F2FE; /* pastel blue */
            color: #0C4A6E; /* dark blue text */
        }
        .birads-badge.birads-t2 {
            background-color: #D1FAE5; /* pastel green */
            color: #064E3B; /* dark green text */
        }
        .birads-badge.birads-t3 {
            background-color: #FEF3C7; /* pastel yellow */
            color: #92400E; /* dark yellow/brown text */
        }
        .birads-badge.birads-t4 {
            background-color: #FEE2E2; /* pastel red */
            color: #991B1B; /* dark red text */
        }
        
        /* === NEW Color Coding for Tables === */
        .text-benign { color: var(--color-benign-text) !important; }
        .bg-benign-light { background-color: var(--color-benign-bg) !important; }
        .text-malignant { color: var(--color-malignant-text) !important; }
        .bg-malignant-light { background-color: var(--color-malignant-bg) !important; }
        
        /* Apply to table rows */
        .data-table tbody tr.row-benign td {
            color: var(--color-benign-text);
        }
        .data-table tbody tr.row-malignant td {
            color: var(--color-malignant-text);
        }
        /* Color for the numeric value */
        .data-table tbody tr.row-benign td.mono strong,
        .data-table tbody tr.row-benign td.mono {
            color: var(--color-benign-text) !important;
            font-weight: 600;
        }
        .data-table tbody tr.row-malignant td.mono strong,
        .data-table tbody tr.row-malignant td.mono {
            color: var(--color-malignant-text) !important;
            font-weight: 600;
        }
        /* Light pastel background on hover */
        .data-table tbody tr.row-benign:hover {
            background-color: var(--color-benign-bg);
        }
        .data-table tbody tr.row-malignant:hover {
            background-color: var(--color-malignant-bg);
        }
        
        /* === NEW Sub-headers (like comparison.php) === */
        .chart-sub-header {
            font-size: 1.1rem; /* 1.25rem; */
            font-weight: 600;
            color: var(--text-header, #333);
            margin-top: 1.5rem;
            margin-bottom: 0.25rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color, #e0e0e0);
        }
        .chart-sub-header:first-of-type {
            margin-top: 0;
        }
        .chart-sub-desc {
            font-size: 0.9rem;
            color: var(--text-dark, #555);
            margin-bottom: 1rem;
        }

        /* === NEW Layout for split charts/tables === */
        .split-layout-container {
            display: grid;
            grid-template-columns: 1fr; /* Stack on small screens */
            gap: 1.5rem 1rem;
        }
        .split-chart-wrapper {
            height: 300px; /* Default height */
        }
        
        /* Side-by-side on larger screens */
        @media (min-width: 1024px) {
             .split-layout-container {
                grid-template-columns: 1fr 1fr; /* 50/50 split */
             }
        }
        
        /* === NEW Distance Metrics Display === */
        .distance-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        .distance-metric {
            padding: 0.75rem;
            border-radius: var(--border-radius-small);
            text-align: center;
        }
        .distance-metric .metric-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-dark);
            display: block;
            margin-bottom: 0.25rem;
        }
        .distance-metric .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        /* === NEW: Layout for split charts/tables (from comparison.php) === */
        .split-layout-container {
            display: grid;
            grid-template-columns: 1fr; /* Stack on small screens */
            gap: 1.5rem 1rem;
        }
        .split-chart-wrapper {
             /* Height will be set by JS */
        }
        @media (min-width: 1024px) {
             .split-layout-container {
                grid-template-columns: 1fr 1fr; /* 50/50 split */
             }
        }
        
        /* === NEW: Layout for single table/chart in "All Features" === */
        .all-features-table-wrapper .table-wrapper-scroll {
            max-height: 600px; /* Taller default for single table */
        }
        .all-features-chart-wrapper {
             /* Height will be set by JS */
        }
        
        /* === END NEW STYLES === */
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        window.__PREDICT__ = <?php echo $jsonData; ?>;
        window.__UPLOADED_IMAGE__ = <?php echo json_encode($uploadedImageWebPath ?: null); ?>;
        window.__PRETTY_NAMES__ = <?php echo $jsonPrettyNames; ?>; // Make names available to JS
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-inner">
            <div class="header-left"> <div class="header-logo">🐋</div> <div class="header-title"> <h1>WOA: <span>Balancing Exploration–Exploitation</span></h1> <p>for Breast Cancer Feature Detection</p> </div> </div>
            <nav class="header-nav">
                <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a>
                <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a>
                <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
            </nav>
        </div>
    </header>

    <div id="aurora-background"></div>

    <div class="main-container">
        <header class="header">
             <h1> <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C10.14 2 8.5 3.65 8.5 5.5C8.5 6.4 8.89 7.2 9.5 7.82C7.03 8.35 5.3 10.13 5.3 12.39C5.3 13.53 5.79 14.58 6.6 15.35C5.59 16.32 5 17.58 5 19C5 21.21 6.79 23 9 23C10.86 23 12.5 21.35 12.5 19.5C12.5 18.6 12.11 17.8 11.5 17.18C13.97 16.65 15.7 14.87 15.7 12.61C15.7 11.47 15.21 10.42 14.4 9.65C15.41 8.68 16 7.42 16 6C16 3.79 14.21 2 12 2M12 4C13.1 4 14 4.9 14 6C14 7.03 13.2 7.9 12.18 7.97C12.12 7.99 12.06 8 12 8C10.9 8 10 7.1 10 6C10 4.9 10.9 4 12 4M9 21C7.9 21 7 20.1 7 19C7 17.97 7.8 17.1 8.82 17.03C8.88 17.01 8.94 17 9 17C10.1 17 11 17.9 11 19C11 20.1 10.1 21 9 21" /></svg> EWOA Breast Cancer Feature Detection </h1>
             <div class="quick-guide"> <h3> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg> Quick Start Guide </h3> <ul> <li><strong>Step 1:</strong> Upload mammogram (<code>.png</code>, <code>.jpg</code>, <code>.tif</code>).</li> <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong>.</li> <li><strong>Step 3:</strong> View results.</li> <li><strong>Step 4:</strong> Check <strong>History</strong> for past runs.</li> </ul> </div>
        </header>

        <div class="left-column">
            <!-- NEW STICKY CONTROLS -->
            <div class="sticky-controls">
                <button type="button" id="toggle-sticky-btn" class="btn btn-secondary btn-small" title="Toggle Sticky Panel" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="pin-icon">
                        <path d="M4.146.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L5.793 3 4.146 1.354a.5.5 0 0 1 0-.708zm0 15.708a.5.5 0 0 1 .708 0l2-2a.5.5 0 0 1 0-.708l-2-2a.5.5 0 0 1-.708.708L5.793 13l-1.647-1.646a.5.5 0 0 1 0-.708zM1.146 8.354a.5.5 0 0 1 0-.708l2-2a.5.5 0 0 1 .708.708L2.207 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2zM4 8a.5.5 0 0 1 .5-.5h7.5a.5.5 0 0 1 0 1H4.5A.5.5 0 0 1 4 8z"/>
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="unpin-icon" style="display:none;">
                        <path d="M4.146.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L5.793 3 4.146 1.354a.5.5 0 0 1 0-.708zM8 4a.5.5 0 0 1 .5.5v7.5a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4zM1.146 8.354a.5.5 0 0 1 0-.708l2-2a.5.5 0 0 1 .708.708L2.207 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2zM4 8a.5.5 0 0 1 .5-.5h7.5a.5.5 0 0 1 0 1H4.5A.5.5 0 0 1 4 8z"/>
                    </svg>
                    <span id="sticky-btn-text">Pin Panel</span>
                </button>
            </div>

            <div class="step-card">
                <div class="step-header">
                    <div class="step-header-left"> <div class="step-number">1</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg> Upload Image</h2> </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Accepted formats: .tif, .tiff, .png, .jpg, .jpeg. Size limit depends on server config.</span></span>
                </div>
                <form id="image-upload-form" method="post" enctype="multipart/form-data">
                    <div id="image-preview-wrapper" style="display: none;">
                        <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"> <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                        <canvas></canvas>
                        <p id="image-filename" class="file-meta" style="display:none;"></p>
                    </div>
                    <div class="upload-area" id="upload-area">
                        <input type="file" name="image" id="file-input" accept=".tif,.tiff,.png,.jpg,.jpeg" required>
                        <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                        <p class="upload-area__text">Drag & Drop image file or <span>browse</span> to upload.</p>
                    </div>
                </form>
            </div>
            <div class="step-card text-center">
                <div class="step-header"> <div class="step-header-left"> <div class="step-number">2</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" /></svg> Run Analysis</h2> </div> </div>
                <p style="color:var(--text-dark); margin-bottom: 2rem;">Once image selected, button active.</p>
                <button class="btn" type="submit" id="submit-btn" disabled form="image-upload-form"> <span id="btn-text">Run Prediction</span> <div class="spinner" id="spinner" style="display:none;"></div> </button>
                <button class="btn btn-secondary" type="button" id="clear-btn" style="margin-top:0; margin-left:.75rem; display: none;">↺ Reset</button>
            </div>
            
            <!-- NEW HISTORY CARD -->
            <div class="step-card" id="history-card">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px; color: white;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.183m-4.993 0H2.985" /></svg>
                        </div>
                        <h2>History</h2>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" id="clear-history-btn" title="Clear All History" style="display: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12.54 0c-.265.11-.506.227-.745.357m0 0l-1.473 1.473a.875.875 0 000 1.238l9.19 9.19a.875.875 0 001.238 0l1.473-1.473m-7.407-13.87c.19-.148.39-.287.6-.41m0 0l4.773 4.773" /></svg>
            
                    </button>
                </div>
                <div class="card-content" id="history-list-container">
                    <!-- History items will be injected here by JS -->
                    <p class="file-meta" id="history-placeholder" style="text-align:center; padding: 1rem 0;">No history saved.</p>
                    <div id="history-list"></div>
                </div>
            </div>
            <!-- END HISTORY CARD -->

            <div id="error-container"></div>
        </div>

        <div class="right-column">

            <div id="results-placeholder" style="display: block;"> <div class="step-card placeholder-card single-placeholder"> <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 20px; height: 20px; color: white;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg> </div> <h2>Results Preview</h2> </div> </div> <div class="placeholder-content"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 1.085-1.085-1.085m1.085 1.085L5.25 16.5m7.5 0l-1 1.085m0 0l-1.085-1.085m1.085 1.085L18.75 16.5m-7.5 2.25h.008v.008H11.25v-.008zM12 3.75h.008v.008H12V3.75z" /></svg> <p>Analysis results will be displayed here after running the prediction.</p> </div> </div> </div>

            <div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;"> <div class="step-card loader-card"> <div class="loader-inner"> <div class="scan-loader"> <span></span><span></span><span></span><span></span> </div> <p class="loader-caption">Analyzing mammogram... please wait</p> </div> </div> </div>

            <div class="results-container animate-slide-up" id="results-container" style="display:none;">
                <div class="step-card"> <div class="step-header">
                        <div class="step-header-left"> <div class="step-number">3</div> <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> View Results</h2> </div>
                        <div class="header-buttons">
                            <button type="button" class="btn btn-print" id="print-btn" title="Print Report"> <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 9V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v4" /> <path stroke-linecap="round" stroke-linejoin="round" d="M6 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-1" /> <path stroke-linecap="round" stroke-linejoin="round" d="M7 14h10v7H7z" /></svg> <span>Print Results</span> </button>
                            <button type="button" class="btn btn-csv" id="csv-btn" title="Download CSV"> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg> <span>Download CSV</span> </button>
                        </div>
                    </div>

                    <div class="results-grid" id="results-grid">

                        <!-- === UPDATED: Prediction Card (Simplified) === -->
                        <div class="step-card prediction-card animate-slide-up" id="prediction-card-content">
                            <div class="step-header">
                                <div class="step-header-left">
                                    <h2 style="padding-left:0;">Final Prediction <span class="pill pill-rule">Rule-based</span></h2>
                                </div>
                                <button type="button" class="maximize-card-btn" title="Maximize"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button>
                            </div>
                            <div class="card-content prediction-content-layout"> <!-- Added class -->

                                <!-- Left side: Text & Details -->
                                <div class="prediction-details-section">
                                    <div class="prediction-text-wrapper">
                                        <span class="prediction-indicator"></span>
                                        <span style="font-size:3.5rem; font-weight:800;" data-field="final_prediction">—</span>
                                    </div>
                                    <!-- REMOVED TOGGLE WRAPPER AND DECISION-DETAILS DIV -->
                                </div>

                                <!-- Right side: Visualizer -->
                                <div class="prediction-visualizer-section">
                                    <div class="final-vis" id="final-prediction-visualizer">
                                        <canvas id="prediction-gauge-chart"></canvas>
                                        <div class="ring-label">
                                            <span class="ring-main"></span>
                                            <span class="ring-sub">Confidence</span>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <!-- === END Prediction Card === -->

                        <div class="step-card animate-slide-up" id="probability-card-content" style="animation-delay:.1s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Probabilities</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Model confidence per class. This visualization supports the final prediction but the decision uses the ratio rule (τ).</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content"> <div id="probability-chart-container"><canvas id="probability-chart"></canvas></div> </div>
                        </div>

                        <div class="step-card animate-slide-up" id="background-card-content" style="animation-delay:.15s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Background Tissue Density</h2> </div> <!-- Updated Title -->
                                <span class="tooltip-icon">i<span class="tooltip-content">Inferred BI-RADS density category based on image features.</span></span> <!-- Updated Tooltip -->
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <!-- === NEW Card Content Structure === -->
                            <div class="card-content">
                                <div class="flex items-center space-x-4 mb-3">
                                    <span class="birads-badge" data-field="background_tissue_code_badge">?</span>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-500">BI-RADS Code</p>
                                        <p class="text-lg font-semibold" data-field="background_tissue_code">—</p>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-500">Description</p>
                                        <p class="text-lg font-semibold" data-field="background_tissue_text">—</p>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2" data-field="background_tissue_explain">—</p>
                                
                                <!-- === UPDATED LEGEND (T1, T2, T3, T4) === -->
                                <div class="birads-legend">
                                    <h4 class="legend-title">BI-RADS Density Legend</h4>
                                    <div class="legend-item"><span class="birads-badge birads-t1">T1</span> <p><strong>Almost entirely fatty:</strong> The breasts are almost entirely composed of fat. (0-25% dense)</p></div>
                                    <div class="legend-item"><span class="birads-badge birads-t2">T2</span> <p><strong>Scattered areas of fibroglandular density:</strong> There are scattered areas of density. (26-50% dense)</p></div>
                                    <div class="legend-item"><span class="birads-badge birads-t3">T3</span> <p><strong>Heterogeneously dense:</strong> The breasts are heterogeneously dense, which may obscure small masses. (51-75% dense)</p></div>
                                    <div class="legend-item"><span class="birads-badge birads-t4">T4</span> <p><strong>Extremely dense:</strong> The breasts are extremely dense, which lowers the sensitivity of mammography. (76-100% dense)</p></div>
                                </div>
                            </div>
                            <!-- === END NEW Card Content Structure === -->
                        </div>

                        <div class="step-card animate-slide-up" id="explanation-card-content" style="animation-delay:.20s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Explanations & Distances</h2> </div>
                                <!-- UPDATED Tooltip -->
                                <span class="tooltip-icon">i<span class="tooltip-content">Analysis of features and distance metrics contributing to the prediction.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content" id="explain-root">
                                <!-- Content generated by renderExplanations JS -->
                                
                                <!-- NEW: Distance Metrics Container -->
                                <div class="distance-metrics">
                                    <div class="distance-metric bg-benign-light">
                                        <span class="metric-label">Distance to Benign</span>
                                        <span class="metric-value text-benign" data-field="distance_to_benign">—</span>
                                    </div>
                                    <div class="distance-metric bg-malignant-light">
                                        <span class="metric-label">Distance to Malignant</span>
                                        <span class="metric-value text-malignant" data-field="distance_to_malignant">—</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-card animate-slide-up" id="abnormality-card-content" style="animation-delay:.25s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Abnormality Scores</h2> </div> <span class="tooltip-icon">i<span class="tooltip-content">Calculated scores for different abnormality characteristics.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content">
                                <div class="abnormality-chart-wrapper"> <canvas id="abnormality-chart"></canvas> </div>
                            </div>
                        </div>

                        <!-- === NEW: Feature Contributions Card (like comparison.php) === -->
                        <div class="step-card animate-slide-up" id="tfc-card-content" style="animation-delay:.30s;">
                            <div class="step-header">
                                <div class="step-header-left">
                                    <h2>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.31h5.518a.562.562 0 01.31.95l-4.203 3.03a.563.563 0 00-.182.635l1.578 4.87a.562.562 0 01-.84.61l-4.72-3.47a.563.563 0 00-.652 0l-4.72 3.47a.562.562 0 01-.84-.61l1.578-4.87a.563.563 0 00-.182-.635L2.543 9.87a.562.562 0 01.31-.95h5.518a.563.563 0 00.475-.31L11.48 3.5z"/></svg>
                                        Feature Contributions (Benign vs. Malignant)
                                    </h2>
                                </div>
                                <span class="tooltip-icon">i
                                    <span class="tooltip-content">Features from 'top_feature_contributors' separated by their leaning.</span>
                                </span>
                                <button type="button" class="maximize-card-btn" title="Maximize">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
                                </button>
                            </div>

                            <div class="card-content">
                                <!-- Malignant Section -->
                                <h3 class="chart-sub-header text-malignant">Malignant-Leaning Features</h3>
                                <p class="chart-sub-desc">Features with a negative contribution (more negative is stronger).</p>
                                <div class="split-layout-container">
                                    <div class="tfc-table-wrapper">
                                        <div class="table-wrapper-scroll" id="tfc-malignant-table-scroll" style="max-height: 300px;">
                                            <table class="data-table">
                                                <thead><tr><th>Feature</th><th>Contribution (Raw)</th></tr></thead>
                                                <tbody id="tfc-malignant-body"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="split-chart-wrapper">
                                        <canvas id="tfc-malignant-chart"></canvas>
                                    </div>
                                </div>

                                <!-- Benign Section -->
                                <h3 class="chart-sub-header text-benign">Benign-Leaning Features</h3>
                                <p class="chart-sub-desc">Features with a positive contribution (more positive is stronger).</p>
                                <div class="split-layout-container">
                                    <div class="tfc-table-wrapper">
                                        <div class="table-wrapper-scroll" id="tfc-benign-table-scroll" style="max-height: 300px;">
                                            <table class="data-table">
                                                <thead><tr><th>Feature</th><th>Contribution (Raw)</th></tr></thead>
                                                <tbody id="tfc-benign-body"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="split-chart-wrapper">
                                        <canvas id="tfc-benign-chart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- === END: Feature Contributions Card === -->

                        <!-- === NEW: All Detected Features (Tables) Card === -->
                        <div class="step-card animate-slide-up" id="all-features-tables-card" style="animation-delay:.40s;">
                                <div class="step-header">
                                    <div class="step-header-left">
                                        <h2>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
                                            All Detected Features (Tables)
                                        </h2>
                                    </div>
                                    <span class="tooltip-icon">i<span class="tooltip-content">Standardized values (z-scores) for all radiomic features. Sorted from most Benign-leaning (positive) to most Malignant-leaning (negative).</span></span>
                                    <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                                </div>
                                <div class="card-content">
                                    <div class="all-features-table-wrapper">
                                        <div class="table-wrapper-scroll" id="all-features-table-scroll">
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>Feature</th>
                                                        <th>Z-Score</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="all-features-body"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                        </div>
                        <!-- === END: All Features (Tables) Card === -->
                        
                        <!-- === NEW: All Detected Features (Charts) Card === -->
                        <div class="step-card animate-slide-up" id="all-features-charts-card" style="animation-delay:.50s;">
                                <div class="step-header">
                                    <div class="step-header-left">
                                        <h2>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" /></svg>
                                            All Detected Features (Charts)
                                        </h2>
                                    </div>
                                    <span class="tooltip-icon">i<span class="tooltip-content">Standardized values (z-scores) for all radiomic features. Sorted from most Benign-leaning (positive) to most Malignant-leaning (negative).</span></span>
                                    <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                                </div>
                                <div class="card-content">
                                    <div class="all-features-chart-wrapper">
                                        <canvas id="all-features-chart"></canvas>
                                    </div>
                                </div>
                        </div>
                        <!-- === END: All Features (Charts) Card === -->


                    </div> </div>
            </div> <?php if ($result && !$isDebug): /* Keep this for raw JSON view if needed */ ?>
                            <?php endif; ?>
        </div> </div> <footer> <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p> </footer>

    <div id="image-modal-overlay"></div>
    <div id="card-modal-overlay"> <div id="card-modal-content"> <button class="close-modal-btn">&times;</button> <h2 class="modal-title"></h2> <div class="modal-body"></div> </div> </div>


    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // === Element Refs ===
        const fileInput = document.getElementById('file-input');
        const submitBtn = document.getElementById('submit-btn');
        const clearBtn = document.getElementById('clear-btn');
        const form = document.getElementById('image-upload-form');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btn-text');
        const skeletonLoader = document.getElementById('skeleton-loader');
        const uploadArea = document.getElementById('upload-area');
        const resultsContainer = document.getElementById('results-container');
        const resultsGrid = document.getElementById('results-grid');
        const imageModalOverlay = document.getElementById('image-modal-overlay');
        const cardModalOverlay = document.getElementById('card-modal-overlay');
        const cardModalContent = document.getElementById('card-modal-content');
        const cardModalTitle = cardModalContent.querySelector('.modal-title');
        const cardModalBody = cardModalContent.querySelector('.modal-body');
        const closeCardModalBtn = cardModalContent.querySelector('.close-modal-btn');
        const errorContainer = document.getElementById('error-container');
        const previewWrapper = document.getElementById('image-preview-wrapper');
        const resultsPlaceholder = document.getElementById('results-placeholder');
        
        // --- History Refs (NEW) ---
        const historyCard = document.getElementById('history-card');
        const historyList = document.getElementById('history-list');
        const historyPlaceholder = document.getElementById('history-placeholder');
        const clearHistoryBtn = document.getElementById('clear-history-btn');


        // === State ===
        let activeCharts = {}; // Use object to store charts by ID
        let currentMaximizedChartId = null;
        const PRETTY_NAMES = window.__PRETTY_NAMES__ || {}; // Load pretty names

        // === Persisted state (localStorage) ===
        const STORAGE_KEY = 'woa_result_state_v3'; // Single key for last run
        const HISTORY_KEY = 'woa_history_v1'; // NEW: Key for history array
        
        function loadState() { try { const r = localStorage.getItem(STORAGE_KEY); return r ? JSON.parse(r) : null; } catch (e) { return null; } }
        function saveState(p) { try { const pr = loadState() || {}; let n = { ...pr, ...p, savedAt: Date.now() }; let pl = JSON.stringify(n); if (pl.length > 4_500_000) { delete n.previewDataUrl; pl = JSON.stringify(n); } localStorage.setItem(STORAGE_KEY, pl); } catch (e) { console.warn('State save failed:', e); } }
        function clearState() { try { localStorage.removeItem(STORAGE_KEY); } catch (e) {} }

        // --- History Functions (NEW) ---
        function loadHistory() {
            try {
                const h = localStorage.getItem(HISTORY_KEY);
                return h ? JSON.parse(h) : [];
            } catch (e) {
                return [];
            }
        }
        function saveHistory(history) {
            try {
                localStorage.setItem(HISTORY_KEY, JSON.stringify(history));
            } catch (e) {
                console.warn('History save failed:', e);
            }
        }
        function addResultToHistory(payload) {
            if (!payload?.result) return;
            try {
                let history = loadHistory();
                const historyItem = {
                    id: new Date().toISOString() + '_' + Math.random().toString(36).substring(2, 9),
                    savedAt: Date.now(),
                    result: payload.result,
                    imagePath: payload.image,
                    filename: fileInput?.files?.[0]?.name || 'N/A'
                };
                history.unshift(historyItem); // Add to beginning
                if (history.length > 20) history.pop(); // Limit to 20 items
                saveHistory(history);
                renderHistory(); // Update UI
            } catch (e) {
                console.error("Failed to add to history:", e);
            }
        }
        function renderHistory() {
            const history = loadHistory();
            if (history.length === 0) {
                historyList.innerHTML = '';
                historyPlaceholder.style.display = 'block';
                clearHistoryBtn.style.display = 'none';
                return;
            }
            historyPlaceholder.style.display = 'none';
            clearHistoryBtn.style.display = 'inline-flex';
            
            historyList.innerHTML = history.map(item => {
                const pred = item.result?.final_prediction || 'N/A';
                const predClass = pred.toLowerCase().startsWith('mal') ? 'history-item-malignant' : 'history-item-benign';
                const date = new Date(item.savedAt).toLocaleString(undefined, {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                return `
                    <div class="history-item" data-history-id="${escapeHTML(item.id)}">
                        <div class="history-item-left">
                            <span class="history-item-filename">${escapeHTML(item.filename)}</span>
                            <span class="history-item-date">${escapeHTML(date)}</span>
                        </div>
                        <div class="history-item-right">
                            <span class="pill ${predClass}">${escapeHTML(pred)}</span>
                        </div>
                    </div>
                `;
            }).join('');
        }
        // --- End History Functions ---


        // === Get Computed CSS Colors ===
        const computedStyles = getComputedStyle(document.documentElement);
        const chartColors = { 
            // Main colors from CSS variables
            accentGlow: computedStyles.getPropertyValue('--accent-glow').trim(), 
            accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim(), 
            accentSuccess: computedStyles.getPropertyValue('--accent-success').trim() || 'rgba(46, 204, 113, 0.7)', 
            accentWarning: computedStyles.getPropertyValue('--accent-warning').trim() || 'rgba(231, 76, 60, 0.7)', 
            textDark: computedStyles.getPropertyValue('--text-dark').trim(), 
            borderColor: computedStyles.getPropertyValue('--border-color').trim(), 
            bgDark: computedStyles.getPropertyValue('--bg-dark').trim(),
            
            // Pastel colors for charts (from comparison.php)
            pastelBenign: 'rgba(132, 204, 145, 0.7)', // pastel green
            pastelMalignant: 'rgba(252, 165, 165, 0.7)', // pastel red
            
            // Pastel colors for probability chart
            probBenign: 'rgba(144, 238, 144, 0.8)', // light green
            probMalignant: 'rgba(255, 182, 193, 0.8)'  // light pink
        };
        const PASTELS = ['rgba(99, 179, 237, 0.7)','rgba(132, 204, 145, 0.7)','rgba(250, 202, 154, 0.7)','rgba(196, 181, 253, 0.7)','rgba(252, 165, 165, 0.7)','rgba(153, 246, 228, 0.7)'];
        
        // === Utility Functions ===
        function showError(m) { errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${m}</div>`; }
        function renderToCanvas(f) { return new Promise((res, rej) => { const isTiff = f.type === 'image/tiff' || f.name.toLowerCase().endsWith('.tif') || f.name.toLowerCase().endsWith('.tiff'); const rdr = new FileReader(); if (isTiff) { rdr.onload = e => { try { Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 }); const tiff = new Tiff({ buffer: e.target.result }); res(tiff.toCanvas()); } catch (err) { rej(err); } }; rdr.onerror = rej; rdr.readAsArrayBuffer(f); } else { rdr.onload = e => { const img = new Image(); img.onload = () => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); res(c); }; img.onerror = rej; img.src = e.target.result; }; rdr.onerror = rej; rdr.readAsDataURL(f); } }); }
        function scaleCanvasToFit(sC, mW, mH) { const w = sC.width, h = sC.height; const sc = Math.min(mW / w, mH / h, 1); const o = document.createElement('canvas'); o.width = Math.round(w * sc); o.height = Math.round(h * sc); o.getContext('2d').drawImage(sC, 0, 0, o.width, o.height); return o; }
        function displayCanvas(c, cE) { const eC = cE.querySelector('canvas'); if (eC) eC.remove(); cE.prepend(c); cE.style.display = 'flex'; }
        function handleFileSelect(file) {
            if (!file) {
                if (fileInput.files.length > 0) {
                    file = fileInput.files[0];
                } else {
                    return; // No file selected
                }
            }
            const f = file;
            renderToCanvas(f).then(rC => { const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH); previewWrapper.dataset.fullImage = rC.toDataURL(); displayCanvas(sc, previewWrapper); const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = f.name; nE.style.display = 'block'; } submitBtn.disabled = false; clearBtn.style.display = 'inline-flex'; uploadArea.style.display = 'none'; }).catch(err => { console.error(err); showError('Could not read or render image.'); });
        }
        function closeCardModal() {
            cardModalOverlay.classList.remove('visible');
            document.body.style.overflow = '';
            cardModalBody.innerHTML = '';
            // Destroy all modal chart instances
            Object.keys(activeCharts).forEach(key => {
                if (key.startsWith('modal_')) {
                    activeCharts[key].destroy();
                    delete activeCharts[key];
                }
            });
        }
        function escapeHTML(s) { return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }


        // === Modal Display Logic ===
        function showContentInModal(title, contentHtml, cardId = null) {
            cardModalTitle.textContent = title;
            cardModalBody.innerHTML = contentHtml; // Inject the cloned content first
            cardModalOverlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
            
            let chartIdsToRender = [];

            if (cardId) {
                if (cardId === 'tfc-card-content') {
                    chartIdsToRender = ['tfc-malignant-chart', 'tfc-benign-chart'];
                } else if (cardId === 'all-features-tables-card') {
                    chartIdsToRender = []; // No chart, just table
                } else if (cardId === 'all-features-charts-card') {
                    chartIdsToRender = ['all-features-chart'];
                } else if (cardId === 'prediction-card-content') {
                     chartIdsToRender = ['prediction-gauge-chart'];
                } else if (cardId === 'probability-card-content') {
                    chartIdsToRender = ['probability-chart'];
                } else if (cardId === 'abnormality-card-content') {
                    chartIdsToRender = ['abnormality-chart'];
                } else if (cardId === 'background-card-content') {
                     chartIdsToRender = []; // No chart
                }
            }


            requestAnimationFrame(() => { // Ensure DOM is updated
                const resultData = window.__PREDICT__?.result;
                if (!resultData) { console.error("No result data for modal chart"); return; }

                chartIdsToRender.forEach(id => {
                    const canvasInModal = cardModalBody.querySelector(`#${id}`);
                    if (!canvasInModal) { console.error(`Canvas #${id} not found in modal body.`); return; }
                    
                    let container = canvasInModal.closest('.split-chart-wrapper, .abnormality-chart-wrapper, #probability-chart-container, .prediction-visualizer-section, .all-features-chart-wrapper');
                    if (!container) { console.error("Could not find container for chart:", id); return; }

                    // Set height for modal charts
                    if (id === 'prediction-gauge-chart') container.style.height = '250px';
                    else if (id === 'probability-chart') container.style.height = '200px';
                    else if (id === 'abnormality-chart') container.style.height = '400px';
                    else if (id.startsWith('tfc-')) container.style.height = '300px';
                    else if (id === 'all-features-chart') container.style.height = '800px'; // Taller for modal

                    const ctx = canvasInModal.getContext('2d');
                    if (!ctx) { console.error(`Could not get 2D context for modal canvas #${id}.`); return; }

                    if (activeCharts['modal_' + id]) activeCharts['modal_' + id].destroy();
                    
                    try {
                        let newChart;
                        // Find the original chart config from the main page to clone
                        const originalChart = activeCharts[id];
                        if (originalChart) {
                            // Create a new chart instance using the original's config
                            newChart = new Chart(ctx, originalChart.config);
                            activeCharts['modal_' + id] = newChart;
                        } else {
                            console.warn(`Original chart for ${id} not found to clone for modal.`);
                        }
                    } catch (chartError) {
                        console.error(`Error creating chart #${id} in modal:`, chartError);
                    }
                });
            });
        }

        // === Event Listeners ===
        uploadArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => handleFileSelect()); // Simplified
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => { uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false); });
        ['dragenter', 'dragover'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover'), false); });
        ['dragleave', 'drop'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover'), false); });
        uploadArea.addEventListener('drop', e => { fileInput.files = e.dataTransfer.files; handleFileSelect(); });

        form.addEventListener('submit', async e => {
            e.preventDefault(); submitBtn.disabled = true; spinner.style.display = 'block'; btnText.textContent = 'Analyzing...'; skeletonLoader.style.display = 'block'; resultsContainer.style.display = 'none'; resultsPlaceholder.style.display = 'none'; errorContainer.innerHTML = '';
            Object.values(activeCharts).forEach(c => c.destroy()); activeCharts = {}; // Clear all charts
            try {
                const formData = new FormData(form); formData.set('ajax', '1'); formData.delete('mock');
                const response = await fetch(window.location.href, { method: 'POST', body: formData }); const contentType = response.headers.get('content-type') || '';
                if (!response.ok) { const text = await response.text(); throw new Error(`HTTP ${response.status}\n\n${text.slice(0, 2000)}`); }
                if (!contentType.includes('application/json')) { const text = await response.text(); if (text.includes("POST Content-Length")) { throw new Error("File too large."); } throw new Error(`Expected JSON, got HTML/text:\n\n${text.slice(0, 500)}...`); }
                const payload = await response.json(); console.log('AJAX payload:', payload);
                if (payload.ok && payload.result) {
                    window.__PREDICT__ = payload;
                    displayResults(payload.result);
                    saveState({ result: payload.result, imagePath: payload.image || null, filename: fileInput?.files?.[0]?.name || null });
                    addResultToHistory(payload); // NEW: Add to history
                    resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else { throw new Error(payload.error || payload.noise || 'Backend error.'); }
            } catch (err) { console.error('Fetch Error:', err); showError(err?.message?.replace(/\n/g, '<br>') || 'Analysis error.'); }
            finally { skeletonLoader.style.display = 'none'; spinner.style.display = 'none'; btnText.textContent = 'Run Prediction'; submitBtn.disabled = false; }
        });

        clearBtn.addEventListener('click', () => { clearState(); fileInput.value=''; previewWrapper.style.display='none'; previewWrapper.removeAttribute('data-full-image'); const eC=previewWrapper.querySelector('canvas'); if(eC)eC.remove(); const nE=document.getElementById('image-filename'); if(nE)nE.style.display='none'; resultsContainer.style.display='none'; errorContainer.innerHTML=''; skeletonLoader.style.display='none'; resultsPlaceholder.style.display='block'; btnText.textContent='Run Prediction'; submitBtn.disabled=true; clearBtn.style.display='none'; uploadArea.style.display='block'; Object.values(activeCharts).forEach(c=>c.destroy()); activeCharts={}; window.__PREDICT__=null; window.scrollTo({top:0,behavior:'smooth'}); });
        document.body.addEventListener('click', e => { if (e.target.closest('.maximize-btn')) { const dU = document.getElementById('image-preview-wrapper')?.dataset?.fullImage; if (dU) showImageInModal(dU); } });
        function showImageInModal(dU) { const i=new Image(); i.src=dU; i.style.maxWidth='90vw'; i.style.maxHeight='90vh'; i.style.borderRadius='12px'; imageModalOverlay.innerHTML=''; imageModalOverlay.appendChild(i); imageModalOverlay.classList.add('visible'); }
        imageModalOverlay.addEventListener('click', e => { if (e.target === imageModalOverlay) imageModalOverlay.classList.remove('visible'); });
        closeCardModalBtn.addEventListener('click', closeCardModal);
        cardModalOverlay.addEventListener('click', e => { if (e.target === cardModalOverlay) closeCardModal(); });

        // --- History Event Listeners (NEW) ---
        clearHistoryBtn.addEventListener('click', () => {
            saveHistory([]); // Clear storage
            renderHistory(); // Re-render empty state
        });
        
        historyList.addEventListener('click', (e) => {
            const itemEl = e.target.closest('.history-item[data-history-id]');
            if (!itemEl) return;
            
            const id = itemEl.dataset.historyId;
            const history = loadHistory();
            const item = history.find(h => h.id === id);
            
            if (item) {
                console.log("Loading from history:", item);
                // 1. Set global predict data
                window.__PREDICT__ = { ok: true, result: item.result, image: item.imagePath };
                // 2. Display the results
                displayResults(item.result);
                // 3. Display the image
                if (item.imagePath) {
                    const img = new Image();
                    img.onload = () => {
                        const rC = document.createElement('canvas'); rC.width = img.width; rC.height = img.height; rC.getContext('2d').drawImage(img, 0, 0);
                        const dU = rC.toDataURL(); previewWrapper.dataset.fullImage = dU;
                        const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH);
                        displayCanvas(sc, previewWrapper);
                        const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = item.filename || 'image'; nE.style.display = 'block'; }
                        submitBtn.disabled = false;
                        uploadArea.style.display = 'none';
                        clearBtn.style.display = 'inline-flex';
                    };
                    img.onerror = () => { console.warn("Could not load history image:", item.imagePath); };
                    img.src = item.imagePath;
                }
                // 4. Scroll to results
                resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        // --- Sticky Column Logic (NEW) ---
        const toggleStickyBtn = document.getElementById('toggle-sticky-btn');
        const leftColumn = document.querySelector('.left-column');
        const stickyBtnText = document.getElementById('sticky-btn-text');
        const pinIcon = toggleStickyBtn?.querySelector('.pin-icon');
        const unpinIcon = toggleStickyBtn?.querySelector('.unpin-icon');
        const STICKY_KEY = 'woa_sticky_pref';

        function setStickyState(isSticky) {
            if (!leftColumn || !toggleStickyBtn || !stickyBtnText || !pinIcon || !unpinIcon) return;
            
            if (isSticky) {
                leftColumn.classList.add('is-sticky');
                stickyBtnText.textContent = 'Unpin';
                pinIcon.style.display = 'none';
                unpinIcon.style.display = 'inline-block';
                toggleStickyBtn.setAttribute('aria-pressed', 'true');
            } else {
                leftColumn.classList.remove('is-sticky');
                stickyBtnText.textContent = 'Pin Panel';
                pinIcon.style.display = 'inline-block';
                unpinIcon.style.display = 'none';
                toggleStickyBtn.setAttribute('aria-pressed', 'false');
            }
        }

        if (toggleStickyBtn) {
            toggleStickyBtn.addEventListener('click', () => {
                const wantsSticky = !leftColumn.classList.contains('is-sticky');
                setStickyState(wantsSticky);
                try {
                    localStorage.setItem(STICKY_KEY, wantsSticky);
                } catch (e) { console.warn('Could not save sticky pref'); }
            });
        }
        
        // Load sticky pref on init
        try {
            const storedSticky = localStorage.getItem(STICKY_KEY);
            if (storedSticky === 'true') {
                setStickyState(true);
            }
        } catch(e) {}
        // --- End Sticky Column Logic ---
        
        
        // === NEW: Horizontal Bar Chart Renderer (from comparison.php) ===
        // Renders a horizontal bar chart into a canvas
        function renderHorizontalBarChart(canvasId, featuresData, barColor, valueLabel = 'Value') {
            const cv = document.getElementById(canvasId);
            if (!cv) { console.warn(`Canvas not found: ${canvasId}`); return; }
            if (activeCharts[canvasId]) activeCharts[canvasId].destroy();
            
            if (!featuresData || featuresData.length === 0) {
                const ctx = cv.getContext('2d');
                ctx.clearRect(0, 0, cv.width, cv.height);
                ctx.fillStyle = chartColors.textDark;
                ctx.textAlign = 'center';
                ctx.fillText(`No features found.`, cv.width / 2, cv.height / 2);
                cv.parentElement.style.height = '100px'; // collapse if no data
                return;
            }
            
            const labels = featuresData.map(f => PRETTY_NAMES[f[0]] || f[0]);
            const data = featuresData.map(f => f[1]);
            const bgColor = barColor || PASTELS[0];
            const borderColor = bgColor.replace('0.7', '1').replace('0.8', '1');

            // Dynamically set height
            const chartHeight = Math.max(150, featuresData.length * 20); // 20px per bar, min 150px
            cv.parentElement.style.height = `${chartHeight}px`;

            activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: valueLabel,
                        data: data,
                        backgroundColor: bgColor,
                        borderColor: borderColor,
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { color: chartColors.borderColor }, ticks: { color: chartColors.textDark, font: { size: 10 }, callback: v => v.toFixed(2) } },
                        y: { grid: { display: false }, ticks: { color: chartColors.textDark, font: { size: 10 } } }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: (ctx) => ` ${valueLabel}: ${ctx.parsed.x.toFixed(6)}` } }
                    }
                }
            });
        }
        
        // Renders a *single* combined, color-coded chart for all Z-Scores
        function renderAllFeaturesChart(canvasId, zDataSorted) {
            const cv = document.getElementById(canvasId);
            if (!cv) { console.warn(`Canvas not found: ${canvasId}`); return; }
            if (activeCharts[canvasId]) activeCharts[canvasId].destroy();

            if (!zDataSorted || zDataSorted.length === 0) {
                 const ctx = cv.getContext('2d');
                ctx.clearRect(0, 0, cv.width, cv.height);
                ctx.fillStyle = chartColors.textDark;
                ctx.textAlign = 'center';
                ctx.fillText(`No features found.`, cv.width / 2, cv.height / 2);
                cv.parentElement.style.height = '100px'; // collapse if no data
                return;
            }

            const labels = zDataSorted.map(d => d.label);
            const data = zDataSorted.map(d => d.z);
            const colors = zDataSorted.map(d => d.z >= 0 ? chartColors.pastelBenign : chartColors.pastelMalignant);

            const numFeatures = zDataSorted.length;
            const chartHeight = Math.max(400, numFeatures * 18);
            
            const chartWrapper = cv.parentElement;
            if (chartWrapper) chartWrapper.style.height = `${chartHeight}px`;
            
            // Sync table height if it exists
            const tableScroll = document.getElementById('all-features-table-scroll');
            if (tableScroll) tableScroll.style.maxHeight = `${chartHeight}px`;

            activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Z-Score',
                        data: data,
                        backgroundColor: colors,
                        borderWidth: 0,
                        borderRadius: 4
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            position: 'top',
                            ticks: { callback: v => v.toFixed(1) },
                            grid: { color: chartColors.borderColor }
                        },
                        y: {
                            ticks: { font: { size: 9 } }, 
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => `Z-Score: ${ctx.parsed.x.toFixed(3)}`
                            }
                        }
                    }
                }
            });
        }


        // === NEW: Color-coded Table Renderer ===
        function renderColorCodedTable(tbodyId, featuresData, valueType = 'Value') {
            const tableBody = document.getElementById(tbodyId);
            if (!tableBody) { console.warn(`Table body not found: ${tbodyId}`); return; }

            const rows = featuresData.map(([name, value]) => {
                const prettyName = PRETTY_NAMES[name] || name;
                const val = Number(value);
                const colorClass = val < 0 ? 'row-malignant' : 'row-benign';
                const formattedValue = val.toFixed(valueType === 'Z-Score' ? 4 : 6);
                
                return `<tr class="${colorClass}">
                            <td>${escapeHTML(prettyName)} <span class="subtle-name">(${escapeHTML(name)})</span></td>
                            <td class="mono"><strong>${formattedValue}</strong></td>
                        </tr>`;
            }).join('') || `<tr><td colspan="2">No features found.</td></tr>`;
            
            tableBody.innerHTML = rows;
        }


        // === UPDATED displayResults Function ===
        function displayResults(resultData) {
            resultsContainer.style.display='block'; resultsPlaceholder.style.display='none';

            // --- Prediction Card ---
            const predEl = document.querySelector('#prediction-card-content [data-field="final_prediction"]');
            const indEl = document.querySelector('#prediction-card-content .prediction-indicator');
            const pred = resultData.final_prediction || '—';
            const predClass = pred.toLowerCase();
            const predColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess;
            const predBgColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess; // Use same color for gauge

            if (predEl) { predEl.textContent = pred; predEl.style.color = predColor; const pC=predEl.closest('.prediction-card'); if(pC)pC.className=`step-card prediction-card animate-slide-up prediction-${predClass}`; }
            if (indEl) { const bSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentSuccess}"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`; const mSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentWarning}"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`; indEl.innerHTML = pred === 'Malignant' ? mSVG : bSVG; }

            const probs = resultData.probabilities || {};
            const benProb = probs['Benign'] || 0;
            const malProb = probs['Malignant'] || 0;
            const confVal = Math.max(benProb, malProb);

            // --- Prediction Gauge Visualizer ---
            const gaugeCanvas = document.getElementById('prediction-gauge-chart');
            const ringLabelMain = document.querySelector('.final-vis .ring-main');
            if (gaugeCanvas && ringLabelMain) {
                const ctx = gaugeCanvas.getContext('2d');
                if (activeCharts['prediction-gauge-chart']) activeCharts['prediction-gauge-chart'].destroy();

                const gaugeValue = confVal * 100;
                const remainingValue = 100 - gaugeValue;

                activeCharts['prediction-gauge-chart'] = new Chart(ctx, { // Use new ID
                    type: 'doughnut', data: { datasets: [{ data: [gaugeValue, remainingValue], backgroundColor: [ predBgColor, chartColors.bgDark ], borderWidth: 0, borderRadius: 5 }] },
                    options: { responsive: true, maintainAspectRatio: true, cutout: '75%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: { duration: 800, easing: 'easeOutQuart' }, elements: { arc: { roundedCorners: true, } } }
                });
                ringLabelMain.textContent = `${gaugeValue.toFixed(1)}%`; ringLabelMain.style.color = predColor;
            }
            
            // --- Distance Metrics ---
            const dB = Number(resultData.distance_to_benign);
            const dM = Number(resultData.distance_to_malignant);
            const elDB = document.querySelector('[data-field="distance_to_benign"]');
            const elDM = document.querySelector('[data-field="distance_to_malignant"]');
            if (elDB) elDB.textContent = Number.isFinite(dB) ? dB.toFixed(4) : 'N/A';
            if (elDM) elDM.textContent = Number.isFinite(dM) ? dM.toFixed(4) : 'N/A';

            // --- Add distance data to resultData for CSV ---
            const tau = Number(resultData.tau);
            if (Number.isFinite(dB) && dB > 0 && Number.isFinite(dM) && Number.isFinite(tau) && tau > 0) {
                    const r = dM / dB; 
                    const malMargin = tau / r; 
                    const benMargin = r / tau;
                    if (Number.isFinite(r)) resultData.distance_ratio = r.toFixed(6);
                    if (Number.isFinite(malMargin)) { resultData.malignant_margin_x = malMargin.toFixed(6); resultData.malignant_margin_pct = ((malMargin - 1) * 100).toFixed(3) + '%'; }
                    if (Number.isFinite(benMargin)) { resultData.benign_margin_x = benMargin.toFixed(6); resultData.benign_margin_pct = ((benMargin - 1) * 100).toFixed(3) + '%'; }
            }


            // --- Probability Chart ---
            const probCanvas = document.getElementById('probability-chart');
            if (probCanvas) {
                const ctx = probCanvas.getContext('2d');
                if (activeCharts['probability-chart']) activeCharts['probability-chart'].destroy();
                activeCharts['probability-chart'] = new Chart(ctx, {
                    type: 'bar', data: { labels: ['Benign', 'Malignant'], datasets: [{ label: 'Model Probability (%)', data: [benProb * 100, malProb * 100], backgroundColor: [chartColors.probBenign, chartColors.probMalignant], borderWidth: 0, borderRadius: 6 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, scales: { x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' }, grid: { color: chartColors.borderColor } }, y: { grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.parsed.x.toFixed(1)}%` } } }, animation: { duration: 700 } }
                });
                const wrap = document.querySelector('#probability-card-content .card-content'); let noteEl = wrap.querySelector('.explain-note');
                if (!noteEl) { noteEl = document.createElement('div'); noteEl.className = 'explain-note file-meta'; noteEl.style.marginTop = '0.75rem'; noteEl.style.textAlign = 'center'; wrap.appendChild(noteEl); }
                noteEl.textContent = 'Note: These bars show model confidence. The final prediction uses the ratio test (τ).';
            }

            // --- Background Card ---
            const bg = resultData.background_tissue || {};
            const code = bg.code?.toUpperCase() ?? '—';
            const badgeEl = document.querySelector('#background-card-content [data-field="background_tissue_code_badge"]');
            const codeEl = document.querySelector('#background-card-content [data-field="background_tissue_code"]');
            const textEl = document.querySelector('#background-card-content [data-field="background_tissue_text"]');
            const explainEl = document.querySelector('#background-card-content [data-field="background_tissue_explain"]');

            if (badgeEl) {
                badgeEl.textContent = code.slice(0,1) || '?'; // Show first letter or ?
                badgeEl.className = 'birads-badge'; // Reset classes
                if (code.startsWith('A') || code.startsWith('T1')) badgeEl.classList.add('birads-t1');
                else if (code.startsWith('B') || code.startsWith('T2')) badgeEl.classList.add('birads-t2');
                else if (code.startsWith('C') || code.startsWith('T3')) badgeEl.classList.add('birads-t3');
                else if (code.startsWith('D') || code.startsWith('T4')) badgeEl.classList.add('birads-t4');
            }
            if (codeEl) codeEl.textContent = code;
            if (textEl) textEl.textContent = bg.text ?? '—';
            if (explainEl) explainEl.textContent = bg.explain ?? '—';


            // --- Explanations Card (Rendered by JS function) ---
            (function renderExplanations() {
                const root = document.getElementById('explain-root');
                if (!root) { console.error("Could not find #explain-root element."); return; }
                const iconInfo = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 110-16 8 8 0 010 16zM9 9h2v6H9V9zm1-4a1.25 1.25 0 100 2.5A1.25 1.25 0 0010 5z"/></svg>`;
                const iconShield = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2l7 3v5c0 4.418-2.686 7.418-7 8-4.314-.582-7-3.582-7-8V5l7-3z"/></svg>`;
                function decorateMath(s) { if (!s) return ''; return `<span class="math-inline">${s.replace(/<=/g, '≤').replace(/->/g, '→').replace(/\*/g, '·')}</span>`; }
                function metricsToChips(summary) { if (!summary) return ''; const c = []; const re = /([A-Za-z][A-Za-z_ ]+)\s*=\s*([-+]?\d*\.?\d+(?:e[-+]?\d+)?)/gi; let m; while ((m = re.exec(summary)) !== null) c.push(`<span class="metric-chip"><span class="k">${m[1].trim()}</span><span class="v">${Number(m[2]).toFixed(2)}</span></span>`); return c.join(''); }
                function riskBadge(summary) { const s = (summary || '').toLowerCase(); if (s.includes('risk level: high')) return `<span class="badge badge-risk high">${iconShield} High Risk</span>`; if (s.includes('risk level: medium')) return `<span class="badge badge-risk med">${iconShield} Medium Risk</span>`; if (s.includes('risk level: low')) return `<span class="badge badge-risk low">${iconShield} Low Risk</span>`; return ''; }
                function patternBadge(ct, sum) { const b = `${ct||''} ${sum||''}`.toLowerCase(); if (b.includes('→ malignant')||b.includes('malignant pattern')) return `<span class="badge badge-pattern malignant">${iconInfo} Malignant Pattern</span>`; if (b.includes('benign pattern')||b.includes('→ benign')) return `<span class="badge badge-pattern benign">${iconInfo} Benign Pattern</span>`; return ''; }
                
                const classExplanations = (Array.isArray(resultData.explanation?.class) ? resultData.explanation.class : [])
                    .filter(e => !(e || '').includes("Mahalanobis ratio:"))
                    .map(e => `${escapeHTML(e)}`);
                
                const cExp = classExplanations.length > 0 ? classExplanations.join('<br>') : '—';
                const aSumm = resultData.explanation?.abnormality_summary || '—';

                // Find existing elements to preserve distance metrics
                const distanceMetricsEl = root.querySelector('.distance-metrics');
                
                const classHTML = (cExp && cExp !== '—') ? `<div class="explain-section"><div class="explain-body">${decorateMath(cExp || '')}</div></div>` : ''; 
                const metricsHTML = metricsToChips(aSumm || '');
                const badgesHTML = `<div class="badge-row">${patternBadge(cExp, aSumm)}${riskBadge(aSumm)}</div>`;
                const summaryHTML = `<div class="explain-section"><div class="explain-title"><span class="dot"></span>Abnormality Summary</div><div class="explain-body">${metricsHTML||''}${badgesHTML}</div></div>`;
                
                root.innerHTML = classHTML + summaryHTML;
                if (distanceMetricsEl) {
                    root.appendChild(distanceMetricsEl); // Re-append the distance metrics
                }
            })();


            // --- Abnormality Scores Chart ---
            const abnScores = resultData.abnormality_scores || {};
            const abnCtx = document.getElementById('abnormality-chart')?.getContext('2d');
            if (abnCtx) {
                if (activeCharts['abnormality-chart']) activeCharts['abnormality-chart'].destroy();
                const abnVals = Object.values(abnScores); const abnLabels = Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k);
                activeCharts['abnormality-chart'] = new Chart(abnCtx, { type: 'bar', data: { labels: abnLabels, datasets: [{ label: 'Score', data: abnVals, backgroundColor: abnVals.map((_, i) => PASTELS[i % PASTELS.length]), borderWidth: 0, borderRadius: 4 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true }, y: { ticks: { font: { size: 11 } } } }, plugins: { legend: { display: false } } } });
            }

            // --- (NEW) Feature Contributions (TFC) ---
            const tfc = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors : [];
            // Filter and sort for Malignant (negative, strongest first)
            const tfcMalignant = tfc.filter(([, v]) => Number(v) < 0).sort((a, b) => Number(a[1]) - Number(b[1]));
            // Filter and sort for Benign (positive, strongest first)
            const tfcBenign = tfc.filter(([, v]) => Number(v) > 0).sort((a, b) => Number(b[1]) - Number(a[1]));

            // Render TFC Tables
            renderColorCodedTable('tfc-malignant-body', tfcMalignant, 'Contribution');
            renderColorCodedTable('tfc-benign-body', tfcBenign, 'Contribution');

            // Render TFC Charts (reverse() for horizontal bar chart display)
            renderHorizontalBarChart('tfc-malignant-chart', tfcMalignant.map(f => [f[0], f[1]]).reverse(), chartColors.pastelMalignant, 'Contribution');
            renderHorizontalBarChart('tfc-benign-chart', tfcBenign.map(f => [f[0], f[1]]).reverse(), chartColors.pastelBenign, 'Contribution');
            

            // --- (NEW) All Detected Features (from Z-Scores) ---
            const zs = resultData.zscores || {};
            const zData = Object.keys(zs).map(k => {
                const z = Number(zs[k]);
                return {
                    key: k,
                    label: PRETTY_NAMES[k] || k,
                    z: (Number.isFinite(z) ? z : 0)
                };
            });
            
            // Sort by Z-Score (Benign to Malignant) for both table and chart
            const zDataSorted = zData.sort((a, b) => b.z - a.z);
            
            // --- All Detected Features Table ---
            {
                const ztb = document.getElementById('all-features-body'); // Use new ID
                if (ztb) {
                    // Use renderColorCodedTable for consistency
                    renderColorCodedTable('all-features-body', zDataSorted.map(d => [d.key, d.z]), 'Z-Score');
                }
            }
            
            // --- All Detected Features Chart ---
            // (Use the dedicated all-features chart renderer)
            renderAllFeaturesChart('all-features-chart', zDataSorted);
            

            // --- Re-attach Maximize Button Listeners ---
            resultsGrid.querySelectorAll('.maximize-card-btn').forEach(b=>{const nb=b.cloneNode(true); b.parentNode.replaceChild(nb,b); nb.addEventListener('click',e=>{
                const c=e.target.closest('.step-card[id]');
                if(c?.id){
                    const t=c.querySelector('h2')?.textContent.trim()||'Details'; 
                    const ce=c.querySelector('.card-content'); 
                    if(ce){
                        const cl=ce.cloneNode(true);
                        // Pass the *card's* ID to the modal function
                        showContentInModal(t, cl.innerHTML, c.id);
                    }
                }
            });});

            // --- Re-attach Print/CSV Listeners ---
            const printBtn = document.getElementById('print-btn'); if (printBtn) { const nPB = printBtn.cloneNode(true); printBtn.parentNode.replaceChild(nPB, printBtn); nPB.addEventListener('click', () => window.print()); }
            const csvBtn = document.getElementById('csv-btn'); if (csvBtn) { const nCB = csvBtn.cloneNode(true); csvBtn.parentNode.replaceChild(nCB, csvBtn); nCB.addEventListener('click', () => openCSVPreview(resultData)); }

        } // End displayResults

        // === CSV Functions (Updated with new fields) ===
        function downloadCSV(rd) {
             let c = "Category,Parameter,Value\r\n";
             const e = (s) => { if (s==null) return ''; let r=String(s); if (r.includes(',')||r.includes('"')||r.includes('\n')) r='"'+r.replace(/"/g,'""')+'"'; return r; };
             c+=`Prediction,final_prediction,${e(rd.final_prediction)}\r\n`;
             if(rd.probabilities) Object.entries(rd.probabilities).forEach(([k,v])=>c+=`Probability,${e(k)},${e(v)}\r\n`);
             c+=`Decision,distance_to_benign,${e(rd.distance_to_benign)}\r\n`;
             c+=`Decision,distance_to_malignant,${e(rd.distance_to_malignant)}\r\n`;
             c+=`Decision,tau,${e(rd.tau)}\r\n`;
             c+=`Decision,ratio_decision_rule,${e(rd.ratio_decision)}\r\n`;
             c+=`Decision,distance_ratio,${e(rd.distance_ratio ?? '')}\r\n`;
             c+=`Decision,malignant_margin_x,${e(rd.malignant_margin_x ?? '')}\r\n`;
             c+=`Decision,malignant_margin_pct,${e(rd.malignant_margin_pct ?? '')}\r\n`;
             c+=`Decision,benign_margin_x,${e(rd.benign_margin_x ?? '')}\r\n`;
             c+=`Decision,benign_margin_pct,${e(rd.benign_margin_pct ?? '')}\r\n`;
             c+=`Decision,decision_verdict,${e(rd.decision_verdict ?? '')}\r\n`;
             c+=`Abnormality,type,${e(rd.abnormality_type)}\r\n`;
             if(rd.abnormality_scores) Object.entries(rd.abnormality_scores).forEach(([k,v])=>c+=`Abnormality Score,${e(PRETTY_NAMES[k] || k)},${e(v)}\r\n`);
             if(rd.background_tissue){ c+=`Background,code,${e(rd.background_tissue.code)}\r\n`; c+=`Background,text,${e(rd.background_tissue.text)}\r\n`; c+=`Background,explanation,${e(rd.background_tissue.explain)}\r\n`; }
             if(rd.explanation?.class) c+=`Explanation,class,${e(Array.isArray(rd.explanation.class) ? rd.explanation.class.join('; ') : rd.explanation.class)}\r\n`;
             if(rd.explanation?.abnormality_summary) c+=`Explanation,abnormality_summary,${e(rd.explanation.abnormality_summary)}\r\n`;
             // ADDED TFC to CSV
             if(rd.top_feature_contributors) rd.top_feature_contributors.forEach(([n,v])=>c+=`Feature Contribution,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`);
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => c+=`Z-Score,${e(PRETTY_NAMES[k] || k)},${e(rd.zscores[k])}\r\n`);

             const b=new Blob([c],{type:'text/csv;charset=utf-8;'}); const l=document.createElement("a");
             if(l.download!==undefined){ const u=URL.createObjectURL(b); const t=new Date().toISOString().replace(/:/g,'-').slice(0,19); l.setAttribute("href",u); l.setAttribute("download",`prediction_results_${t}.csv`); l.style.visibility='hidden'; document.body.appendChild(l); l.click(); document.body.removeChild(l); }
           }
        function buildCSVPreviewHTML(rd, l=35) {
             const rs=[];
             rs.push(["Prediction","final_prediction",String(rd.final_prediction??"—")]);
             if(rd.probabilities)Object.entries(rd.probabilities).forEach(([k,v])=>rs.push(["Probability",k,String(v??"—")]));
             rs.push(["Decision","distance_to_benign",String(rd.distance_to_benign??"—")]);
             rs.push(["Decision","distance_to_malignant",String(rd.distance_to_malignant??"—")]);
             rs.push(["Decision","tau",String(rd.tau??"—")]);
             rs.push(["Decision","ratio_decision_rule",String(rd.ratio_decision??"—")]);
             rs.push(["Decision","distance_ratio",String(rd.distance_ratio ?? "—")]);
             rs.push(["Decision","malignant_margin_x",String(rd.malignant_margin_x ?? "—")]);
             rs.push(["Decision","malignant_margin_pct",String(rd.malignant_margin_pct ?? "—")]);
             rs.push(["Decision","benign_margin_x",String(rd.benign_margin_x ?? "—")]);
             rs.push(["Decision","benign_margin_pct",String(rd.benign_margin_pct ?? "—")]);
             rs.push(["Decision","decision_verdict",String(rd.decision_verdict ?? "—")]);
             rs.push(["Abnormality","type",String(rd.abnormality_type??"—")]);
             if(rd.abnormality_scores)Object.entries(rd.abnormality_scores).forEach(([k,v])=>rs.push(["Abnormality Score", PRETTY_NAMES[k] || k ,String(v??"—")]));
             if(rd.background_tissue){rs.push(["Background","code",String(rd.background_tissue.code??"—")]); rs.push(["Background","text",String(rd.background_tissue.text??"—")]); rs.push(["Background","explanation",String(rd.background_tissue.explain??"—")]);}
             if(rd.explanation?.class) rs.push(["Explanation","class",String(Array.isArray(rd.explanation.class) ? rd.explanation.class.join("; ") : rd.explanation.class)]);
             if(rd.explanation?.abnormality_summary)rs.push(["Explanation","abnormality_summary",String(rd.explanation.abnormality_summary)]);
             // ADDED TFC to CSV Preview
             if(Array.isArray(rd.top_feature_contributors))rd.top_feature_contributors.forEach(([n,v])=>rs.push(["Feature Contribution", PRETTY_NAMES[n] || n ,String(v??"—")]));
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => rs.push(["Z-Score", PRETTY_NAMES[k] || k, String(rd.zscores[k] ?? "—")]));

             const lim=rs.slice(0,l); let t='<div class="table-wrapper-scroll"><table class="data-table"><thead><tr><th>Category</th><th>Parameter</th><th>Value</th></tr></thead><tbody>'; lim.forEach(r=>t+=`<tr><td>${escapeHTML(r[0])}</td><td>${escapeHTML(r[1])}</td><td>${escapeHTML(r[2])}</td></tr>`); t+='</tbody></table></div>'; t+=`<div class="modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;"><button type="button" class="btn" id="csv-download-confirm">Download All ${rs.length} Rows</button><button type="button" class="btn btn-secondary" id="csv-preview-close">Close</button></div><p class="file-meta" style="margin-top:.5rem; text-align:right;">Showing first ${lim.length} of ${rs.length} rows.</p>`; return t;
           }
        function openCSVPreview(rd) { const h=buildCSVPreviewHTML(rd); showContentInModal('CSV Preview', h); const dl=document.getElementById('csv-download-confirm'); const cl=document.getElementById('csv-preview-close'); if(dl)dl.addEventListener('click',()=>{downloadCSV(rd); closeCardModal();}); if(cl)cl.addEventListener('click',closeCardModal); }


        // --- Initialization ---
        renderHistory(); // NEW: Render history on load
        const initialPredictData = window.__PREDICT__;
        const stored = loadState();
        let initialImageSrc = null;

        // Determine initial state (PHP load > LocalStorage > Default)
        if (initialPredictData?.result) { // Result from PHP
            resultsPlaceholder.style.display = 'none';
            displayResults(initialPredictData.result);
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = window.__UPLOADED_IMAGE__;
        } else if (stored?.result) { // Result from localStorage
            resultsPlaceholder.style.display = 'none';
            displayResults(stored.result);
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = stored.imagePath || stored.previewDataUrl;
             window.__PREDICT__ = { ok: true, result: stored.result, image: stored.imagePath };
        } else { // Default initial state
            resultsPlaceholder.style.display = 'block';
            resultsContainer.style.display = 'none';
            skeletonLoader.style.display = 'none';
        }

        // Display initial image if available
        if (initialImageSrc) {
            const img = new Image();
            img.onload = () => {
                const rC = document.createElement('canvas'); rC.width = img.width; rC.height = img.height; rC.getContext('2d').drawImage(img, 0, 0);
                const dU = rC.toDataURL(); previewWrapper.dataset.fullImage = dU;
                const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH);
                displayCanvas(sc, previewWrapper);
                const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = (initialPredictData ? window.__UPLOADED_IMAGE__?.split('/').pop() : stored?.filename) || 'image'; nE.style.display = 'block'; }
                submitBtn.disabled = false;
                uploadArea.style.display = 'none';
            };
            img.onerror = () => { console.warn("Could not load initial image:", initialImageSrc); clearState(); };
            img.src = initialImageSrc;
        }

    });
    </script>

</body>
</html>
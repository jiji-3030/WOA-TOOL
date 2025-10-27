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
    'ok'    => (bool) $result && !$error,
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
    <link rel="stylesheet" href="style.css?v=23"> <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
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
             <div class="quick-guide"> <h3> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg> Quick Start Guide </h3> <ul> <li><strong>Step 1:</strong> Upload mammogram (<code>.png</code>, <code>.jpg</code>, <code>.tif</code>).</li> <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong>.</li> <li><strong>Step 3:</strong> View results.</li> </ul> </div>
        </header>

        <div class="left-column">
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

                        <div class="step-card prediction-card animate-slide-up" id="prediction-card-content">
                            <div class="step-header">
                                <div class="step-header-left"> <h2 style="padding-left:0;">Final Prediction</h2> </div>
                                <button type="button" class="maximize-card-btn" title="Maximize"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button>
                            </div>
                            <div class="card-content">
                                <div class="prediction-left">
                                    <div class="prediction-text-wrapper">
                                        <span class="prediction-indicator"></span>
                                        <span style="font-size:2.5rem; font-weight:800;" data-field="final_prediction">—</span>
                                    </div>
                                    <div class="confidence-bar-wrapper">
                                        <div class="confidence-bar-bg"> <div id="confidence-fill"></div> </div>
                                        <p id="confidence-label">Top probability: —</p> </div>
                                    <div class="decision-details" style="margin-top:0.75rem;">
                                        <table class="data-table" style="margin-top:.25rem;">
                                            <tr> <th>d<sub>B</sub> <span class="tooltip-icon">?<span class="tooltip-content">Mahalanobis distance to Benign centroid.</span></span></th> <td data-field="distance_to_benign">—</td> </tr>
                                            <tr> <th>d<sub>M</sub> <span class="tooltip-icon">?<span class="tooltip-content">Mahalanobis distance to Malignant centroid.</span></span></th> <td data-field="distance_to_malignant">—</td> </tr>
                                            <tr> <th>τ <span class="tooltip-icon">?<span class="tooltip-content">Decision threshold ratio.</span></span></th> <td data-field="tau">—</td> </tr>
                                            <tr> <th>Rule <span class="tooltip-icon">?<span class="tooltip-content">The rule used for the final decision.</span></span></th> <td data-field="ratio_decision">—</td> </tr>
                                        </table>
                                        <p class="file-meta" id="decision-note" style="margin-top:.5rem; text-align: right;"> {/* JS fills this */} </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-card animate-slide-up" id="probability-card-content" style="animation-delay:.1s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Probabilities</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Model probability per class.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content"> <div id="probability-chart-container"><canvas id="probability-chart"></canvas></div> </div>
                        </div>

                        <div class="step-card animate-slide-up" id="background-card-content" style="animation-delay:.15s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Background Tissue</h2> </div> <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <tr> <th>BI-RADS Code <span class="tooltip-icon">?<span class="tooltip-content">Inferred BI-RADS density category.</span></span></th> <td data-field="background_tissue_code"></td> </tr>
                                    <tr> <th>Description</th> <td data-field="background_tissue_text"></td> </tr>
                                    <tr> <th>Inference Detail</th> <td data-field="background_tissue_explain"></td> </tr>
                                </table>
                            </div>
                        </div>

                        <div class="step-card animate-slide-up" id="explanation-card-content" style="animation-delay:.20s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Explanations</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">AI-generated explanations for the prediction.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <tr> <th>Class-based <span class="tooltip-icon">?<span class="tooltip-content">Reasoning based on the final class assignment.</span></span></th> <td data-field="explanation_class"></td> </tr>
                                    <tr> <th>Abnormality Summary <span class="tooltip-icon">?<span class="tooltip-content">Summary description of detected abnormality characteristics.</span></span></th> <td data-field="explanation_abnormality_summary"></td> </tr>
                                </table>
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

                        <div class="step-card animate-slide-up" id="top-features-card-content" style="animation-delay:.30s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Top Feature Contributors</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Features most influencing the Benign/Malignant decision (raw value shown).</span></span> <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead><tr><th>Feature</th><th>Contribution</th></tr></thead> <tbody data-field="top_feature_contributors">   </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="step-card animate-slide-up" id="contrib-stacked-card" style="animation-delay:.45s;">
                            <div class="step-header">
                                <div class="step-header-left"> <h2>Relative Contributions (Top 5)</h2> </div> <span class="tooltip-icon">i<span class="tooltip-content">Relative contribution (%) of the top features shown above.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 0 1.5 1.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button>
                            </div>
                            <div class="card-content"> <div style="height:220px;"> <canvas id="contrib-stacked"></canvas> </div> </div>
                        </div>

                        <div class="step-card animate-slide-up" id="zscores-card-content" style="animation-delay:.50s;">
                             <div class="step-header">
                                <div class="step-header-left"> <h2>Feature Z-Scores</h2> </div>
                                <span class="tooltip-icon">i<span class="tooltip-content">Standardized values (z-scores) for all calculated radiomic features.</span></span>
                                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                            </div>
                             <div class="card-content">
                                <div class="table-wrapper-scroll" style="max-height: 300px;"> <table class="data-table">
                                        <thead><tr><th>Feature</th><th>Z-Score</th></tr></thead>
                                        <tbody data-field="zscores">   </tbody>
                                    </table>
                                </div>
                             </div>
                        </div>

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

        // === State ===
        let activeCharts = {}; // Use object to store charts by ID
        let currentMaximizedChartId = null;
        const PRETTY_NAMES = window.__PRETTY_NAMES__ || {}; // Load pretty names

        // === Persisted state (localStorage) ===
        const STORAGE_KEY = 'woa_result_state_v3'; // Incremented version
        function loadState() { try { const r = localStorage.getItem(STORAGE_KEY); return r ? JSON.parse(r) : null; } catch (e) { return null; } }
        function saveState(p) { try { const pr = loadState() || {}; let n = { ...pr, ...p, savedAt: Date.now() }; let pl = JSON.stringify(n); if (pl.length > 4_500_000) { delete n.previewDataUrl; pl = JSON.stringify(n); } localStorage.setItem(STORAGE_KEY, pl); } catch (e) { console.warn('State save failed:', e); } }
        function clearState() { try { localStorage.removeItem(STORAGE_KEY); } catch (e) {} }

        // === Get Computed CSS Colors ===
        const computedStyles = getComputedStyle(document.documentElement);
        const chartColors = { accentGlow: computedStyles.getPropertyValue('--accent-glow').trim(), accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim(), accentSuccess: computedStyles.getPropertyValue('--accent-success').trim(), accentWarning: computedStyles.getPropertyValue('--accent-warning').trim(), textDark: computedStyles.getPropertyValue('--text-dark').trim(), borderColor: computedStyles.getPropertyValue('--border-color').trim(), };
        const PASTELS = ['rgba(99, 179, 237, 0.7)','rgba(132, 204, 145, 0.7)','rgba(250, 202, 154, 0.7)','rgba(196, 181, 253, 0.7)','rgba(252, 165, 165, 0.7)','rgba(153, 246, 228, 0.7)'];

        // === Utility Functions ===
        function showError(m) { errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${m}</div>`; }
        function renderToCanvas(f) { return new Promise((res, rej) => { const isTiff = f.type === 'image/tiff' || f.name.toLowerCase().endsWith('.tif') || f.name.toLowerCase().endsWith('.tiff'); const rdr = new FileReader(); if (isTiff) { rdr.onload = e => { try { Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 }); const tiff = new Tiff({ buffer: e.target.result }); res(tiff.toCanvas()); } catch (err) { rej(err); } }; rdr.onerror = rej; rdr.readAsArrayBuffer(f); } else { rdr.onload = e => { const img = new Image(); img.onload = () => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; c.getContext('2d').drawImage(img, 0, 0); res(c); }; img.onerror = rej; img.src = e.target.result; }; rdr.onerror = rej; rdr.readAsDataURL(f); } }); }
        function scaleCanvasToFit(sC, mW, mH) { const w = sC.width, h = sC.height; const sc = Math.min(mW / w, mH / h, 1); const o = document.createElement('canvas'); o.width = Math.round(w * sc); o.height = Math.round(h * sc); o.getContext('2d').drawImage(sC, 0, 0, o.width, o.height); return o; }
        function displayCanvas(c, cE) { const eC = cE.querySelector('canvas'); if (eC) eC.remove(); cE.prepend(c); cE.style.display = 'flex'; }
        function handleFileSelect() { if (fileInput.files.length > 0) { const f = fileInput.files[0]; renderToCanvas(f).then(rC => { const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH); previewWrapper.dataset.fullImage = rC.toDataURL(); displayCanvas(sc, previewWrapper); const nE = document.getElementById('image-filename'); if (nE) { nE.textContent = f.name; nE.style.display = 'block'; } submitBtn.disabled = false; clearBtn.style.display = 'inline-flex'; uploadArea.style.display = 'none'; }).catch(err => { console.error(err); showError('Could not read or render image.'); }); } }
        function closeCardModal() { cardModalOverlay.classList.remove('visible'); document.body.style.overflow = ''; cardModalBody.innerHTML = ''; currentMaximizedChartId = null; /* Destroy modal chart instance if exists */ }
        function escapeHTML(s) { return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }

        // === Modal Display Logic ===
        function showContentInModal(title, contentHtml, chartId = null) {
            cardModalTitle.textContent = title;
            cardModalBody.innerHTML = contentHtml; // Inject the cloned content first
            cardModalOverlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
            currentMaximizedChartId = chartId;

            requestAnimationFrame(() => { // Ensure DOM is updated
                if (chartId) {
                    const resultData = window.__PREDICT__?.result;
                    if (!resultData) { console.error("No result data for modal chart:", chartId); return; }

                    const canvasInModal = cardModalBody.querySelector(`#${chartId}`); // Find canvas INSIDE modal
                    if (!canvasInModal) { console.error(`Canvas #${chartId} not found in modal body.`); return; }

                    let container = canvasInModal.closest('.card-content > div') || canvasInModal.parentElement;
                    if (container && !container.style.height) { // Ensure container has height
                        if (chartId === 'probability-chart') container.style.height = '400px';
                        else if (chartId === 'abnormality-chart') container.style.height = '500px';
                        else if (chartId === 'contrib-stacked') container.style.height = '400px';
                        else container.style.height = '400px';
                    }

                    const ctx = canvasInModal.getContext('2d');
                    if (!ctx) { console.error(`Could not get 2D context for modal canvas #${chartId}.`); return; }

                    // Destroy old modal chart instance before creating new one
                    if (activeCharts['modal_' + chartId]) {
                        activeCharts['modal_' + chartId].destroy();
                    }

                    try {
                        let newChart;
                        if (chartId === 'probability-chart') {
                            const probs = resultData.probabilities || {}; const ben = probs['Benign'] ?? 0; const mal = probs['Malignant'] ?? 0;
                            newChart = new Chart(ctx, { type: 'doughnut', data: { labels: ['Benign', 'Malignant'], datasets: [{ data: [ben, mal], backgroundColor: [chartColors.accentSuccess, chartColors.accentWarning], borderColor: '#fff', borderWidth: 3, cutout: '60%', hoverOffset: 8 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels:{ font:{ size: 14 } } } } } });
                        } else if (chartId === 'abnormality-chart') {
                            const abnScores = resultData.abnormality_scores || {}; const abnValues = Object.values(abnScores);
                            newChart = new Chart(ctx, { type: 'bar', data: { labels: Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k), datasets: [{ label: 'Score', data: abnValues, backgroundColor: abnValues.map((_, i) => PASTELS[i % PASTELS.length]), borderWidth: 0, borderRadius: 5 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true }, y: { ticks: { font: {size: 14} } } }, plugins: { legend: { display: false } } } });
                        } else if (chartId === 'contrib-stacked') {
                            const tfc = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors : []; const total = tfc.reduce((s, x) => s + (x?.[1] || 0), 0) || 1; const pct = tfc.map(([label, v]) => [(PRETTY_NAMES[label] || label), (v / total) * 100]);
                            const datasets = pct.map(([label, value], i) => ({ label, data: [value], backgroundColor: PASTELS[i % PASTELS.length], borderWidth: 0 }));
                            newChart = new Chart(ctx, { type: 'bar', data: { labels: ['Contribution'], datasets }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true, min: 0, max: 100, ticks: { callback: v => v + '%' } }, y: { stacked: true } }, plugins: { legend: { position: 'right', labels:{ font:{ size: 12 } } } } } });
                        }
                        if(newChart) {
                            activeCharts['modal_' + chartId] = newChart; // Store modal chart instance
                        }
                    } catch (chartError) {
                        console.error(`Error creating chart #${chartId} in modal:`, chartError);
                    }
                }
            });
        }

        // === Event Listeners ===
        uploadArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', handleFileSelect);
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

        // === UPDATED displayResults Function ===
        function displayResults(resultData) {
            resultsContainer.style.display='block'; resultsPlaceholder.style.display='none';

            // --- Prediction Card ---
            const predEl = document.querySelector('#prediction-card-content [data-field="final_prediction"]');
            const indEl = document.querySelector('#prediction-card-content .prediction-indicator');
            const pred = resultData.final_prediction || '—';
            const predClass = pred.toLowerCase();
            const predColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess;

            if (predEl) { predEl.textContent = pred; predEl.style.color = predColor; const pC=predEl.closest('.prediction-card'); if(pC)pC.className=`step-card prediction-card animate-slide-up prediction-${predClass}`; }
            if (indEl) { const bSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentSuccess}"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`; const mSVG=`<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentWarning}"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`; indEl.innerHTML = pred === 'Malignant' ? mSVG : bSVG; }

            const probs = resultData.probabilities || {};
            const benProb = probs['Benign'] || 0;
            const malProb = probs['Malignant'] || 0;
            const confVal = Math.max(benProb, malProb);
            const probWinner = (benProb >= malProb) ? 'Benign' : 'Malignant';
            const confFill = document.getElementById('confidence-fill');
            const confLabel = document.getElementById('confidence-label');
            if (confFill) { confFill.style.width = (confVal * 100) + '%'; confFill.style.backgroundColor = (probWinner === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess); } // Color by probability winner
            if (confLabel) { confLabel.textContent = `Top probability: ${(confVal * 100).toFixed(1)}% (${probWinner})`; }

            // Distances, Tau, Rule
            const dB = Number(resultData.distance_to_benign);
            const dM = Number(resultData.distance_to_malignant);
            const tau = Number(resultData.tau);
            const ruleText = resultData.ratio_decision || '—';
            document.querySelector('[data-field="distance_to_benign"]').textContent = Number.isFinite(dB) ? dB.toFixed(4) : '—';
            document.querySelector('[data-field="distance_to_malignant"]').textContent = Number.isFinite(dM) ? dM.toFixed(4) : '—';
            document.querySelector('[data-field="tau"]').textContent = Number.isFinite(tau) ? tau.toFixed(4) : '—';
            document.querySelector('[data-field="ratio_decision"]').textContent = ruleText;

            let ratioSaysMalignant = null;
            if (Number.isFinite(dB) && Number.isFinite(dM) && Number.isFinite(tau) && dB > 0) { // Check dB > 0
                ratioSaysMalignant = (dM <= tau * dB);
            }

            const decisionNote = document.getElementById('decision-note');
            const probabilitiesDisagree = (probWinner !== pred);
            if (decisionNote) {
                let parts = [];
                 if (ratioSaysMalignant !== null) {
                    const lhs = dM.toFixed(4);
                    const rhs = (tau * dB).toFixed(4);
                    const inequality = ratioSaysMalignant ? '≤' : '>';
                    parts.push(`Ratio Test: ${lhs} ${inequality} ${rhs}`);
                 } else {
                     parts.push("Ratio Test: N/A");
                 }
                // parts.push(`Rule: ${ruleText}`); // Rule text from backend might be enough
                if (probabilitiesDisagree) { parts.push('Note: Probabilities disagree; ratio rule used.'); }
                decisionNote.textContent = parts.join(' • ');
            }

            // --- Probability Chart ---
            const probCanvas = document.getElementById('probability-chart');
            if (probCanvas) {
                const ctx = probCanvas.getContext('2d');
                activeCharts['probability-chart'] = new Chart(ctx, { type: 'doughnut', data: { labels:['Benign','Malignant'], datasets: [{ data: [benProb, malProb], backgroundColor: [chartColors.accentSuccess, chartColors.accentWarning], borderColor: '#fff', borderWidth: 2, cutout: '60%', hoverOffset: 6 }] }, options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } }, animation: { duration: 700 } } });
                // Add legend dynamically
                (function mPL(){ const w=document.querySelector('#probability-card-content .card-content'); if(!w||w.querySelector('.pie-legend'))return; const l=document.createElement('div'); l.className='pie-legend'; const bP=(benProb*100).toFixed(1)+'%'; const mP=(malProb*100).toFixed(1)+'%'; l.innerHTML=`<span class="chip"><span class="dot" style="background:${chartColors.accentSuccess}"></span>Benign: ${bP}</span><span class="chip"><span class="dot" style="background:${chartColors.accentWarning}"></span>Malignant: ${mP}</span>`; w.appendChild(l); })();
            }

            // --- Background Card ---
            const bg = resultData.background_tissue || {};
            document.querySelector('#background-card-content [data-field="background_tissue_code"]').textContent = bg.code ?? '—';
            document.querySelector('#background-card-content [data-field="background_tissue_text"]').textContent = bg.text ?? '—';
            document.querySelector('#background-card-content [data-field="background_tissue_explain"]').textContent = bg.explain ?? '—';

            // --- Explanations Card ---
            const cExp = (Array.isArray(resultData.explanation?.class) && resultData.explanation.class.length > 0) ? resultData.explanation.class.map(e => `${escapeHTML(e)}`).join('<br>') : '—';
            document.querySelector('#explanation-card-content [data-field="explanation_class"]').innerHTML = cExp;
            const aSumm = resultData.explanation?.abnormality_summary || '—';
            document.querySelector('#explanation-card-content [data-field="explanation_abnormality_summary"]').textContent = aSumm;


            // --- Abnormality Scores Chart ---
            const abnScores = resultData.abnormality_scores || {};
            const abnCtx = document.getElementById('abnormality-chart')?.getContext('2d');
            // const abnTypeEl = document.querySelector('#abnormality-card-content [data-field="abnormality_type"]'); // Removed, type is separate
            // if (abnTypeEl) abnTypeEl.textContent = resultData.abnormality_type || '—'; // Show type if available

            if (abnCtx) {
                const abnVals = Object.values(abnScores);
                const abnLabels = Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k); // Use pretty names for labels
                activeCharts['abnormality-chart'] = new Chart(abnCtx, { type: 'bar', data: { labels: abnLabels, datasets: [{ label: 'Score', data: abnVals, backgroundColor: abnVals.map((_, i) => PASTELS[i % PASTELS.length]), borderWidth: 0, borderRadius: 4 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true }, y: { ticks: { font: { size: 11 } } } }, plugins: { legend: { display: false } } } });
            }

            // --- Top Contributors Table ---
            const topF = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors.map(([name, val]) =>
                `<tr><td>${escapeHTML(PRETTY_NAMES[name] || name)}</td><td class="mono"><strong>${escapeHTML(Number(val).toFixed(4))}</strong></td></tr>`
            ).join('') : '<tr><td colspan="2">No data</td></tr>';
            const topFEl = document.querySelector('#top-features-card-content [data-field="top_feature_contributors"]');
            if (topFEl) topFEl.innerHTML = topF;

            // --- Stacked Contributions Chart ---
            (function() {
                const canvas = document.getElementById('contrib-stacked');
                if (!canvas) return;
                const tfc = Array.isArray(resultData.top_feature_contributors) ? resultData.top_feature_contributors : [];
                const total = tfc.reduce((s, x) => s + (x?.[1] || 0), 0) || 1;
                const pct = tfc.map(([label, v]) => [(PRETTY_NAMES[label] || label), (v / total) * 100]);
                const datasets = pct.map(([label, value], i) => ({ label, data:[value], backgroundColor: PASTELS[i % PASTELS.length], borderWidth: 0 }));
                activeCharts['contrib-stacked'] = new Chart(canvas.getContext('2d'), { type: 'bar', data: { labels: ['Contribution'], datasets }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true, min: 0, max: 100, ticks: { callback: v => v + '%' } }, y: { stacked: true } }, plugins: { legend: { position: 'bottom', labels: { boxWidth:15, font:{size:10} } } } } });
            })();

            // --- Z-Scores Table ---
            const zscores = resultData.zscores || {};
            const zscoreTbody = document.querySelector('#zscores-card-content [data-field="zscores"]');
            if (zscoreTbody) {
                let zscoreHtml = '';
                // Sort keys alphabetically for consistent order
                Object.keys(zscores).sort().forEach(key => {
                    const prettyName = PRETTY_NAMES[key] || key;
                    const value = Number.isFinite(zscores[key]) ? zscores[key].toFixed(4) : 'N/A';
                    zscoreHtml += `<tr><td>${escapeHTML(prettyName)}</td><td class="mono">${escapeHTML(value)}</td></tr>`;
                });
                zscoreTbody.innerHTML = zscoreHtml || '<tr><td colspan="2">No Z-Score data</td></tr>';
            }


            // --- Re-attach maximize button listeners ---
            resultsGrid.querySelectorAll('.maximize-card-btn').forEach(button => {
                 // Clone to remove old listeners, then re-add
                const newButton = button.cloneNode(true);
                button.parentNode.replaceChild(newButton, button);
                newButton.addEventListener('click', (e) => {
                    const card = e.target.closest('.step-card[id]');
                    if (card && card.id) {
                        const cardTitle = card.querySelector('h2')?.textContent.trim() || 'Details';
                        const cardContentElement = card.querySelector('.card-content');
                        if (cardContentElement) {
                            const cardContentClone = cardContentElement.cloneNode(true);
                            let chartIdInCard = null;
                            const canvas = card.querySelector('canvas');
                            if (canvas) chartIdInCard = canvas.id; // Get ID if canvas exists

                            // For z-scores, just clone the content, no chart re-render
                            if(card.id === 'zscores-card-content') chartIdInCard = null;

                            showContentInModal(cardTitle, cardContentClone.innerHTML, chartIdInCard);
                        }
                    }
                });
            });

            // Re-attach print/csv listeners
            const printBtn = document.getElementById('print-btn'); if (printBtn) { const nPB = printBtn.cloneNode(true); printBtn.parentNode.replaceChild(nPB, printBtn); nPB.addEventListener('click', () => window.print()); }
            const csvBtn = document.getElementById('csv-btn'); if (csvBtn) { const nCB = csvBtn.cloneNode(true); csvBtn.parentNode.replaceChild(nCB, csvBtn); nCB.addEventListener('click', () => openCSVPreview(resultData)); } // Pass updated function

        } // End displayResults

        // === CSV Functions (UPDATED) ===
        function downloadCSV(rd) {
             let c = "Category,Parameter,Value\r\n";
             const e = (s) => { if (s==null) return ''; let r=String(s); if (r.includes(',')||r.includes('"')||r.includes('\n')) r='"'+r.replace(/"/g,'""')+'"'; return r; };
             c+=`Prediction,final_prediction,${e(rd.final_prediction)}\r\n`;
             if(rd.probabilities) Object.entries(rd.probabilities).forEach(([k,v])=>c+=`Probability,${e(k)},${e(v)}\r\n`);
             c+=`Decision,distance_to_benign,${e(rd.distance_to_benign)}\r\n`;
             c+=`Decision,distance_to_malignant,${e(rd.distance_to_malignant)}\r\n`;
             c+=`Decision,tau,${e(rd.tau)}\r\n`;
             c+=`Decision,ratio_decision_rule,${e(rd.ratio_decision)}\r\n`;
             c+=`Abnormality,type,${e(rd.abnormality_type)}\r\n`;
             if(rd.abnormality_scores) Object.entries(rd.abnormality_scores).forEach(([k,v])=>c+=`Abnormality Score,${e(PRETTY_NAMES[k] || k)},${e(v)}\r\n`); // Use pretty name
             if(rd.background_tissue){ c+=`Background,code,${e(rd.background_tissue.code)}\r\n`; c+=`Background,text,${e(rd.background_tissue.text)}\r\n`; c+=`Background,explanation,${e(rd.background_tissue.explain)}\r\n`; }
             if(rd.explanation?.class) c+=`Explanation,class,${e(rd.explanation.class.join('; '))}\r\n`;
             if(rd.explanation?.abnormality_summary) c+=`Explanation,abnormality_summary,${e(rd.explanation.abnormality_summary)}\r\n`; // Updated field
             if(rd.top_feature_contributors) rd.top_feature_contributors.forEach(([n,v])=>c+=`Feature Contribution,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`); // Use pretty name
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => c+=`Z-Score,${e(PRETTY_NAMES[k] || k)},${e(rd.zscores[k])}\r\n`); // Add Z-Scores with pretty names

             const b=new Blob([c],{type:'text/csv;charset=utf-8;'}); const l=document.createElement("a");
             if(l.download!==undefined){ const u=URL.createObjectURL(b); const t=new Date().toISOString().replace(/:/g,'-').slice(0,19); l.setAttribute("href",u); l.setAttribute("download",`prediction_results_${t}.csv`); l.style.visibility='hidden'; document.body.appendChild(l); l.click(); document.body.removeChild(l); }
        }
        function buildCSVPreviewHTML(rd, l=35) { // Show more rows
             const rs=[];
             rs.push(["Prediction","final_prediction",String(rd.final_prediction??"—")]);
             if(rd.probabilities)Object.entries(rd.probabilities).forEach(([k,v])=>rs.push(["Probability",k,String(v??"—")]));
             rs.push(["Decision","distance_to_benign",String(rd.distance_to_benign??"—")]);
             rs.push(["Decision","distance_to_malignant",String(rd.distance_to_malignant??"—")]);
             rs.push(["Decision","tau",String(rd.tau??"—")]);
             rs.push(["Decision","ratio_decision_rule",String(rd.ratio_decision??"—")]);
             rs.push(["Abnormality","type",String(rd.abnormality_type??"—")]);
             if(rd.abnormality_scores)Object.entries(rd.abnormality_scores).forEach(([k,v])=>rs.push(["Abnormality Score", PRETTY_NAMES[k] || k ,String(v??"—")]));
             if(rd.background_tissue){rs.push(["Background","code",String(rd.background_tissue.code??"—")]); rs.push(["Background","text",String(rd.background_tissue.text??"—")]); rs.push(["Background","explanation",String(rd.background_tissue.explain??"—")]);}
             if(Array.isArray(rd.explanation?.class))rs.push(["Explanation","class",String(rd.explanation.class.join("; "))]);
             if(rd.explanation?.abnormality_summary)rs.push(["Explanation","abnormality_summary",String(rd.explanation.abnormality_summary)]);
             if(Array.isArray(rd.top_feature_contributors))rd.top_feature_contributors.forEach(([n,v])=>rs.push(["Feature Contribution", PRETTY_NAMES[n] || n ,String(v??"—")]));
             if(rd.zscores) Object.keys(rd.zscores).sort().forEach(k => rs.push(["Z-Score", PRETTY_NAMES[k] || k, String(rd.zscores[k] ?? "—")]));

             const lim=rs.slice(0,l); let t='<div class="table-wrapper-scroll"><table class="data-table"><thead><tr><th>Category</th><th>Parameter</th><th>Value</th></tr></thead><tbody>'; lim.forEach(r=>t+=`<tr><td>${escapeHTML(r[0])}</td><td>${escapeHTML(r[1])}</td><td>${escapeHTML(r[2])}</td></tr>`); t+='</tbody></table></div>'; t+=`<div class="modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;"><button type="button" class="btn" id="csv-download-confirm">Download All ${rs.length} Rows</button><button type="button" class="btn btn-secondary" id="csv-preview-close">Close</button></div><p class="file-meta" style="margin-top:.5rem; text-align:right;">Showing first ${lim.length} of ${rs.length} rows.</p>`; return t;
        }
        function openCSVPreview(rd) { const h=buildCSVPreviewHTML(rd); showContentInModal('CSV Preview', h); const dl=document.getElementById('csv-download-confirm'); const cl=document.getElementById('csv-preview-close'); if(dl)dl.addEventListener('click',()=>{downloadCSV(rd); closeCardModal();}); if(cl)cl.addEventListener('click',closeCardModal); }


        // --- Initialization ---
        const initialPredictData = window.__PREDICT__;
        const stored = loadState();
        let initialImageSrc = null;

        // Determine initial state (PHP load > LocalStorage > Default)
        if (initialPredictData?.result) { // Result from PHP (e.g., non-JS submit)
            resultsPlaceholder.style.display = 'none';
            displayResults(initialPredictData.result);
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = window.__UPLOADED_IMAGE__;
        } else if (stored?.result) { // Result from localStorage
            resultsPlaceholder.style.display = 'none';
            displayResults(stored.result); // Display stored result
            clearBtn.style.display = 'inline-flex';
            initialImageSrc = stored.imagePath || stored.previewDataUrl; // Use stored image path
             // Set window.__PREDICT__ from storage so modal logic works on first load
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
                submitBtn.disabled = false; // Enable submit if image loaded
                uploadArea.style.display = 'none';
                // Don't build histogram here, let displayResults handle it
            };
            img.onerror = () => { console.warn("Could not load initial image:", initialImageSrc); clearState(); /* Clear bad state? */ };
            img.src = initialImageSrc;
        }

    });
    </script>

</body>
</html>


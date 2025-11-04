<?php
// ──────────────────────────────────────────────
//  comparison.php
//  add the resolver at the very top
// ──────────────────────────────────────────────
function resolve_test_image_path($requestedPathOrBasename) {
    $root = __DIR__ . "/data/test_images";

    // 1. if the request is already a valid full path
    if (is_file($requestedPathOrBasename)) {
        return $requestedPathOrBasename;
    }

    // 2. if the basename exists directly under /test_images
    $base = basename($requestedPathOrBasename);
    $legacy = "$root/$base";
    if (is_file($legacy)) {
        return $legacy;
    }

    // 3. if it uses the new naming scheme (__basename pattern)
    $matches = glob($root . "/*__*__" . $base);
    if (!empty($matches)) {
        return $matches[0];  // return the first match
    }

    // 4. not found
    return null;
}
// --- end of resolver helper ---

// We still need session for the non-JS error fallback
session_start();


/* ──────────────────────────────────────────────────────────────────────────
 1) Load Config & Session State (Minimal)
────────────────────────────────────────────────────────────────────────── */
$config  = require __DIR__ . '/config.php';
$python  = $config['python_path'] ?? null;
$workdir = $config['workdir'] ?? null;

 

// Only load/unset PHP error state. Results are handled by JS/localStorage.
$error = $_SESSION['comparison_error'] ?? null;
unset($_SESSION['comparison_error']);

// These will be populated by JavaScript from localStorage on page load
$result = null;
$uploaded_image_src = null;
$history = []; // History is now client-side

//
// +++ Pretty Names definition +++
// (Copied from index.php for consistency)
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
// +++ END Pretty Names definition +++


// Helper function (no change)
function translate_features($features, $pretty_names) {
    if (empty($features) || !is_array($features)) return 'N/A';
    $translated = array_map(function($f) use ($pretty_names) {
        $trimmed_f = trim($f);
        if ($trimmed_f === '(none)') return 'N/A';
        return htmlspecialchars($pretty_names[$trimmed_f] ?? $trimmed_f);
    }, $features);
    if (count($translated) === 1 && $translated[0] === 'N/A') return 'N/A';
    return implode(', ', $translated);
}


// --- ADDED FOR DEBUGGING ---
$debug_info = [];
// --- END DEBUGGING ---


// +++ Function to parse the new plain text output (no change) +++
function parse_backend_output($stdout_str) {
    $result = [
        'Ground Truth' => 'N/A',
        'Correct Classification' => 'N/A',
        'WOA' => parse_single_model_block($stdout_str, 'Woa'),
        'EWOA' => parse_single_model_block($stdout_str, 'Ewoa'),
    ];
    if (preg_match('/"Correct Classification": "([^"]+)"/', $stdout_str, $m)) {
        $result['Correct Classification'] = $m[1];
        $result['Ground Truth'] = $m[1];
    }
    if ($result['WOA']['Execution Time'] === 'N/A' && $result['EWOA']['Execution Time'] === 'N/A') {
        return null; // Parsing failed completely
    }
    return $result;
}

// +++ REFINED PARSING FUNCTION (no change) +++
function parse_single_model_block($stdout_str, $model_key) {
    $data = [
        'Prediction' => 'N/A',
        'Execution Time' => 'N/A',
        'Total detected' => 'N/A',
        'Total malignant' => 'N/A',
        'Total benign' => 'N/A',
        'Malignant features' => 'N/A',
        'Benign features' => 'N/A',
        'All features names' => 'N/A',
        'Top Contributors' => [],
        'All Detected Features' => [],
    ];
    $pattern = '/' . preg_quote($model_key, '/') . ':\s*([\s\S]*?)(?=(?:Woa:|Ewoa:|"Correct Classification":|\z))/i';
    if (!preg_match($pattern, $stdout_str, $block_match)) {
        return $data; // Model block not found
    }
    $block = $block_match[1];
    if (preg_match('/"Prediction": "([^"]+)"/', $block, $m)) $data['Prediction'] = $m[1];
    if (preg_match('/total number of features detected:\s*([\d]+)/is', $block, $m)) $data['Total detected'] = $m[1];
    if (preg_match('/total number of "towards malignant":\s*([\d]+)/is', $block, $m)) $data['Total malignant'] = $m[1];
    if (preg_match('/total number of "towards benign":\s*([\d]+)/is', $block, $m)) $data['Total benign'] = $m[1];
    if (preg_match('/name of malignant features:\s*([\s\S]*?)(?=name of benign features:)/i', $block, $m)) {
        $data['Malignant features'] = trim(str_replace("\n", " ", $m[1]));
    }
    if (preg_match('/name of benign features:\s*([\s\S]*?)(?=name of all detected features:)/i', $block, $m)) {
        $data['Benign features'] = trim(str_replace("\n", " ", $m[1]));
    }
    if (preg_match('/name of all detected features:\s*([\s\S]*?)(?=Exec time:)/i', $block, $m)) {
        $data['All features names'] = trim(str_replace("\n", " ", $m[1]));
    }
    if (preg_match('/Exec time:\s*([\d.]+)s/is', $block, $m)) $data['Execution Time'] = $m[1];
    if (preg_match('/top features contributing to malignant:\s*([\s\S]*?)(?=all detected features with numericals|"\w+":|\z)/i', $block, $feature_block)) {
        if (preg_match_all('/-\s*([\w_]+):\s*(-?[\d.]+)/', $feature_block[1], $feature_matches, PREG_SET_ORDER)) {
            foreach ($feature_matches as $match) {
                $data['Top Contributors'][] = [$match[1], (float)$match[2]];
            }
        }
    }
    if (preg_match('/all detected features with numericals.*:\s*([\s\S]*?)(?:\n\n|\z)/i', $block, $all_f_block)) {
        if (preg_match_all('/-\s*([\w_]+):\s*(-?[\d.]+)/', $all_f_block[1], $all_matches, PREG_SET_ORDER)) {
             foreach ($all_matches as $match) {
                $data['All Detected Features'][] = [$match[1], (float)$match[2]];
            }
        }
    }
    return $data;
}
// +++ END new function +++


/* ──────────────────────────────────────────────────────────────────────────
   3) Handle File Upload & Backend Call (MODIFIED FOR AJAX)
────────────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    
    // --- A. Handle File Upload ---
    $uploadDir = __DIR__ . '/test_uploads/';
    @mkdir($uploadDir, 0777, true);

    $originalName  = basename($_FILES['image']['name']);
    $imagePath     = $uploadDir . $originalName; 
    $uploaded_image_src = null; // Init

    if (move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
        $uploaded_image_src = 'test_uploads/' . $originalName; 

        try {
            // --- B. Build Python Command (Reliably) ---
            if (empty($python) || empty($workdir)) {
                throw new Exception("Config Error: 'python_path' or 'workdir' is not set in config.php.");
            }

            // 1. Get CSV Path
            $csv_path = $config['csv_path'] ?? null;
            $csv_arg = '';
            $debug_info[] = "--- CSV Path Debug ---";
            $debug_info[] = "Config 'csv_path': " . ($csv_path ?? 'NOT SET');
            if ($csv_path) {
                clearstatcache();
                $debug_info[] = "file_exists(): " . (file_exists($csv_path) ? 'true' : 'false');
                $debug_info[] = "is_readable(): " . (is_readable($csv_path) ? 'true' : 'false');
            }
            $open_basedir = ini_get('open_basedir');
            $debug_info[] = "PHP open_basedir: " . ($open_basedir ? $open_basedir : 'NOT SET (Good!)');
            
            if ($csv_path && file_exists($csv_path) && is_readable($csv_path)) {
                $csv_arg = ' --csv ' . escapeshellarg($csv_path);
            } else {
                error_log('[comparison.php] CSV check failed. Path: ' . (string)$csv_path . ' | exists: ' . (file_exists((string)$csv_path) ? 'T' : 'F') . ' | readable: ' . (is_readable((string)$csv_path) ? 'T' : 'F'));
            }
            
            // 2. Get Model Paths
        
            $ewoa_model_path = $config['models']['ewoa'] ?? ($workdir . '/models/model_final_ewoa.json');
            $woa_model_path  = $config['models']['woa']  ?? ($workdir . '/models/model_woa.json');
            
            if (!file_exists($ewoa_model_path)) error_log('[comparison.php] ERROR: EWOA model not found: ' . $ewoa_model_path);
            if (!file_exists($woa_model_path))  error_log('[comparison.php] ERROR: WOA model not found: ' . $woa_model_path);

            // 3. Construct the final command
            $cmd = sprintf(
                'PYTHONPATH=%s %s -m woa_tool.compare_predict --image %s --ewoa %s --woa %s%s',
                escapeshellarg($workdir),
                escapeshellcmd($python),
                escapeshellarg($imagePath),
                escapeshellarg($ewoa_model_path),
                escapeshellarg($woa_model_path),
                $csv_arg
            );
            $debug_info[] = "--- Command ---";
            $debug_info[] = $cmd;

            // --- C. Execute Python Script ---
            $stdout_str = ''; $stderr_str = ''; $code = -1;
            $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
            $process = proc_open($cmd, $descriptorspec, $pipes, $workdir);

            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout_str = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $stderr_str = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($process);
            } else {
                throw new Exception("Failed to execute Python script (proc_open failed).");
            }

            // --- D. Process Python Output ---
            $decoded = parse_backend_output(trim($stdout_str));
            
            if ($code === 0 && $decoded !== null) {
                // SUCCESS!
                
                // +++ NEW: Respond with JSON if AJAX request +++
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    // Add image src to the result object
                    $decoded['image_src'] = $uploaded_image_src;
                    echo json_encode(['ok' => true, 'result' => $decoded, 'image' => $uploaded_image_src]);
                    exit;
                }
                // (No session saving needed anymore)
                
            } else {
                // Parsing failed or script returned an error code
                $errorMsg  = "Failed to get valid output from Python script (Code: $code).";
                if(!empty($stderr_str)) { $errorMsg .= "<br><strong>Stderr (Python Error):</strong><pre>" . htmlspecialchars($stderr_str) . "</pre>"; }
                if(!empty($stdout_str)) { $errorMsg .= "<br><strong>Raw Stdout:</strong><pre>" . htmlspecialchars(trim($stdout_str)) . "</pre>"; }
                if(empty($stderr_str) && empty($stdout_str) && $code !== 0) { $errorMsg .= "<br><strong>Details:</strong> The script exited with code $code but produced no output."; }
                $errorMsg .= "<br><strong>Debug Info:</strong><pre>" . htmlspecialchars(implode("\n", $debug_info)) . "</pre>";
                throw new Exception($errorMsg);
            }

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            if (!empty($debug_info)) {
                 $errorMsg .= "<br><strong>Debug Info:</strong><pre>" . htmlspecialchars(implode("\n", $debug_info)) . "</pre>";
            }
            
            // +++ NEW: Respond with JSON error if AJAX request +++
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $errorMsg, 'debug' => $debug_info]);
                exit;
            }
            // Fallback for non-JS
            $_SESSION['comparison_error'] = $errorMsg;
        }
    } else {
        // move_uploaded_file failed
        $errorMsg = "Failed to move uploaded file. Check directory permissions for '$uploadDir'.";
        
        // +++ NEW: Respond with JSON error if AJAX request +++
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $errorMsg]);
            exit;
        }
        // Fallback for non-JS
        $_SESSION['comparison_error'] = $errorMsg;
    }

    // --- E. Redirect after POST (for non-JS fallback) ---
    header('Location: comparison.php');
    exit;
}
/* ──────────────────────────────────────────────────────────────────────────
   (End of PHP Logic)
────────────────────────────────────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>WOA vs EWOA Comparison | WOA-Tool</title>
  <link rel="stylesheet" href="style.css?v=34" /> <!-- Version bump -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  
  <!-- Inline styles for history log and new charts -->
  <style>
    /* Styles for JS-injected history (from index.php) */
    .history-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0.5rem;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        transition: background-color 0.2s ease-in-out;
    }
    .history-item:hover {
        background-color: var(--bg-medium-tint);
    }
    .history-item:last-child {
        border-bottom: none;
    }
    .history-item-left {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
        overflow: hidden;
        margin-right: 0.5rem;
    }
    .history-item-filename {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .history-item-date {
        font-size: 0.75rem;
        color: var(--text-dark);
    }
    .history-item-right {
        flex-shrink: 0;
    }
    .pill.history-item-malignant { background-color: var(--accent-warning-tint); color: var(--accent-warning); }
    .pill.history-item-benign { background-color: var(--accent-success-tint); color: var(--accent-success); }


    /* Original styles from comparison.php */
    .latest-history {
        background-color: var(--bg-medium-tint, #f0f3f8);
        font-weight: bold;
    }
    .chart-sub-header {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-header, #333);
        margin-top: 1rem;
        margin-bottom: 0.25rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--border-color, #e0e0e0);
    }
    .chart-sub-desc {
        font-size: 0.9rem;
        color: var(--text-dark, #555);
        margin-bottom: 1.5rem;
    }
    .chart-container-wrapper h4 {
        text-align: center;
        font-weight: 500;
        color: var(--text-dark, #555);
        margin-bottom: 0.75rem;
    }
    .value-malignant { color: var(--accent-warning, #e74c3c) !important; }
    .value-benign { color: var(--accent-success, #2ecc71) !important; }
  </style>

</head>
<body id="page-comparison">
  <!-- Top nav (same as index.php) -->
  <header class="main-header">
    <div class="header-inner">
      <div class="header-left">
        <div class="header-logo">🐋</div>
        <div class="header-title">
          <h1>WOA: <span>Balancing Exploration–Exploitation</span></h1>
          <p>for Breast Cancer Feature Detection</p>
        </div>
      </div>
      <nav class="header-nav">
        <a href="index.php"       class="<?= basename($_SERVER['PHP_SELF'])==='index.php'     ? 'active' : '' ?>">Feature Detection</a>
        <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF'])==='benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a>
        <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF'])==='comparison.php' ? 'active' : '' ?>">Comparison</a>
      </nav>
    </div>
  </header>

  <div id="aurora-background"></div>

  <div class="main-container">
    <!-- Page header -->
    <div class="header">
      <h1>
        <span class="header-logo" style="font-size:2.2rem;width:60px;height:60px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="6" y1="21" x2="6" y2="3"></line>
            <line x1="18" y1="21" x2="18" y2="3"></line>
            <line x1="2" y1="12" x2="22" y2="12"></line>
          </svg>
        </span>
        WOA vs. EWOA Comparison
      </h1>
      <p>Upload a mammogram image to see a side-by-side comparison of the two methods.</p>
    </div>

    <!-- NEW: Left Column -->
    <div class="left-column">
        <!-- ── Upload card ───────────────────────── -->
        <div class="step-card animate-slide-up">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number" style="background: var(--text-light);">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                  <polyline points="17 8 12 3 7 8"></polyline>
                  <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
              </div>
              <h2>Upload Image</h2>
            </div>
          </div>
    
          <form id="comparison-form" method="POST" enctype="multipart/form-data">
            <div id="image-preview-wrapper" style="display: none; background:#fff;">
              <img id="image-preview"
                   src="#" 
                   alt="Image preview"
                   style="max-width:400px; max-height:300px; width: auto; border-radius:var(--border-radius-small);" />
            </div>
    
            <label for="image-upload" class="upload-area" id="upload-area"
                   style="display: block;">
              <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg"
                   width="24" height="24" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round"
                   stroke-linejoin="round">
                <path d="M21.2 15c.7-1.2 1-2.5.7-3.9-.6-2.4-2.4-4.2-4.8-4.8-1.4-.3-2.7-.1-3.9.7L12 8l-1.2-1.1c-1.2-.8-2.5-1-3.9-.7-2.4.6-4.2 2.4-4.8 4.8-.3 1.4-.1 2.7.7 3.9L4 16.5 12 22l8-5.5-2.8-1.5z"></path>
                <path d="M12 8v8"></path>
              </svg>
              <p class="upload-area__text"><span>Click to upload</span> or drag & drop</p>
            </label>
            <input type="file" id="image-upload" name="image" accept="image/*" />
            <p class="file-meta" id="file-meta-text" style="display:none;text-align:center;"></p>
    
            <div class="form-buttons mt-3">
              <button type="submit" id="run-comparison-btn" class="btn" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="6" y1="21" x2="6" y2="3"></line>
                  <line x1="18" y1="21" x2="18" y2="3"></line>
                  <line x1="2" y1="12" x2="22" y2="12"></line>
                </svg>
                <span id="btn-text">Run Comparison</span>
              </button>
              <button type="button" id="reset-btn" class="btn btn-secondary" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <polyline points="1 20 1 14 7 14"></polyline>
                  <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                Reset
              </button>
            </div>
          </form>
        </div>
    
        <!-- ── NEW: JS-powered History Log ────────────────── -->
        <div class="step-card" id="history-card" style="margin-top: 2rem;">
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
                <p class="file-meta" id="history-placeholder" style="text-align:center; padding: 1rem 0;">No history saved.</p>
                <div id="history-list"></div>
            </div>
        </div>
        
        <!-- NEW: Error container for JS -->
        <div id="error-container"></div>
    </div> <!-- /left-column -->

    <!-- NEW: Right Column -->
    <div class="right-column">
        <!-- ── Results/Error wrapper ─────────────────────────────────── -->
        <div id="results-wrapper">
    
          <!-- NEW: Skeleton Loader -->
          <div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;">
               <div class="step-card loader-card">
                 <div class="loader-inner">
                   <div class="scan-loader">
                     <span></span><span></span><span></span><span></span>
                   </div>
                   <p class="loader-caption">Running comparison... please wait</p>
                 </div>
               </div>
          </div>
    
          <!-- Placeholder -->
          <div id="comparison-placeholder" class="placeholder-card single-placeholder animate-slide-up" style="display: block;">
            <div class="step-header">
              <div class="step-header-left">
                <div class="step-number" style="background: var(--text-dark); opacity: 0.5;">?</div>
                <h2>Results</h2>
              </div>
            </div>
            <div class="placeholder-content">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                   viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
                <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
              </svg>
              <h3>Waiting for Image</h3>
              <p>Upload a mammogram image to begin the comparison.</p>
            </div>
          </div>
          
    
          <!-- Main Results Container -->
          <div id="comparison-results" class="animate-slide-up" style="display: none;">
            
            <!-- Ground truth + overall -->
            <div class="step-card" style="margin-bottom:1.5rem;">
              <div class="step-header">
                <div class="step-header-left">
                  <div class="step-number">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path>
                      <path d="m9 12 2 2 4-4"></path>
                    </svg>
                  </div>
                  <h2>Overall Summary</h2>
                </div>
              </div>
              
              <p class="classification-banner warning">
                <span class="banner-icon">i</span>
                Correct Classification: <strong data-field="cc-banner-text">N/A</strong>
                <span class="tooltip-icon">?<span class="tooltip-content">This is the ground truth label found by the backend.</span></span>
              </p>
    
              <div class="comparison-summary">
                 <div class="summary-metric">
                  <span class="metric-label">WOA Runtime
                    <span class="tooltip-icon">?<span class="tooltip-content">Execution time for Standard WOA.</span></span>
                  </span>
                  <span class="metric-value" data-field="summary-woa-time">0.000 s</span>
                </div>
                 <div class="summary-metric">
                  <span class="metric-label">EWOA Runtime
                    <span class="tooltip-icon">?<span class="tooltip-content">Execution time for Enhanced WOA.</span></span>
                  </span>
                  <span class="metric-value" data-field="summary-ewoa-time">0.000 s</span>
                </div>
                <div class="summary-metric">
                  <span class="metric-label">Time Improvement
                    <span class="tooltip-icon">?<span class="tooltip-content">Positive means EWOA was faster vs WOA.</span></span>
                  </span>
                  <span class="metric-value" data-field="summary-time-improvement">N/A</span>
                </div>
                <div class="summary-metric">
                  <span class="metric-label">Total Python Runtime
                    <span class="tooltip-icon">?<span class="tooltip-content">Sum of both runs.</span></span>
                  </span>
                  <span class="metric-value" data-field="summary-total-time">0.000 s</span>
                </div>
              </div>
            </div>
    
            <div class="comparison-grid" style="margin-bottom: 2rem;">
              <!-- Left Column -->
              <div class="comparison-column" id="woa-column">
                <!-- WOA Main Card -->
                <div class="step-card comparison-card">
                  <div class="step-header">
                    <div class="step-header-left">
                      <div class="step-number" style="background: var(--text-dark);">W</div>
                      <h2>Standard WOA</h2>
                    </div>
                    <button class="maximize-card-btn" title="Maximize">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
                    </button>
                  </div>
                  <div class="card-content" id="woa-card-content">
                    <ul class="comparison-metrics simplified">
                      <li>
                        <span class="metric-label">Prediction
                          <span class="tooltip-icon">?<span class="tooltip-content">Prediction from the backend.</span></span>
                        </span>
                        <span class="metric-value" data-field="woa-prediction">N/A</span>
                      </li>
                      <li>
                        <span class="metric-label">Exec. Time
                          <span class="tooltip-icon">?<span class="tooltip-content">Seconds for this run.</span></span>
                        </span>
                        <span class="metric-value" data-field="woa-time">0.000 s</span>
                      </li>
                      <li>
                        <span class="metric-label">Total Features Detected
                          <span class="tooltip-icon">?<span class="tooltip-content">Total features selected by the model.</span></span>
                        </span>
                        <span class="metric-value" data-field="woa-total-detected">N/A</span>
                      </li>
                      <li>
                        <span class="metric-label">Malignant-Leaning
                          <span class="tooltip-icon">?<span class="tooltip-content">Features contributing to a malignant prediction.</span></span>
                        </span>
                        <span class="metric-value value-malignant" data-field="woa-total-malignant">N/A</span>
                      </li>
                        <li>
                        <span class="metric-label">Benign-Leaning
                          <span class="tooltip-icon">?<span class="tooltip-content">Features contributing to a benign prediction.</span></span>
                        </span>
                        <span class="metric-value value-benign" data-field="woa-total-benign">N/A</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div> <!-- /woa-column -->
    
              <!-- Right Column -->
              <div class="comparison-column" id="ewoa-column">
                <!-- EWOA Main Card -->
                <div class="step-card comparison-card ewoa-card">
                  <div class="step-header">
                    <div class="step-header-left">
                      <div class="step-number" style="background: var(--accent-glow);">E</div>
                      <h2>Enhanced WOA</h2>
                    </div>
                    <button class="maximize-card-btn" title="Maximize">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg>
                    </button>
                  </div>
                  <div class="card-content" id="ewoa-card-content">
                    <ul class="comparison-metrics simplified">
                        <li>
                        <span class="metric-label">Prediction
                          <span class="tooltip-icon">?<span class="tooltip-content">Prediction from the backend.</span></span>
                        </span>
                        <span class="metric-value" data-field="ewoa-prediction">N/A</span>
                      </li>
                      <li>
                        <span class="metric-label">Exec. Time
                          <span class="tooltip-icon">?<span class="tooltip-content">Seconds for this run.</span></span>
                        </span>
                        <span class="metric-value" data-field="ewoa-time">0.000 s</span>
                      </li>
                      <li>
                        <span class="metric-label">Total Features Detected
                          <span class="tooltip-icon">?<span class="tooltip-content">Total features selected by the model.</span></span>
                        </span>
                        <span class="metric-value" data-field="ewoa-total-detected">N/A</span>
                      </li>
                      <li>
                        <span class="metric-label">Malignant-Leaning
                          <span class="tooltip-icon">?<span class="tooltip-content">Features contributing to a malignant prediction.</span></span>
                        </span>
                        <span class="metric-value value-malignant" data-field="ewoa-total-malignant">N/A</span>
                      </li>
                        <li>
                        <span class="metric-label">Benign-Leaning
                          <span class="tooltip-icon">?<span class="tooltip-content">Features contributing to a benign prediction.</span></span>
                        </span>
                        <span class="metric-value value-benign" data-field="ewoa-total-benign">N/A</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div> <!-- /ewoa-column -->
            </div> <!-- /comparison-grid -->
    
            <!-- Key Metrics Comparison Chart -->
            <div class="step-card animate-slide-up" id="metrics-comparison-card" style="margin-bottom: 2rem;">
              <div class="step-header">
                <div class="step-header-left">
                  <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>Execution Time Comparison</h2>
                </div>
                <span class="tooltip-icon">i<span class="tooltip-content">Direct comparison of execution time in seconds.</span></span>
                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
              </div>
              <div class="card-content">
                <div class="chart-container" style="height: 350px;">
                  <canvas id="metrics-comparison-chart"></canvas>
                </div>
              </div>
            </div>
            
            <!-- Top Feature Contributions Card -->
            <div class="step-card animate-slide-up" id="top-features-card" style="margin-bottom: 2rem;">
              <div class="step-header">
                <div class="step-header-left">
                  <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h15.75c.621 0 1.125.504 1.125 1.125v6.75C21 20.496 20.496 21 19.875 21H4.125A1.125 1.125 0 013 19.875v-6.75zM3 8.625C3 8.004 3.504 7.5 4.125 7.5h15.75c.621 0 1.125.504 1.125 1.125v.75C21 10.996 20.496 11.5 19.875 11.5H4.125A1.125 1.125 0 013 10.375v-.75zM3 4.125C3 3.504 3.504 3 4.125 3h15.75c.621 0 1.125.504 1.125 1.125v.75C21 5.496 20.496 6 19.875 6H4.125A1.125 1.125 0 013 4.875v-.75z" /></svg>Feature Contributions (Benign vs. Malignant)</h2>
                </div>
                <span class="tooltip-icon">i<span class="tooltip-content">Visualizes all features leaning towards Malignant (negative) and Benign (positive) for each model.</span></span>
                <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.f5.5 0 0 1 .5-.5z" /></svg></button>
              </div>
              <div class="card-content">
                <h3 class="chart-sub-header value-malignant">All Malignant-Leaning Features</h3>
                <p class="chart-sub-desc">Features with a negative contribution (more negative is stronger).</p>
                <div class="comparison-grid">
                    <div class="chart-container-wrapper">
                        <h4>Standard WOA</h4>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="woa-top-malignant-chart-canvas"></canvas>
                        </div>
                    </div>
                    <div class="chart-container-wrapper ewoa-card">
                        <h4>Enhanced WOA</h4>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="ewoa-top-malignant-chart-canvas"></canvas>
                        </div>
                    </div>
                </div>
                
                <h3 class="chart-sub-header value-benign">All Benign-Leaning Features</h3>
                <p class="chart-sub-desc">Features with a positive contribution (more positive is stronger).</p>
                <div class="comparison-grid">
                    <div class="chart-container-wrapper">
                        <h4>Standard WOA</h4>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="woa-top-benign-chart-canvas"></canvas>
                        </div>
                    </div>
                    <div class="chart-container-wrapper ewoa-card">
                        <h4>Enhanced WOA</h4>
                        <div class="chart-container" style="height: 300px;">
                            <canvas id="ewoa-top-benign-chart-canvas"></canvas>
                        </div>
                    </div>
                </div>
              </div>
            </div>
            
            <!-- All Detected Features Card (Tables) -->
            <div class="step-card animate-slide-up" id="all-features-card" style="margin-top: 2rem;">
                <div class="step-header">
                    <div class="step-header-left">
                      <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>All Detected Features (Tables)</h2>
                    </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Full list of numerical contributions for all selected features. Negative values lean Malignant, Positive values lean Benign.</span></span>
                    <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                </div>
                <div class="card-content">
                    <div class="comparison-grid">
                        <div class="tfc-table-wrapper" id="woa-all-features-wrapper">
                            <h3>Standard WOA</h3>
                            <div class="table-wrapper-scroll" id="woa-all-features-scroll" style="max-height: 400px;">
                                <table class="data-table">
                                    <thead><tr><th>Feature</th><th>Contribution</th></tr></thead>
                                    <tbody id="woa-all-features-body"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tfc-table-wrapper ewoa-card" id="ewoa-all-features-wrapper">
                            <h3>Enhanced WOA</h3>
                            <div class="table-wrapper-scroll" id="ewoa-all-features-scroll" style="max-height: 400px;">
                                <table class="data-table">
                                    <thead><tr><th>Feature</th><th>Contribution</th></tr></thead>
                                    <tbody id="ewoa-all-features-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- All Detected Features (Charts) Card -->
            <div class="step-card animate-slide-up" id="all-features-charts-card" style="margin-top: 2rem;">
                <div class="step-header">
                    <div class="step-header-left">
                      <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" /></svg>All Detected Features (Charts)</h2>
                    </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Full list of numerical contributions, sorted from most Benign (positive) to most Malignant (negative).</span></span>
                    <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg></button>
                </div>
                <div class="card-content">
                    <div class="comparison-grid"> 
                        <div class="chart-container-wrapper">
                            <h3 class="chart-sub-header">Standard WOA</h3>
                            <p class="chart-sub-desc">All features sorted by contribution.</p>
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="woa-all-features-chart-canvas"></canvas>
                            </div>
                        </div>
                        <div class="chart-container-wrapper ewoa-card">
                            <h3 class="chart-sub-header" style="margin-top: 1rem;">Enhanced WOA</h3>
                            <p class="chart-sub-desc">All features sorted by contribution.</p>
                            <div class="chart-container" style="height: 300px;">
                                <canvas id="ewoa-all-features-chart-canvas"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    
    
          </div>
        </div> <!-- /results-wrapper -->
    </div> <!-- /right-column -->
  </div> <!-- /main-container -->

  <!-- Modal -->
  <div id="card-modal-overlay">
    <div id="card-modal-content">
      <button class="close-modal-btn">&times;</button>
      <h2 class="modal-title">Modal Title</h2>
      <div class="modal-body" id="card-modal-body"></div>
    </div>
  </div>

  <footer><p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p></footer>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // === Refs ===
    const form = document.getElementById('comparison-form'),
          fileInput = document.getElementById('image-upload'),
          uploadArea = document.getElementById('upload-area'),
          previewWrapper = document.getElementById('image-preview-wrapper'),
          previewImg = document.getElementById('image-preview'),
          fileMetaText = document.getElementById('file-meta-text'),
          runButton = document.getElementById('run-comparison-btn'),
          btnText = document.getElementById('btn-text'),
          resetButton = document.getElementById('reset-btn'),
          // Loaders/Containers
          skeletonLoader = document.getElementById('skeleton-loader'),
          resultsWrapper = document.getElementById('results-wrapper'),
          placeholderCard = document.getElementById('comparison-placeholder'),
          resultsContainer = document.getElementById('comparison-results'),
          errorContainer = document.getElementById('error-container'),
          // Modal refs
          modalOverlay = document.getElementById('card-modal-overlay'),
          modalContent = document.getElementById('card-modal-content'),
          modalTitle = modalContent.querySelector('.modal-title'),
          modalBody = modalContent.querySelector('#card-modal-body'),
          closeModalBtn = modalContent.querySelector('.close-modal-btn'),
          // History Refs (NEW)
          historyList = document.getElementById('history-list'),
          historyPlaceholder = document.getElementById('history-placeholder'),
          clearHistoryBtn = document.getElementById('clear-history-btn');

    // === State ===
    let activeCharts = {};
    let window__PREDICT__ = null; // Store last result for modals
    const PRETTY_NAMES = <?php echo json_encode($pretty_names); ?> || {};
    const computedStyles = getComputedStyle(document.documentElement);
    const chartColors = { 
        accentGlow: computedStyles.getPropertyValue('--accent-glow').trim() || 'rgba(216, 27, 96, 0.7)', 
        accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim() || 'rgba(216, 27, 96, 0.1)', 
        accentSuccess: computedStyles.getPropertyValue('--accent-success').trim() || 'rgba(46, 204, 113, 0.7)', 
        accentWarning: computedStyles.getPropertyValue('--accent-warning').trim() || 'rgba(231, 76, 60, 0.7)', 
        textDark: computedStyles.getPropertyValue('--text-dark').trim() || 'rgba(127,140,141,0.7)', 
        textHeader: computedStyles.getPropertyValue('--text-header').trim() || '#333333',
        borderColor: computedStyles.getPropertyValue('--border-color').trim() || 'rgba(0,0,0,0.1)', 
        bgDark: computedStyles.getPropertyValue('--bg-dark').trim() || '#34495e',
        pastels: ['rgba(99, 179, 237, 0.7)','rgba(132, 204, 145, 0.7)','rgba(250, 202, 154, 0.7)','rgba(196, 181, 253, 0.7)','rgba(252, 165, 165, 0.7)','rgba(153, 246, 228, 0.7)', 'rgba(249, 190, 220, 0.7)', 'rgba(223, 223, 133, 0.7)', 'rgba(160, 234, 222, 0.7)', 'rgba(190, 190, 190, 0.7)']
    };
    
    // === NEW: localStorage and History Functions (from index.php) ===
    const STORAGE_KEY = 'woa_comparison_state_v1'; // Key for last run
    const HISTORY_KEY = 'woa_comparison_history_v1'; // Key for history array
    
    function loadState() { try { const r = localStorage.getItem(STORAGE_KEY); return r ? JSON.parse(r) : null; } catch (e) { return null; } }
    function saveState(p) { try { const pr = loadState() || {}; let n = { ...pr, ...p, savedAt: Date.now() }; let pl = JSON.stringify(n); if (pl.length > 4_500_000) { delete n.previewDataUrl; pl = JSON.stringify(n); } localStorage.setItem(STORAGE_KEY, pl); } catch (e) { console.warn('State save failed:', e); } }
    function clearState() { try { localStorage.removeItem(STORAGE_KEY); } catch (e) {} }

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
    
    // MODIFIED for comparison.php data structure
    function addResultToHistory(payload) {
        if (!payload?.result) return;
        try {
            let history = loadHistory();
            const historyItem = {
                id: new Date().toISOString() + '_' + Math.random().toString(36).substring(2, 9),
                savedAt: Date.now(),
                result: payload.result, // The whole result object
                imagePath: payload.image,
                filename: fileInput?.files?.[0]?.name || 'N/A'
            };
            history.unshift(historyItem); // Add to beginning
            if (history.length > 20) history.pop(); // Limit
            saveHistory(history);
            renderHistory(); // Update UI
        } catch (e) {
            console.error("Failed to add to history:", e);
        }
    }
    
    // MODIFIED for comparison.php UI
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
            const pred = item.result?.['Correct Classification'] || 'N/A'; // Use Ground Truth for the pill
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
    
    // NEW: Error display function
    function showError(m) { 
        errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${m}</div>`; 
        errorContainer.style.display = 'block';
    }


    // === File Handling ===
    function handleFile(f){
      if(f && f.type.startsWith('image/')){
        const r=new FileReader();
        r.onload=e=>{
          previewImg.src=e.target.result;
          previewWrapper.style.display='flex';
          uploadArea.style.display='none';
          runButton.disabled=false;
          resetButton.disabled=false;
          btnText.textContent = 'Run Comparison';
        };
        r.readAsDataURL(f);
        fileMetaText.textContent=`${f.name} (${(f.size/1024).toFixed(1)} KB)`;
        fileMetaText.style.display='block';
      }
    }
    fileInput.addEventListener('change',e=>handleFile(e.target.files[0]));
    ['dragenter','dragover','dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,e=>{e.preventDefault();e.stopPropagation()},!1)});
    ['dragenter','dragover'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.add('dragover'),!1)});
    ['dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.remove('dragover'),!1)});
    uploadArea.addEventListener('drop',e=>{const d=e.dataTransfer;const f=d.files[0];fileInput.files=d.files;handleFile(f)},!1);

    // === NEW: AJAX Form Submission & Loader Logic ===
    form.addEventListener('submit', async e => {
        const s = e.submitter || document.activeElement;
        // Only trigger on main run button, not reset
        if (s && s.id === 'run-comparison-btn') {
            e.preventDefault();
            if (!fileInput.files[0]) return; // No file

            // Show skeleton loader
            skeletonLoader.style.display = 'block';
            resultsContainer.style.display = 'none';
            placeholderCard.style.display = 'none';
            errorContainer.innerHTML = '';
            
            btnText.textContent = 'Analyzing...';
            runButton.disabled = true;
            resetButton.disabled = true;
            destroyAllCharts();

            try {
                const formData = new FormData(form);
                formData.set('ajax', '1'); // Add ajax flag
                
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                
                // Check for non-JSON response
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error(`Server returned non-JSON response: ${text.substring(0, 200)}...`);
                }

                const payload = await response.json();

                if (payload.ok && payload.result) {
                    displayResults(payload.result, payload.image);
                    saveState({ result: payload.result, imagePath: payload.image || null, filename: fileInput?.files?.[0]?.name || null });
                    addResultToHistory(payload);
                    resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    throw new Error(payload.error || 'Backend error. No error message provided.');
                }
            } catch (err) {
                console.error('Fetch Error:', err);
                showError(err?.message?.replace(/\n/g, '<br>') || 'Analysis error.');
                skeletonLoader.style.display = 'none'; // Hide loader on error
            } finally {
                // This runs on success or error
                skeletonLoader.style.display = 'none';
                btnText.textContent = 'Run Comparison';
                runButton.disabled = false;
                resetButton.disabled = false;
            }
        }
    });
    
    // === NEW: Reset Button JS Listener ===
    resetButton.addEventListener('click', () => {
        clearState(); // Clears localStorage
        fileInput.value = '';
        previewWrapper.style.display = 'none';
        previewImg.src = '#';
        uploadArea.style.display = 'block';
        fileMetaText.style.display = 'none';
        runButton.disabled = true;
        resetButton.disabled = true;
        btnText.textContent = 'Run Comparison';
        resultsContainer.style.display = 'none';
        errorContainer.innerHTML = '';
        placeholderCard.style.display = 'block';
        destroyAllCharts();
        window__PREDICT__ = null; // Clear global
        window.scrollTo({top: 0, behavior: 'smooth'});
    });
    
    // === NEW: History Click Listeners ===
    historyList.addEventListener('click', (e) => {
        const itemEl = e.target.closest('.history-item[data-history-id]');
        if (!itemEl) return;
        
        const id = itemEl.dataset.historyId;
        const history = loadHistory();
        const item = history.find(h => h.id === id);
        
        if (item) {
            console.log("Loading from history:", item);
            
            // 1. Display the results
            displayResults(item.result, item.imagePath); // Pass image path
            
            // 2. Display the image
            if (item.imagePath) {
                previewImg.src = item.imagePath;
                previewWrapper.style.display = 'flex';
                uploadArea.style.display = 'none';
                fileMetaText.textContent = item.filename || 'image';
                fileMetaText.style.display = 'block';
                runButton.disabled = false;
                resetButton.disabled = false;
                btnText.textContent = 'Re-run Comparison';
            }
            // 3. Scroll to results
            resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    clearHistoryBtn.addEventListener('click', () => {
        saveHistory([]); // Clear storage
        renderHistory(); // Re-render empty state
    });

    // === NEW: Main displayResults function ===
    function displayResults(resultData, imagePath) {
        if (!resultData) return;
        
        // Show results, hide placeholders
        resultsContainer.style.display = 'block';
        placeholderCard.style.display = 'none';
        errorContainer.innerHTML = '';

        window__PREDICT__ = { ok: true, result: resultData, image: imagePath }; // Save for modals

        try {
            // 1. Update Summary Card
            const cc_label = resultData['Correct Classification'] || 'N/A';
            let cc_class = 'warning';
            if (cc_label === 'Malignant') cc_class = 'malignant';
            if (cc_label === 'Benign') cc_class = 'benign';
            
            document.querySelector('[data-field="cc-banner-text"]').textContent = cc_label;
            const ccBanner = document.querySelector('.classification-banner');
            if (ccBanner) ccBanner.className = `classification-banner ${cc_class}`;
            
            const woa_time = Number(resultData.WOA['Execution Time'] || 0);
            const ewoa_time = Number(resultData.EWOA['Execution Time'] || 0);
            const time_diff = woa_time - ewoa_time;
            const percent_diff = (woa_time > 0) ? (time_diff / woa_time) * 100 : 0;
            let timeImprovement = 'N/A';
            let timeClass = '';
            if (woa_time > 0) {
                if (time_diff >= 0) {
                    timeImprovement = 'EWOA ' + percent_diff.toFixed(1) + '% faster';
                    timeClass = 'value-benign';
                } else {
                    timeImprovement = 'EWOA ' + Math.abs(percent_diff).toFixed(1) + '% slower';
                    timeClass = 'value-malignant';
                }
            }
            
            document.querySelector('[data-field="summary-woa-time"]').textContent = woa_time.toFixed(3) + ' s';
            document.querySelector('[data-field="summary-ewoa-time"]').textContent = ewoa_time.toFixed(3) + ' s';
            const timeEl = document.querySelector('[data-field="summary-time-improvement"]');
            timeEl.textContent = timeImprovement;
            timeEl.className = `metric-value ${timeClass}`;
            document.querySelector('[data-field="summary-total-time"]').textContent = (woa_time + ewoa_time).toFixed(3) + ' s';

            // 2. Update WOA/EWOA info cards
            const woaPredEl = document.querySelector('[data-field="woa-prediction"]');
            woaPredEl.textContent = resultData.WOA.Prediction || 'N/A';
            woaPredEl.className = `metric-value ${ (resultData.WOA.Prediction || '') === 'Malignant' ? 'value-malignant' : 'value-benign' }`;
            document.querySelector('[data-field="woa-time"]').textContent = (Number(resultData.WOA['Execution Time'] || 0).toFixed(3)) + ' s';
            document.querySelector('[data-field="woa-total-detected"]').textContent = resultData.WOA['Total detected'] || 'N/A';
            document.querySelector('[data-field="woa-total-malignant"]').textContent = resultData.WOA['Total malignant'] || 'N/A';
            document.querySelector('[data-field="woa-total-benign"]').textContent = resultData.WOA['Total benign'] || 'N/A';
            
            const ewoaPredEl = document.querySelector('[data-field="ewoa-prediction"]');
            ewoaPredEl.textContent = resultData.EWOA.Prediction || 'N/A';
            ewoaPredEl.className = `metric-value ${ (resultData.EWOA.Prediction || '') === 'Malignant' ? 'value-malignant' : 'value-benign' }`;
            document.querySelector('[data-field="ewoa-time"]').textContent = (Number(resultData.EWOA['Execution Time'] || 0).toFixed(3)) + ' s';
            document.querySelector('[data-field="ewoa-total-detected"]').textContent = resultData.EWOA['Total detected'] || 'N/A';
            document.querySelector('[data-field="ewoa-total-malignant"]').textContent = resultData.EWOA['Total malignant'] || 'N/A';
            document.querySelector('[data-field="ewoa-total-benign"]').textContent = resultData.EWOA['Total benign'] || 'N/A';

            // 3. Render Charts
            destroyAllCharts();
            renderMetricsChart(resultData);
            renderContributorTable('woa-all-features-body', resultData.WOA['All Detected Features']);
            renderContributorTable('ewoa-all-features-body', resultData.EWOA['All Detected Features']);
            renderFeaturesChart('woa-top-malignant-chart-canvas', resultData.WOA['All Detected Features'], 'malignant');
            renderFeaturesChart('ewoa-top-malignant-chart-canvas', resultData.EWOA['All Detected Features'], 'malignant');
            renderFeaturesChart('woa-top-benign-chart-canvas', resultData.WOA['All Detected Features'], 'benign');
            renderFeaturesChart('ewoa-top-benign-chart-canvas', resultData.EWOA['All Detected Features'], 'benign');
            renderAllFeaturesChart('woa-all-features-chart-canvas', resultData.WOA['All Detected Features']);
            renderAllFeaturesChart('ewoa-all-features-chart-canvas', resultData.EWOA['All Detected Features']);

        } catch(e) { 
            console.error('Failed to display results:', e); 
            showError('Failed to render results: ' + e.message);
        }
    }

    // === Destroy Charts ===
    function destroyAllCharts() {
      Object.values(activeCharts).forEach(chart => {
          if (chart) chart.destroy();
      });
      activeCharts = {};
    }

    // === UPDATED: Render Contributor Table ===
    function renderContributorTable(tableBodyId, features) {
        const tfc = Array.isArray(features) ? features : [];
        const tableBody = document.getElementById(tableBodyId);
        if (tableBody) {
            const rows = tfc.map(([name, value]) => {
                const prettyName = PRETTY_NAMES[name] || name;
                return `<tr>
                          <td>${escapeHTML(prettyName)} <span class="subtle-name">(${escapeHTML(name)})</span></td>
                          <td class="mono ${Number(value) < 0 ? 'value-malignant' : 'value-benign'}">${Number(value).toFixed(6)}</td>
                        </tr>`;
            }).join('') || '<tr><td colspan="2">No features found</td></tr>';
            tableBody.innerHTML = rows;
        }
    }
    
    // === UPDATED: Render Grouped Bar Chart ===
    function renderMetricsChart(result) {
        const cv = document.getElementById('metrics-comparison-chart');
        if (!cv) return;
        if (activeCharts['metrics-comparison-chart']) activeCharts['metrics-comparison-chart'].destroy();
        
        const woaData = [ Number(result.WOA['Execution Time'] || 0) ];
        const ewoaData = [ Number(result.EWOA['Execution Time'] || 0) ];

        activeCharts['metrics-comparison-chart'] = new Chart(cv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Exec. Time (s)'],
                datasets: [
                    {
                        label: 'WOA',
                        data: woaData,
                        backgroundColor: 'rgba(127,140,141,0.7)', // --text-dark
                        borderColor: 'rgba(127,140,141,1)',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'EWOA',
                        data: ewoaData,
                        backgroundColor: chartColors.accentGlow,
                        borderColor: chartColors.accentGlow.replace('0.7', '1'),
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, grid: { color: chartColors.borderColor }, ticks: { color: chartColors.textHeader } },
                    x: { grid: { color: chartColors.borderColor }, ticks: { color: chartColors.textHeader } }
                },
                plugins: {
                    legend: { position: 'top', labels: { color: chartColors.textHeader } },
                    tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(4)} s` } }
                }
            }
        });
    }

    // === UPDATED: Render Features Chart (Horizontal Bar) ===
    function renderFeaturesChart(canvasId, features, direction = 'malignant') {
        const cv = document.getElementById(canvasId);
        if (!cv) return;
        if (activeCharts[canvasId]) activeCharts[canvasId].destroy();

        let sortedFeatures = Array.isArray(features) ? [...features] : [];

        if (direction === 'malignant') {
            sortedFeatures = sortedFeatures.filter(f => f[1] < 0).sort((a, b) => a[1] - b[1]);
        } else {
            sortedFeatures = sortedFeatures.filter(f => f[1] > 0).sort((a, b) => b[1] - a[1]);
        }

        const topFeatures = sortedFeatures.reverse(); // Reverse for Chart.js

        if (topFeatures.length === 0) {
            const ctx = cv.getContext('2d');
            ctx.clearRect(0, 0, cv.width, cv.height);
            ctx.fillStyle = chartColors.textDark;
            ctx.textAlign = 'center';
            ctx.fillText(`No ${direction} features found.`, cv.width / 2, cv.height / 2);
            return;
        }

        const labels = topFeatures.map(f => PRETTY_NAMES[f[0]] || f[0]);
        const data = topFeatures.map(f => f[1]);
        const bgColors = topFeatures.map((_, i) => chartColors.pastels[i % chartColors.pastels.length]);
        const borderColors = bgColors.map(c => c.replace('0.7', '1'));
        const chartHeight = Math.max(300, topFeatures.length * 18);
        cv.parentElement.style.height = `${chartHeight}px`;

        activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Contribution',
                    data: data,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: chartColors.borderColor }, ticks: { color: chartColors.textHeader, font: { size: 10 } } },
                    y: { grid: { color: 'transparent' }, ticks: { color: chartColors.textHeader, font: { size: 10 } } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ` Contribution: ${ctx.parsed.x.toFixed(6)}` } }
                }
            }
        });
    }

    // +++ NEW: Render All Features Chart (Horizontal Bar) +++
    function renderAllFeaturesChart(canvasId, features) {
        const cv = document.getElementById(canvasId);
        if (!cv) return;
        if (activeCharts[canvasId]) activeCharts[canvasId].destroy();

        let sortedFeatures = Array.isArray(features) ? [...features] : [];
        sortedFeatures = sortedFeatures.sort((a, b) => b[1] - a[1]);

        if (sortedFeatures.length === 0) {
            const ctx = cv.getContext('2d');
            ctx.clearRect(0, 0, cv.width, cv.height);
            ctx.fillStyle = chartColors.textDark;
            ctx.textAlign = 'center';
            ctx.fillText(`No features found.`, cv.width / 2, cv.height / 2);
            return;
        }

        const labels = sortedFeatures.map(f => PRETTY_NAMES[f[0]] || f[0]);
        const data = sortedFeatures.map(f => f[1]);
        const bgColors = data.map(val => (val >= 0 ? chartColors.accentSuccess : chartColors.accentWarning));
        const borderColors = bgColors.map(c => c.replace('0.7', '1'));
        const chartHeight = Math.max(300, sortedFeatures.length * 18);
        cv.parentElement.style.height = `${chartHeight}px`;

        activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Contribution',
                    data: data,
                    backgroundColor: bgColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    borderRadius: 2
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: chartColors.borderColor }, ticks: { color: chartColors.textHeader, font: { size: 10 } } },
                    y: { grid: { color: 'transparent' }, ticks: { color: chartColors.textHeader, font: { size: 9 } } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ` Contribution: ${ctx.parsed.x.toFixed(6)}` } }
                }
            }
        });
    }

    // === Modal Logic ===
    function openModal(title, contentHtml) {
      modalTitle.textContent = title;
      modalBody.innerHTML = contentHtml;
      modalOverlay.classList.add('visible');
      document.body.style.overflow = 'hidden';

      // Re-render charts in modal
      try {
          const resultData = window__PREDICT__?.result;
          if (resultData) {
              // Check for metrics chart
              const metricsCanvas = modalBody.querySelector('#metrics-comparison-chart');
              if (metricsCanvas && activeCharts['metrics-comparison-chart']) {
                  activeCharts['modal_instance_metrics'] = new Chart(metricsCanvas.getContext('2d'), activeCharts['metrics-comparison-chart'].config);
              }
              // ... (add logic for other charts if needed, copying from original) ...
              const woaMalignantCanvas = modalBody.querySelector('#woa-top-malignant-chart-canvas');
              if (woaMalignantCanvas && activeCharts['woa-top-malignant-chart-canvas']) {
                  activeCharts['modal_instance_woa_mal'] = new Chart(woaMalignantCanvas.getContext('2d'), activeCharts['woa-top-malignant-chart-canvas'].config);
              }
              const ewoaMalignantCanvas = modalBody.querySelector('#ewoa-top-malignant-chart-canvas');
              if (ewoaMalignantCanvas && activeCharts['ewoa-top-malignant-chart-canvas']) {
                  activeCharts['modal_instance_ewoa_mal'] = new Chart(ewoaMalignantCanvas.getContext('2d'), activeCharts['ewoa-top-malignant-chart-canvas'].config);
              }
              const woaBenignCanvas = modalBody.querySelector('#woa-top-benign-chart-canvas');
              if (woaBenignCanvas && activeCharts['woa-top-benign-chart-canvas']) {
                  activeCharts['modal_instance_woa_ben'] = new Chart(woaBenignCanvas.getContext('2d'), activeCharts['woa-top-benign-chart-canvas'].config);
              }
              const ewoaBenignCanvas = modalBody.querySelector('#ewoa-top-benign-chart-canvas');
              if (ewoaBenignCanvas && activeCharts['ewoa-top-benign-chart-canvas']) {
                  activeCharts['modal_instance_ewoa_ben'] = new Chart(ewoaBenignCanvas.getContext('2d'), activeCharts['ewoa-top-benign-chart-canvas'].config);
              }
              const woaAllFeatCanvas = modalBody.querySelector('#woa-all-features-chart-canvas');
              if (woaAllFeatCanvas && activeCharts['woa-all-features-chart-canvas']) {
                  activeCharts['modal_instance_woa_all'] = new Chart(woaAllFeatCanvas.getContext('2d'), activeCharts['woa-all-features-chart-canvas'].config);
              }
              const ewoaAllFeatCanvas = modalBody.querySelector('#ewoa-all-features-chart-canvas');
              if (ewoaAllFeatCanvas && activeCharts['ewoa-all-features-chart-canvas']) {
                  activeCharts['modal_instance_ewoa_all'] = new Chart(ewoaAllFeatCanvas.getContext('2d'), activeCharts['ewoa-all-features-chart-canvas'].config);
              }
          }
      } catch (e) {
          console.error("Error re-rendering charts in modal:", e);
      }
    }

    function closeCardModal() {
        modalOverlay.classList.remove('visible');
        document.body.style.overflow = '';
        Object.keys(activeCharts).forEach(key => {
            if (key.startsWith('modal_instance')) {
                activeCharts[key].destroy();
                delete activeCharts[key];
            }
        });
        modalBody.innerHTML = '';
    }
    
    closeModalBtn.addEventListener('click', closeCardModal);
    modalOverlay.addEventListener('click',e=>{ if(e.target===modalOverlay) closeCardModal(); });
    
    // Delegated click listener for maximize buttons
    resultsContainer.addEventListener('click', e => {
        const btn = e.target.closest('.maximize-card-btn');
        if (!btn) return;
        const card = btn.closest('.step-card');
        if (!card) return;
        const title = card.querySelector('h2')?.textContent.trim() || 'Details';
        const content = card.querySelector('.card-content');
        if (content) {
            openModal(title, content.innerHTML);
        }
    });

    // === Details Toggle (for old history, if you keep it) ===
    document.addEventListener('click', (event) => {
      const button = event.target.closest('.details-toggle-btn');
      if (!button) return;
      const targetSelector = button.dataset.target;
      const content = document.querySelector(targetSelector);
      if (!content) return;

      button.classList.toggle('active');
      if (content.style.maxHeight && content.style.maxHeight !== '0px') {
        content.style.maxHeight = null;
        button.textContent = button.textContent.replace('Hide', 'Show');
      } else {
        content.style.maxHeight = content.scrollHeight + 'px';
        button.textContent = button.textContent.replace('Show', 'Hide');
      }
    });

    // === Utility ===
    function escapeHTML(s) { return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }

    // --- NEW: Initialization on page load ---
    renderHistory(); // Render history from localStorage
    
    const stored = loadState();
    if (stored?.result) {
        console.log("Loading last state from localStorage");
        displayResults(stored.result, stored.imagePath);
        
        if (stored.imagePath) {
            previewImg.src = stored.imagePath;
            previewWrapper.style.display = 'flex';
            uploadArea.style.display = 'none';
            fileMetaText.textContent = stored.filename || 'image';
            fileMetaText.style.display = 'block';
            runButton.disabled = false;
            resetButton.disabled = false;
            btnText.textContent = 'Re-run Comparison';
        }
    } else {
        // No stored state, ensure placeholder is visible
        placeholderCard.style.display = 'block';
        resultsContainer.style.display = 'none';
    }

    // Handle PHP-based error (non-JS fallback)
    <?php if ($error): ?>
    showError(<?= json_encode($error) ?>);
    <?php endif; ?>

  });
  </script>
</body>
</html>



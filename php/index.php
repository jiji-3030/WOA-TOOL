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
function get_workdir() {
    global $config;
    return $config['workdir'];
}

function build_predict_cmd($imagePath) {
    global $config;
    $python  = escapeshellcmd($config['python_path']);
    $workdir = escapeshellarg($config['workdir']);
    $model   = escapeshellarg($config['workdir'] . '/models/model.json');
    $image   = escapeshellarg($imagePath);
    return "PYTHONPATH=$workdir $python -m woa_tool.cli predict --model $model --image $image";
}

// === Standard PHP Setup ===
ob_start();
ini_set('display_errors', 1); // Set to 0 in production
error_reporting(E_ALL);

$result = null;
$error  = null;
$uploadedImageWebPath = null;
$isDebug = isset($_GET['debug']); // Add a debug flag

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {

    // Basic validation
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error code: " . $_FILES['image']['error'];
    } elseif ($_FILES['image']['size'] == 0) {
        $error = "Uploaded file is empty.";
    } elseif (!is_uploaded_file($_FILES['image']['tmp_name'])) {
         $error = "Possible file upload attack.";
    } else {
        $fileName   = uniqid('img_', true) . '-' . preg_replace('/[^A-Za-z0-9\.\-\_]/', '', basename($_FILES['image']['name'])); // Sanitize filename
        $targetPath = $upload_dir . '/' . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            $uploadedImageWebPath = 'test_uploads/' . basename($targetPath); // Web path relative to index.php

            // --- Real Prediction Logic ---
            if (empty($_POST['mock'])) { // Only run if not mocking
                $cmd = build_predict_cmd($targetPath);
                $desc = [ 0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w'], ];
                $proc = proc_open($cmd, $desc, $pipes, get_workdir());

                if (is_resource($proc)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code   = proc_close($proc);

                    $decoded = json_decode($stdout, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $result = $decoded;
                    } else {
                        $error = "Model did not return valid JSON.";
                        if ($isDebug) {
                             $error .= "<br><b>Exit code:</b> $code"
                                    . "<br><b>STDERR:</b><pre>" . htmlspecialchars($stderr ?: '(empty)') . "</pre>"
                                    . "<br><b>STDOUT (first 500 chars):</b><pre>" . htmlspecialchars(substr($stdout,0,500)) . "</pre>"
                                    . "<br><b>CMD:</b><pre>" . htmlspecialchars($cmd) . "</pre>";
                        }
                    }
                } else {
                    $error = "proc_open failed — shell execution issue?";
                }
            }
            // --- End Real Prediction ---

        } else {
            $error = "Failed to move uploaded file. Check permissions for '$upload_dir'. Error code: " . ($_FILES['image']['error'] ?? 'unknown');
        }
    }
}

// === MOCK DATA FOR UI TESTING (AJAX ONLY) ===
if (!empty($_POST['ajax']) && !empty($_POST['mock'])) { // Check for mock flag specifically for AJAX
    $noise = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    sleep(1); // Simulate delay
    $mock = [
      "ok" => true,
      "result" => [
        "final_prediction" => "Malignant",
        "probabilities" => ["Benign" => 0.234, "Malignant" => 0.766],
        "abnormality_type" => "Mass with Spiculation",
        "abnormality_scores" => ["glcm_contrast" => 0.206, "glcm_correlation" => 4.25, "texture_variance" => 1.89],
        "explanation" => ["class" => ["High texture variance and GLCM contrast contributed..."], "abnormality" => ["Features consistent with a spiculated mass."]],
        "background_tissue" => ["code" => "C", "text" => "Heterogeneously Dense", "explain" => "May obscure small masses."],
        "top_feature_contributors" => [ ["glcm_correlation", 0.45], ["texture_variance", 0.30], ["glcm_contrast", 0.15], ["shape_circularity", 0.05], ["asymmetry", 0.02] ],
      ], "error" => null, "noise" => $noise ?: null ];
    echo json_encode($mock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// === AJAX Response (Real or Error) ===
if (!empty($_POST['ajax'])) {
    $noise = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'     => (bool)$result && !$error, // OK is true only if result exists AND there's no error
        'result' => $result,
        'error'  => $error,
        'noise'  => $isDebug ? ($noise ?: null) : null, // Only show PHP noise if debugging
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
// === END AJAX Handling ===

// Prepare data for initial page load (non-AJAX, potentially after form submit without JS)
$jsonData = $result ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
// Clean buffer for HTML output
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1"/>
  <title>WOA & EWOA Breast Cancer Feature Detection</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- === CHANGED === Bumping version number to force reload -->
  <link rel="stylesheet" href="style.css?v=21">
  <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    window.__PREDICT__ = <?php echo $jsonData; ?>;
    window.__UPLOADED_IMAGE__ = <?php echo json_encode($uploadedImageWebPath ?: null); ?>;
  </script>
</head>
<body>
  <!-- === Navigation Bar === -->
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
      <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a>
      <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a>
      <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
    </nav>
  </div>
</header>


  <div id="aurora-background"></div>

  <div class="main-container">
    <header class="header">
      <h1>
        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
          <path d="M12 2C10.14 2 8.5 3.65 8.5 5.5C8.5 6.4 8.89 7.2 9.5 7.82C7.03 8.35 5.3 10.13 5.3 12.39C5.3 13.53 5.79 14.58 6.6 15.35C5.59 16.32 5 17.58 5 19C5 21.21 6.79 23 9 23C10.86 23 12.5 21.35 12.5 19.5C12.5 18.6 12.11 17.8 11.5 17.18C13.97 16.65 15.7 14.87 15.7 12.61C15.7 11.47 15.21 10.42 14.4 9.65C15.41 8.68 16 7.42 16 6C16 3.79 14.21 2 12 2M12 4C13.1 4 14 4.9 14 6C14 7.03 13.2 7.9 12.18 7.97C12.12 7.99 12.06 8 12 8C10.9 8 10 7.1 10 6C10 4.9 10.9 4 12 4M9 21C7.9 21 7 20.1 7 19C7 17.97 7.8 17.1 8.82 17.03C8.88 17.01 8.94 17 9 17C10.1 17 11 17.9 11 19C11 20.1 10.1 21 9 21" />
        </svg>
        WOA & EWOA Breast Cancer Feature Detection
      </h1>
      <div class="quick-guide">
        <h3>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
          Quick Start Guide
        </h3>
        <ul>
          <li><strong>Step 1:</strong> Upload mammogram (<code>.png</code>, <code>.jpg</code>, <code>.tif</code>).</li>
          <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong>.</li>
          <li><strong>Step 3:</strong> View results.</li>
        </ul>
      </div>
    </header>

    <div class="left-column">
        <div class="step-card">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number">1</div>
              <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg> Upload Image</h2>
            </div>
            <span class="tooltip-icon">i<span class="tooltip-content">Accepted formats: .tif, .tiff, .png, .jpg, .jpeg. Size limit depends on server config.</span></span>
          </div>
          <form id="image-upload-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="mock" value="1">
            <div id="image-preview-wrapper" style="display: none;">
              <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button>
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
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number">2</div>
              <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" /></svg> Run Analysis</h2>
            </div>
          </div>
          <p style="color:var(--text-dark); margin-bottom: 2rem;">Once image selected, button active.</p>
          <button class="btn" type="submit" id="submit-btn" disabled form="image-upload-form">
            <span id="btn-text">Run Prediction</span>
            <div class="spinner" id="spinner" style="display:none;"></div>
          </button>
          <button class="btn btn-secondary" type="button" id="clear-btn" style="margin-top:0; margin-left:.75rem; display: none;">↺ Reset</button>
        </div>

        <div id="error-container"></div>
    </div> <div class="right-column">

<div id="results-placeholder" style="display: block;">
            <div class="step-card placeholder-card single-placeholder">
          
              <div class="step-header">
                <div class="step-header-left">
             
                  <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 20px; height: 20px; color: white;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                      </svg>
                  </div>
                  <h2>Results Preview</h2>
                </div>
              </div>
         
              <div class="placeholder-content">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 1.085-1.085-1.085m1.085 1.085L5.25 16.5m7.5 0l-1 1.085m0 0l-1.085-1.085m1.085 1.085L18.75 16.5m-7.5 2.25h.008v.008H11.25v-.008zM12 3.75h.008v.008H12V3.75z" />
                  </svg>
                  <p>Analysis results will be displayed here after running the prediction.</p>
              </div>
            </div>
        </div>



<div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;">
  <div class="step-card loader-card">
    <div class="loader-inner">
      <div class="scan-loader">
        <span></span><span></span><span></span><span></span>
      </div>
      <p class="loader-caption">Analyzing mammogram... please wait</p>
    </div>
  </div>
</div>

        <div class="results-container animate-slide-up" id="results-container" style="display:none;">
           <div class="step-card">
              <div class="step-header">
                 <div class="step-header-left">
                    <div class="step-number">3</div>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> View Results</h2>
                 </div>
                 <div class="header-buttons">
                    <button type="button" class="btn btn-print" id="print-btn" title="Print Report">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6 18.233m0 0c.091.341.31.65.597.872l7.18 4.247a1.13 1.13 0 001.28 0l7.18-4.247a1.13 1.13 0 00.597-.872M6 18.233L6.72 13.83m0 0a42.28 42.28 0 004.584 1.284a42.28 42.28 0 004.584-1.284m0 0L18 18.233m0 0c.091.341.31.65.597.872l7.18 4.247a1.13 1.13 0 001.28 0l7.18-4.247a1.13 1.13 0 00.597-.872M18 18.233L17.28 13.83m-10.56 0c.24.03.48.062-.72.096m0 0a42.28 42.28 0 005.28 0m5.28 0a42.28 42.28 0 005.28 0m0 0c.24.03.48.062.72.096m-10.56 0a42.415 42.415 0 0110.56 0m0 0L12 5.132m0 0L6.72 13.83m5.28-8.698a42.415 42.415 0 010 17.396" /></svg>
                        <span>Print Report</span>
                    </button>
                    <button type="button" class="btn btn-csv" id="csv-btn" title="Download CSV">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        <span>Download CSV</span>
                    </button>
                 </div>
              </div>
              <div class="results-grid" id="results-grid">
                  <div class="step-card prediction-card animate-slide-up" id="prediction-card-content">
                    <div class="step-header"><div class="step-header-left"><h2 style="padding-left:0;">Final Prediction</h2></div><button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button></div>
                    <div class="card-content">
                       <div class="prediction-text-wrapper">
                         <span class="prediction-indicator"></span>
                         <span style="font-size:2.5rem; font-weight:800;" data-field="final_prediction"></span>
                       </div>
                      <div class="confidence-bar-wrapper">
                        <div class="confidence-bar-bg">
                          <div id="confidence-fill"></div>
                        </div>
                        <p id="confidence-label">Confidence: —</p>
                      </div>
                    </div>
                  </div>
                  
                  <div class="step-card animate-slide-up" id="probability-card-content" style="animation-delay:.1s;">
                    <div class="step-header"><div class="step-header-left"><h2>Probabilities</h2></div><span class="tooltip-icon">i<span class="tooltip-content">Model confidence per class.</span></span><button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button></div>
                    <div class="card-content"><div id="probability-chart-container"><canvas id="probability-chart"></canvas></div></div>
                  </div>
                  <div class="step-card animate-slide-up" id="abnormality-card-content" style="animation-delay:.15s;">
                    <div class="step-header"><div class="step-header-left"><h2>Abnormality</h2></div><button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button></div>
                    <div class="card-content"><table class="data-table" style="margin-bottom: 1.5rem;"><tr><th><span class="tooltip-trigger" data-tooltip="Primary abnormality type.">Type<span class="tooltip-content">Primary abnormality type.</span></span></th><td data-field="abnormality_type"></td></tr></table><h3 style="font-size: 1.2rem; color: var(--text-light); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">Key Feature Scores<span class="tooltip-icon" style="font-weight: 400;">i<span class="tooltip-content">Feature scores.</span></span></h3><div style="height: 250px;"><canvas id="abnormality-chart"></canvas></div></div>
                  </div>
                  
                  <div class="step-card animate-slide-up" id="background-card-content" style="animation-delay:.2s;">
                    <div class="step-header"><div class="step-header-left"><h2>Background</h2></div><button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button></div>
                    <div class="card-content"><table class="data-table"><tr><th><span class="tooltip-trigger" data-tooltip="BI-RADS density code.">Density<span class="tooltip-content">BI-RADS density code.</span></span></th><td data-field="background_tissue_density"></td></tr><tr><th>Explain</th><td data-field="background_tissue_explain"></td></tr></table></div>
                  </div>
                  <div class="step-card animate-slide-up" id="explanation-card-content" style="animation-delay:.25s;">
                    <div class="step-header"><div class="step-header-left"><h2>Explanations</h2></div><span class="tooltip-icon">i<span class="tooltip-content">AI explanations.</span></span><button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button></div>
                    <div class="card-content"><table class="data-table"><tr><th>Class-based</th><td data-field="explanation_class"></td></tr><tr><th>Abnormality-based</th><td data-field="explanation_abnormality"></td></tr></table></div>
                  </div>
                  
                  <div class="wide-card-container animate-slide-up" style="animation-delay:.28s;">
            
<div class="step-card" id="top-features-card-content">
 <div class="step-header">
 <div class="step-header-left"><h2>Top Contributors</h2></div>
 <span class="tooltip-icon">i<span class="tooltip-content">Top features & contribution %.</span></span>
<button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button>
</div>
<div class="card-content">
<table class="data-table">
<thead><tr><th>Feature</th><th>Contribution</th></tr></thead>
<tbody data-field="top_feature_contributors"></tbody>
 </table>
 </div>
 </div>

 <div class="step-card" id="feature-signature-card">
                  <div class="step-header">
                    <div class="step-header-left">
                      <h2>Feature Signature Map</h2>
                    </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Visualizes the pattern of key texture and shape metrics.</span></span>
                    <button type="button" class="maximize-card-btn" title="Maximize"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/></svg></button>
                  </div>
                  <div class="card-content">
                    <canvas id="feature-signature-chart"></canvas>
                    <p class="chart-note">Visualizes the pattern of key texture and shape metrics.</p>
                  </div>
                </div>
            
            </div> 
             </div>
</div>
                  
          

        <?php if ($result && !$isDebug): ?>
            <div class="step-card" style="margin-top:1rem;">
               <h2 style="text-align:left;">Full Result (Tabular)</h2>
               <pre><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT)); ?></pre>
            </div>
        <?php endif; ?>
    </div>
  </div>

  <footer>
    <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p>
  </footer>

  <div id="image-modal-overlay"></div>
  <div id="card-modal-overlay">
      <div id="card-modal-content">
         <button class="close-modal-btn">&times;</button>
         <h2 class="modal-title"></h2>
         <div class="modal-body"></div>
      </div>
   </div>

   <!-- 
    ================================================================
    === SCRIPT BLOCK: All fixes are in this section ===
    ================================================================
   -->
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
        let activeCharts = [];
        let currentMaximizedChartId = null;

        // === Get Computed CSS Colors ===
        const computedStyles = getComputedStyle(document.documentElement);
        const chartColors = {
            accentGlow: computedStyles.getPropertyValue('--accent-glow').trim(), accentGlowTint: computedStyles.getPropertyValue('--accent-glow-tint').trim(), accentSuccess: computedStyles.getPropertyValue('--accent-success').trim(), accentWarning: computedStyles.getPropertyValue('--accent-warning').trim(), textDark: computedStyles.getPropertyValue('--text-dark').trim(), borderColor: computedStyles.getPropertyValue('--border-color').trim(),
        };

        // === Functions ===
        function showError(message) {
             errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${message}</div>`;
        }
        function renderToCanvas(file) {
             return new Promise((resolve, reject) => { const isTiff = file.type === 'image/tiff' || file.name.toLowerCase().endsWith('.tif') || file.name.toLowerCase().endsWith('.tiff'); const reader = new FileReader(); if (isTiff) { reader.onload = e => { try { Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 }); const tiff = new Tiff({ buffer: e.target.result }); resolve(tiff.toCanvas()); } catch (err) { reject(err); } }; reader.onerror = reject; reader.readAsArrayBuffer(file); } else { reader.onload = e => { const img = new Image(); img.onload = () => { const canvas = document.createElement('canvas'); canvas.width = img.width; canvas.height = img.height; canvas.getContext('2d').drawImage(img, 0, 0); resolve(canvas); }; img.onerror = reject; img.src = e.target.result; }; reader.onerror = reject; reader.readAsDataURL(file); } });
        }
        function scaleCanvasToFit(srcCanvas, maxW, maxH) {
             const w = srcCanvas.width, h = srcCanvas.height; const scale = Math.min(maxW / w, maxH / h, 1); const out = document.createElement('canvas'); out.width  = Math.round(w * scale); out.height = Math.round(h * scale); out.getContext('2d').drawImage(srcCanvas, 0, 0, out.width, out.height); return out;
        }
        function displayCanvas(canvas, containerElement) {
             const existingCanvas = containerElement.querySelector('canvas'); if (existingCanvas) existingCanvas.remove(); containerElement.prepend(canvas); containerElement.style.display = 'flex';
        }
        function handleFileSelect() {
              if (fileInput.files.length > 0) { const file = fileInput.files[0]; renderToCanvas(file).then(rawCanvas => { const maxW = previewWrapper.clientWidth || 900; const maxH = 400; const scaled = scaleCanvasToFit(rawCanvas, maxW, maxH); previewWrapper.dataset.fullImage = rawCanvas.toDataURL(); displayCanvas(scaled, previewWrapper); const nameEl = document.getElementById('image-filename'); if (nameEl) { nameEl.textContent = file.name; nameEl.style.display = 'block'; } submitBtn.disabled = false; clearBtn.style.display = 'inline-flex'; uploadArea.style.display = 'none'; }).catch(err => { console.error(err); showError('Could not read or render image.'); }); }
        }
        function showContentInModal(title, contentHtml, chartId = null) {
             cardModalTitle.textContent = title; cardModalBody.innerHTML = contentHtml; cardModalOverlay.classList.add('visible'); document.body.style.overflow = 'hidden';
             currentMaximizedChartId = chartId;
             if (chartId) { const resultData = window.__PREDICT__?.result; if (!resultData) return;
                 if (chartId === 'probability-chart') { const probs = resultData.probabilities || {}; const benignProbRaw = probs['Benign'] || 0; const malignantProbRaw = probs['Malignant'] || 0; const probCtxModal = cardModalBody.querySelector('#probability-chart').getContext('2d'); new Chart(probCtxModal, { type: 'pie', data: { labels: ['Benign', 'Malignant'], datasets: [{ data: [benignProbRaw, malignantProbRaw], backgroundColor: [chartColors.accentSuccess, chartColors.accentWarning], borderColor: '#fff', borderWidth: 2 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: chartColors.textDark, font: { size: 16 }, generateLabels: function(chart) { const data = chart.data; if (data.labels.length && data.datasets.length) { const { labels: { pointStyle } } = chart.legend.options; return data.labels.map((label, i) => { const ds = data.datasets[0]; const value = ds.data[i]; const total = ds.data.reduce((a, b) => a + b, 0); const percentage = (total > 0) ? ((value / total) * 100).toFixed(1) : (0).toFixed(1); return { text: `${label}: ${percentage}%`, fillStyle: ds.backgroundColor[i], strokeStyle: ds.borderColor[i], lineWidth: ds.borderWidth, pointStyle: pointStyle, hidden: !chart.getDataVisibility(i), index: i }; }); } return []; } } }, tooltip: { callbacks: { label: function(context) { let label = context.label || ''; if (label) { label += ': '; } if (context.parsed !== null) { label += (context.parsed * 100).toFixed(1) + '%'; } return label; } } } } } }); }
                 else if (chartId === 'abnormality-chart') { const abnScores = resultData.abnormality_scores || {}; const abnCtxModal = cardModalBody.querySelector('#abnormality-chart').getContext('2d'); new Chart(abnCtxModal, { type: 'bar', data: { labels: Object.keys(abnScores), datasets: [{ label: 'Score', data: Object.values(abnScores), backgroundColor: chartColors.accentGlowTint, borderColor: chartColors.accentGlow, borderWidth: 2, borderRadius: 4, }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, ticks: { color: chartColors.textDark, font: { size: 14 } }, grid: { color: chartColors.borderColor } }, y: { ticks: { color: chartColors.textDark, font: { size: 14 } }, grid: { color: 'transparent' } } }, plugins: { legend: { display: false } } } }); }
                 // === This block is still correct from last time ===
                 else if (chartId === 'feature-signature-chart') {
                    const featSigCtxModal = cardModalBody.querySelector('#feature-signature-chart').getContext('2d');
                    // We get the data from the same global variable
                    const radarLabels = ['GLCM Contrast', 'GLCM Correlation', 'Texture Variance', 'Asymmetry', 'Circularity'];
                    const radarValues = [
                      resultData.abnormality_scores?.glcm_contrast ?? 0.2,
                      resultData.abnormality_scores?.glcm_correlation ?? 0.4,
                      resultData.abnormality_scores?.texture_variance ?? 0.3,
                      Math.random() * 0.5 + 0.2, // This is still the placeholder logic
                      Math.random() * 0.5 + 0.2  // This is still the placeholder logic
                    ];
                    new Chart(featSigCtxModal, {
                      type: 'radar',
                      data: {
                        labels: radarLabels,
                        datasets: [{
                          label: 'Feature Pattern',
                          data: radarValues,
                          backgroundColor: chartColors.accentGlowTint,
                          borderColor: chartColors.accentGlow,
                          borderWidth: 2,
                          pointBackgroundColor: chartColors.accentGlow
                        }]
                      },

                      options: {
  responsive: true,
  maintainAspectRatio: false,
  layout: { padding: 20 },
  scales: {
    r: {
      min: 0,
      max: 1,
      ticks: { display: false },
      grid: { color: '#DDE4E8' },
      angleLines: { color: '#DDE4E8' },
      pointLabels: { color: '#34495E', font: { size: 13 } }
    }
  },
  plugins: { legend: { display: false } }
}

                    });
                }
                 // === END Correct Modal Block ===
             }
        }
        function closeCardModal() {
             cardModalOverlay.classList.remove('visible'); document.body.style.overflow = ''; cardModalBody.innerHTML = ''; currentMaximizedChartId = null;
        }
        
        function downloadCSV(resultData) {
            let csvContent = "Category,Parameter,Value\r\n";
            const escapeCSV = (str) => {
                if (str === null || str === undefined) return '';
                let result = String(str);
                if (result.includes(',') || result.includes('"') || result.includes('\n')) {
                    result = '"' + result.replace(/"/g, '""') + '"';
                }
                return result;
            };

            csvContent += `Prediction,final_prediction,${escapeCSV(resultData.final_prediction)}\r\n`;

            if (resultData.probabilities) {
                for (const [key, value] of Object.entries(resultData.probabilities)) {
                    csvContent += `Probability,${escapeCSV(key)},${escapeCSV(value)}\r\n`;
                }
            }

            csvContent += `Abnormality,type,${escapeCSV(resultData.abnormality_type)}\r\n`;

            if (resultData.abnormality_scores) {
                for (const [key, value] of Object.entries(resultData.abnormality_scores)) {
                    csvContent += `Abnormality Score,${escapeCSV(key)},${escapeCSV(value)}\r\n`;
                }
            }
            if (resultData.background_tissue) {
                csvContent += `Background,code,${escapeCSV(resultData.background_tissue.code)}\r\n`;
                csvContent += `Background,text,${escapeCSV(resultData.background_tissue.text)}\r\n`;
                csvContent += `Background,explanation,${escapeCSV(resultData.background_tissue.explain)}\r\n`;
            }

            if (resultData.explanation) {
                if (resultData.explanation.class) csvContent += `Explanation,class,${escapeCSV(resultData.explanation.class.join('; '))}\r\n`;
                if (resultData.explanation.abnormality) csvContent += `Explanation,abnormality,${escapeCSV(resultData.explanation.abnormality.join('; '))}\r\n`;
            }

            if (resultData.top_feature_contributors) {
                resultData.top_feature_contributors.forEach(([name, value]) => {
                    csvContent += `Feature Contribution,${escapeCSV(name)},${escapeCSV(value)}\r\n`;
                });
            }

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                const timestamp = new Date().toISOString().replace(/:/g, '-').slice(0, 19);
                link.setAttribute("href", url);
                link.setAttribute("download", `prediction_results_${timestamp}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        // === Event Listeners ===
        uploadArea.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', handleFileSelect);
        ['dragenter','dragover','dragleave','drop'].forEach(ev => { uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false); });
        ['dragenter','dragover'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover'), false); });
        ['dragleave','drop'].forEach(ev => { uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover'), false); });
        uploadArea.addEventListener('drop', e => { fileInput.files = e.dataTransfer.files; handleFileSelect(); });

        form.addEventListener('submit', async e => {
             e.preventDefault(); submitBtn.disabled = true; spinner.style.display = 'block'; btnText.textContent = 'Analyzing...'; skeletonLoader.style.display = 'block'; resultsContainer.style.display = 'none'; resultsPlaceholder.style.display = 'none'; errorContainer.innerHTML = '';
             if (activeCharts.length > 0) { activeCharts.forEach(c => c.destroy()); activeCharts = []; }
             try { const formData = new FormData(form); formData.set('ajax', '1'); const useMock = form.querySelector('[name="mock"]')?.value === '1'; if (useMock) { formData.set('mock', '1'); }
                 const response = await fetch(window.location.href, { method: 'POST', body: formData }); const contentType = response.headers.get('content-type') || '';
                 if (!response.ok) { const text = await response.text(); throw new Error(`HTTP ${response.status}\n\n${text.slice(0, 2000)}`); }
                 if (!contentType.includes('application/json')) { const text = await response.text(); if (text.includes("POST Content-Length")) { throw new Error("File too large."); } throw new Error(`Expected JSON, got HTML/text:\n\n${text.slice(0, 500)}...`); }
                 const payload = await response.json(); console.log('AJAX payload:', payload);
                 if (payload.ok && payload.result) { window.__PREDICT__ = payload; displayResults(payload.result); const resultsEl = document.getElementById('results-container'); if (resultsEl) { resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
                 else { throw new Error(payload.error || payload.noise || 'Backend error.'); }
             } catch (err) { console.error('Fetch Error:', err); showError(err?.message?.replace(/\n/g, '<br>') || 'Analysis error.'); }
             finally { skeletonLoader.style.display = 'none'; spinner.style.display = 'none'; btnText.textContent = 'Run Prediction'; submitBtn.disabled = false; }
        });

        clearBtn.addEventListener('click', () => {
             fileInput.value = ''; previewWrapper.style.display = 'none'; previewWrapper.removeAttribute('data-full-image'); const existingCanvas = previewWrapper.querySelector('canvas'); if (existingCanvas) existingCanvas.remove(); const nameEl = document.getElementById('image-filename'); if (nameEl) nameEl.style.display = 'none';
             resultsContainer.style.display = 'none'; errorContainer.innerHTML = ''; skeletonLoader.style.display = 'none'; resultsPlaceholder.style.display = 'block';
             btnText.textContent = 'Run Prediction'; submitBtn.disabled = true; clearBtn.style.display = 'none'; uploadArea.style.display = 'block';
             if (activeCharts.length > 0) { activeCharts.forEach(c => c.destroy()); activeCharts = []; } window.__PREDICT__ = null; window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Image Modal
        document.body.addEventListener('click', e => { if (e.target.closest('.maximize-btn')) { const dataURL = document.getElementById('image-preview-wrapper')?.dataset?.fullImage; if (dataURL) showImageInModal(dataURL); } });
        function showImageInModal(dataURL) { const img = new Image(); img.src = dataURL; img.style.maxWidth = '90vw'; img.style.maxHeight = '90vh'; img.style.borderRadius = '12px'; imageModalOverlay.innerHTML = ''; imageModalOverlay.appendChild(img); imageModalOverlay.classList.add('visible'); }
        imageModalOverlay.addEventListener('click', e => { if (e.target === imageModalOverlay) imageModalOverlay.classList.remove('visible'); });

        // Card Modal
        closeCardModalBtn.addEventListener('click', closeCardModal);
        cardModalOverlay.addEventListener('click', e => { if (e.target === cardModalOverlay) closeCardModal(); });

        function displayResults(resultData) {
          resultsContainer.style.display = 'block';
          resultsPlaceholder.style.display = 'none';

          // --- Populate data ---
          const predEl = document.querySelector('#prediction-card-content [data-field="final_prediction"]');
          const indicatorEl = document.querySelector('#prediction-card-content .prediction-indicator');
          const pred = resultData.final_prediction || '—';
          const predClass = pred.toLowerCase();
          const predColor = pred === 'Malignant' ? chartColors.accentWarning : chartColors.accentSuccess;
          
          if (predEl) {
             predEl.textContent = pred;
             predEl.style.color = predColor;
             const parentCard = predEl.closest('.prediction-card');
             if (parentCard) { parentCard.className = `step-card prediction-card animate-slide-up prediction-${predClass}`; }
          }
          
          if (indicatorEl) {
              const benignSVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentSuccess}"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`;
              const malignantSVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${chartColors.accentWarning}"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`;
              indicatorEl.innerHTML = pred === 'Malignant' ? malignantSVG : benignSVG;
          }

          const probs = resultData.probabilities || {};
          const benignProbRaw = probs['Benign'] || 0;
          const malignantProbRaw = probs['Malignant'] || 0;

          const abnType = resultData.abnormality_type || '—';
          const abnScores = resultData.abnormality_scores || {};
          const abnTypeEl = document.querySelector('#abnormality-card-content [data-field="abnormality_type"]');
          if (abnTypeEl) abnTypeEl.textContent = abnType;

          const bg = resultData.background_tissue || {};
          const bgDensityEl = document.querySelector('#background-card-content [data-field="background_tissue_density"]');
          if (bgDensityEl) bgDensityEl.innerHTML = `<strong>${bg.code ?? '—'}</strong>: ${bg.text ?? '—'}`;
          const bgExplainEl = document.querySelector('#background-card-content [data-field="background_tissue_explain"]');
          if (bgExplainEl) bgExplainEl.textContent = bg.explain ?? '—';

          const classExplain = (Array.isArray(resultData.explanation?.class) && resultData.explanation.class.length > 0) ? resultData.explanation.class.map(e => `${e}`).join('<br>') : '—';
          const classExplainEl = document.querySelector('#explanation-card-content [data-field="explanation_class"]');
          if (classExplainEl) classExplainEl.innerHTML = classExplain;

          const abnExplain = (Array.isArray(resultData.explanation?.abnormality) && resultData.explanation.abnormality.length > 0) ? resultData.explanation.abnormality.map(e => `${e}`).join('<br>') : '—';
          const abnExplainEl = document.querySelector('#explanation-card-content [data-field="explanation_abnormality"]');
          if (abnExplainEl) abnExplainEl.innerHTML = abnExplain;

          const topFeatures = Array.isArray(resultData.top_feature_contributors)
            ? resultData.top_feature_contributors.map(([name, val]) =>
                `<tr><td>${name}</td><td class="mono"><strong>${(val * 100).toFixed(1)}%</strong></td></tr>`
              ).join('')
            : '<tr><td colspan="2">No data</td></tr>';
          const topFeaturesEl = document.querySelector('#top-features-card-content [data-field="top_feature_contributors"]');
          if (topFeaturesEl) {
              topFeaturesEl.innerHTML = topFeatures;
          }

          // --- Build Charts ---
          const probCtx = document.getElementById('probability-chart').getContext('2d');
          const probChart = new Chart(probCtx, {
            type: 'pie', data: { labels: ['Benign', 'Malignant'], datasets: [{ data: [benignProbRaw, malignantProbRaw], backgroundColor: [chartColors.accentSuccess, chartColors.accentWarning], borderColor: '#fff', borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { color: chartColors.textDark, font: { size: 14 }, generateLabels: function(chart) { const data = chart.data; if (data.labels.length && data.datasets.length) { const { labels: { pointStyle } } = chart.legend.options; return data.labels.map((label, i) => { const ds = data.datasets[0]; const value = ds.data[i]; const total = ds.data.reduce((a, b) => a + b, 0); const percentage = (total > 0) ? ((value / total) * 100).toFixed(1) : (0).toFixed(1); return { text: `${label}: ${percentage}%`, fillStyle: ds.backgroundColor[i], strokeStyle: ds.borderColor[i], lineWidth: ds.borderWidth, pointStyle: pointStyle, hidden: !chart.getDataVisibility(i), index: i }; }); } return []; } } }, tooltip: { callbacks: { label: function(context) { let label = context.label || ''; if (label) { label += ': '; } if (context.parsed !== null) { label += (context.parsed * 100).toFixed(1) + '%'; } return label; } } } } }
          });
          activeCharts.push(probChart);

          const abnCtx = document.getElementById('abnormality-chart').getContext('2d');
          const abnChart = new Chart(abnCtx, {
            type: 'bar', data: { labels: Object.keys(abnScores), datasets: [{ label: 'Score', data: Object.values(abnScores), backgroundColor: chartColors.accentGlowTint, borderColor: chartColors.accentGlow, borderWidth: 2, borderRadius: 4, }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, ticks: { color: chartColors.textDark, font: { size: 12 } }, grid: { color: chartColors.borderColor } }, y: { ticks: { color: chartColors.textDark, font: { size: 12 } }, grid: { color: 'transparent' } } }, plugins: { legend: { display: false } } }
          });
          activeCharts.push(abnChart);
          const featSigCtx = document.getElementById('feature-signature-chart').getContext('2d');
const radarLabels = ['GLCM Contrast', 'GLCM Correlation', 'Texture Variance', 'Asymmetry', 'Circularity'];
const radarValues = [
  resultData.abnormality_scores?.glcm_contrast ?? 0.2,
  resultData.abnormality_scores?.glcm_correlation ?? 0.4,
  resultData.abnormality_scores?.texture_variance ?? 0.3,
  Math.random() * 0.5 + 0.2,
  Math.random() * 0.5 + 0.2
];
new Chart(featSigCtx, {
  type: 'radar',
  data: {
    labels: radarLabels,
    datasets: [{
      label: 'Feature Pattern',
      data: radarValues,
      backgroundColor: chartColors.accentGlowTint,
      borderColor: chartColors.accentGlow,
      borderWidth: 2,
      pointBackgroundColor: chartColors.accentGlow
    }]
  },
  options: {
    // === REVERTED CHANGE ===
    // I removed 'maintainAspectRatio: false' from here.
    // The CSS fix handles the layout now.
    scales: {
      r: {
        min: 0,
        max: 1,
        ticks: { display: false },
        grid: { color: chartColors.borderColor },
        angleLines: { color: chartColors.borderColor },
        pointLabels: { color: chartColors.textDark, font: { size: 13 } }
      }
    },
    plugins: { legend: { display: false } }
  }
});

  // 🔹 ADD THIS RADAR CHART HERE
  const radarCtx = document.getElementById('feature-radar')?.getContext('2d');
  if (radarCtx) {
    const features = resultData.top_feature_contributors?.map(f => f[0]) || [];
    const values = resultData.top_feature_contributors?.map(f => f[1]) || [];
    const radarChart = new Chart(radarCtx, {
      type: 'radar',
      data: {
        labels: features,
        datasets: [{
          label: 'Feature Importance',
          data: values.map(v => (v * 100).toFixed(1)),
          backgroundColor: chartColors.accentGlowTint,
          borderColor: chartColors.accentGlow,
          borderWidth: 2,
          pointBackgroundColor: chartColors.accentGlow
        }]
      },
      options: {
        scales: {
          r: {
            angleLines: { color: chartColors.borderColor },
            grid: { color: chartColors.borderColor },
            pointLabels: { color: chartColors.textDark },
            ticks: { display: false }
          }
        },
        plugins: { legend: { display: false } }
      }
    });
    activeCharts.push(radarChart);
  }
  
          // --- Attach maximize button listeners ---
          resultsGrid.querySelectorAll('.maximize-card-btn').forEach(button => {
            const newButton = button.cloneNode(true); button.parentNode.replaceChild(newButton, button);
            newButton.addEventListener('click', (e) => {
              const card = e.target.closest('.step-card[id]');
              if (card && card.id) {
                const cardTitle = card.querySelector('h2')?.textContent.trim() || 'Details';
                const cardContentElement = card.querySelector('.card-content');
                if (cardContentElement) {
                   const cardContentClone = cardContentElement.cloneNode(true);
                   let chartIdInCard = null;
                   if (card.querySelector('#probability-chart')) { chartIdInCard = 'probability-chart'; }
                   else if (card.querySelector('#abnormality-chart')) { chartIdInCard = 'abnormality-chart'; }
                   // This logic is still correct from last time
                   else if (card.querySelector('#feature-signature-chart')) { chartIdInCard = 'feature-signature-chart'; }
                   
                   if (chartIdInCard) { const oldCanvas = cardContentClone.querySelector(`#${chartIdInCard}`); if (oldCanvas) { const newCanvas = document.createElement('canvas'); newCanvas.id = chartIdInCard; oldCanvas.parentNode.replaceChild(newCanvas, oldCanvas); } }
                   showContentInModal(cardTitle, cardContentClone.innerHTML, chartIdInCard);
                }
              }
            });
          });
          
          const printBtn = document.getElementById('print-btn');
          if (printBtn) {
              const newPrintBtn = printBtn.cloneNode(true);
              printBtn.parentNode.replaceChild(newPrintBtn, printBtn);
              newPrintBtn.addEventListener('click', () => window.print());
          }
          
          const csvBtn = document.getElementById('csv-btn');
          if (csvBtn) {
              const newCsvBtn = csvBtn.cloneNode(true);
              csvBtn.parentNode.replaceChild(newCsvBtn, csvBtn);
              newCsvBtn.addEventListener('click', () => downloadCSV(resultData));
          }

        } // End displayResults

        const initialPredictData = window.__PREDICT__;
         if (!initialPredictData || !initialPredictData.result) {
             resultsPlaceholder.style.display = 'block';
             resultsContainer.style.display = 'none';
             skeletonLoader.style.display = 'none';
         } else {
             resultsPlaceholder.style.display = 'none';
             displayResults(initialPredictData.result);
             document.getElementById('clear-btn').style.display = 'inline-flex';
         }
    });
    // === Step Progress Tracker ===
function setActiveStep(step) {
  document.querySelectorAll('.progress-step').forEach((el, i) => {
    el.classList.remove('active', 'completed');
    if (i + 1 < step) el.classList.add('completed');
    if (i + 1 === step) el.classList.add('active');
  });
}

// Example integration points:
fileInput.addEventListener('change', () => setActiveStep(2));
form.addEventListener('submit', async e => {
  setActiveStep(3);
});
// Highlight active link while scrolling
window.addEventListener('scroll', () => {
  const sections = ['feature-detection', 'comparison', 'benchmark'];
  let current = '';
  sections.forEach(id => {
    const section = document.getElementById(id);
    if (section && window.scrollY >= section.offsetTop - 200) {
      current = id;
    }
  });
  document.querySelectorAll('.nav-links a').forEach(link => {
    link.classList.toggle('active', link.getAttribute('href').includes(current));
  });
});

   </script>
</body>
</html>


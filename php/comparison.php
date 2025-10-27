<?php
session_start();

// --- 1. Handle Reset Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    unset($_SESSION['comparison_result']);
    unset($_SESSION['comparison_image']);
    unset($_SESSION['comparison_error']);
    header('Location: comparison.php');
    exit;
}

// --- 2. Load State from Session ---
$config  = require __DIR__ . '/config.php';
$python  = $config['python_path'];
$workdir = $config['workdir'];

$result = $_SESSION['comparison_result'] ?? null;
$uploaded_image_src = $_SESSION['comparison_image'] ?? null;
$error = $_SESSION['comparison_error'] ?? null;

unset($_SESSION['comparison_error']); // Clear error after showing it once


// --- 3. Handle File Upload Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
  $uploadDir = __DIR__ . '/test_uploads/';
  @mkdir($uploadDir, 0777, true);

  $originalName = basename($_FILES['image']['name']);
  $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
  $safeName = 'cmp_' . uniqid(md5($originalName), true) . '.' . $fileExtension;
  $imagePath = $uploadDir . $safeName;

  if (move_uploaded_file($_FILES['image']['tmp_name'], $imagePath)) {
    $uploaded_image_src = 'test_uploads/' . $safeName;

    // --- Build Command for compare_predict.py ---
    // Pass --csv path if available (assuming it's in config or a fixed location)
    // IMPORTANT: Update the path to your test.csv file here!
    $csv_path = $config['workdir'] . '/data/test.csv'; // Example path, adjust as needed!
    $csv_arg = file_exists($csv_path) ? ' --csv ' . escapeshellarg($csv_path) : '';

    $cmd = sprintf(
      'PYTHONPATH=%s %s -m woa_tool.compare_predict --image %s --ewoa %s/models/model_ewoa.json --woa %s/models/model_woa.json%s', // Added %s for csv_arg
      escapeshellarg($workdir),
      escapeshellcmd($python),
      escapeshellarg($imagePath),
      escapeshellarg($workdir), // Path to models dir
      escapeshellarg($workdir), // Path to models dir
      $csv_arg // Add the CSV argument if the file exists
    );

    // --- Execute Python script ---
    $stdout_str = ''; $stderr_str = ''; $code = -1;
    $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
    $process = proc_open($cmd, $descriptorspec, $pipes, $workdir);

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout_str = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr_str = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
    } else {
        $error = "Failed to execute Python script (proc_open failed).";
    }

    // --- Process Python Output ---
    if ($error === null) { // Only process if proc_open succeeded
        $decoded = json_decode($stdout_str, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['WOA']) && isset($decoded['EWOA'])) {
            $_SESSION['comparison_result'] = $decoded; // Store the *entire* decoded result
            $_SESSION['comparison_image'] = $uploaded_image_src;
            unset($_SESSION['comparison_error']);
        } else {
             $jsonErrorMsg = json_last_error_msg();
             $error = "Failed to get valid JSON from compare_predict.py (Code: $code, JSON Error: $jsonErrorMsg).";
             if(!empty($stderr_str)) { $error .= "<br>Stderr:<pre>" . htmlspecialchars($stderr_str) . "</pre>"; }
             if(!empty($stdout_str) && json_last_error() !== JSON_ERROR_NONE) { $error .= "<br>Raw Stdout:<pre>" . htmlspecialchars($stdout_str) . "</pre>"; }
             $_SESSION['comparison_error'] = $error;
             unset($_SESSION['comparison_result']);
             $_SESSION['comparison_image'] = $uploaded_image_src; // Keep image even if Python fails
        }
    } else {
         $_SESSION['comparison_error'] = $error; // Store the proc_open error
         unset($_SESSION['comparison_result']);
         $_SESSION['comparison_image'] = $uploaded_image_src;
    }

  } else {
    $error = "Failed to move uploaded file. Check directory permissions for '$uploadDir'.";
    $_SESSION['comparison_error'] = $error;
    unset($_SESSION['comparison_result']);
    unset($_SESSION['comparison_image']);
  }

  // --- 4. Redirect after POST ---
  header('Location: comparison.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WOA vs EWOA Comparison | WOA-Tool</title>
  <link rel="stylesheet" href="style.css?v=27"> <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body id="page-comparison">
  <header class="main-header"> <div class="header-inner"> <div class="header-left"> <div class="header-logo">🐋</div> <div class="header-title"> <h1>WOA: <span>Balancing Exploration–Exploitation</span></h1> <p>for Breast Cancer Feature Detection</p> </div> </div> <nav class="header-nav"> <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a> <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a> <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a> </nav> </div> </header>
  <div id="aurora-background"></div>

  <div class="main-container">
    <div class="header"> <h1> <span class="header-logo" style="font-size: 2.2rem; width: 60px; height: 60px;"> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="21" x2="6" y2="3"></line><line x1="18" y1="21" x2="18" y2="3"></line><line x1="2" y1="12" x2="22" y2="12"></line></svg> </span> WOA vs. EWOA Comparison </h1> <p>Upload a mammogram image to see a direct performance comparison.</p> </div>

    <div class="left-column"> <div class="step-card animate-slide-up"> <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background: var(--text-light);"> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg> </div> <h2>Upload Image</h2> </div> </div> <form id="comparison-form" method="POST" enctype="multipart/form-data"> <div id="image-preview-wrapper" style="display: <?php echo $uploaded_image_src ? 'flex' : 'none'; ?>; background: #fff;"> <img id="image-preview" src="<?php echo htmlspecialchars($uploaded_image_src ?? '#'); ?>" alt="Image preview" style="max-width: 100%; max-height: 300px; border-radius: var(--border-radius-small);" /> </div> <label for="image-upload" class="upload-area" id="upload-area" style="display: <?php echo $uploaded_image_src ? 'none' : 'block'; ?>;"> <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.2 15c.7-1.2 1-2.5.7-3.9-.6-2.4-2.4-4.2-4.8-4.8-1.4-.3-2.7-.1-3.9.7L12 8l-1.2-1.1c-1.2-.8-2.5-1-3.9-.7-2.4.6-4.2 2.4-4.8 4.8-.3 1.4-.1 2.7.7 3.9L4 16.5 12 22l8-5.5-2.8-1.5z"></path><path d="M12 8v8"></path></svg> <p class="upload-area__text"><span>Click to upload</span> or drag and drop</p> </label> <input type="file" id="image-upload" name="image" accept="image/*"> <p class="file-meta" id="file-meta-text" style="display: none; text-align: center;"></p> <div class="form-buttons mt-3"> <button type="submit" id="run-comparison-btn" class="btn btn-primary-full" disabled> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="21" x2="6" y2="3"></line><line x1="18" y1="21" x2="18" y2="3"></line><line x1="2" y1="12" x2="22" y2="12"></line></svg> Run Comparison </button> <button type="submit" name="action" value="reset" id="reset-btn" class="btn btn-secondary" <?php echo !$result && !$uploaded_image_src && !$error ? 'disabled' : ''; ?>> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg> Reset </button> </div> </form> </div> </div>

    <div class="right-column">
      <?php if ($error): ?>
        <div class="step-card error-card animate-slide-up"> <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background: var(--accent-warning);"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg></div> <h2>Error</h2> </div> </div> <p>An error occurred:</p> <div><?php echo $error; ?></div> </div>
      <?php elseif ($result): ?>
        <div id="comparison-results" class="animate-slide-up">
          <div class="comparison-grid">
            <div class="step-card comparison-card">
              <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background: var(--text-dark);">W</div> <h2>Standard WOA</h2> </div> <button class="maximize-card-btn" data-modal-title="Standard WOA Results" data-modal-type="content" data-modal-target="#woa-card-content"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button> </div>
              <div id="woa-card-content">
                <ul class="comparison-metrics simplified">
                  <li> <span class="metric-label">Prediction <span class="tooltip-icon">?<span class="tooltip-content">Benign or Malignant classification result.</span></span></span> <span class="metric-value <?php echo ($result['WOA']['Prediction'] ?? '') == 'Malignant' ? 'value-malignant' : 'value-benign'; ?>" data-field="woa-prediction"> <?php echo htmlspecialchars($result['WOA']['Prediction'] ?? 'N/A'); ?> </span> </li>
                  <li> <span class="metric-label">Confidence <span class="tooltip-icon">?<span class="tooltip-content">Model certainty (distance ratio based). Closer to 1 = higher confidence.</span></span></span> <span class="metric-value" data-field="woa-confidence"><?php echo htmlspecialchars($result['WOA']['Confidence'] ?? 'N/A'); ?></span> </li>
                  <li> <span class="metric-label">Exec. Time <span class="tooltip-icon">?<span class="tooltip-content">Time for this algorithm run (seconds).</span></span></span> <span class="metric-value" data-field="woa-time"><?php echo htmlspecialchars($result['WOA']['Execution Time'] ?? 'N/A'); ?> s</span> </li>
                  <li class="collapsible-container"> <button type="button" class="details-toggle-btn" data-target="#woa-details-content">Show Technical Details</button>
                    <div id="woa-details-content" class="collapsible-content">
                       <ul class="comparison-metrics nested-details">
                            <li><span class="metric-label nested">Dist. Ratio <span class="tooltip-icon">?<span class="tooltip-content">Ratio dM/dB. Lower values lean towards Malignant.</span></span></span> <span class="metric-value nested" data-field="woa-ratio"><?php echo htmlspecialchars($result['WOA']['Distance Ratio'] ?? 'N/A'); ?></span></li>
                            <li><span class="metric-label nested">Threshold (τ) <span class="tooltip-icon">?<span class="tooltip-content">Decision threshold used (Ratio ≤ τ suggests Malignant).</span></span></span> <span class="metric-value nested" data-field="woa-tau"><?php echo htmlspecialchars($result['WOA']['Tau Used'] ?? 'N/A'); ?></span></li>
                            <li><span class="metric-label nested">Top Features <span class="tooltip-icon">?<span class="tooltip-content">Top 5 features influencing the distance calculation.</span></span></span> <span class="metric-value metric-features nested" data-field="woa-features"><?php echo isset($result['WOA']['Top Features']) ? implode(', ', $result['WOA']['Top Features']) : 'N/A'; ?></span></li>
                            <?php if(isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') != 'N/A (no ground truth)'): ?>
                             <li><span class="metric-label nested">Accuracy <span class="tooltip-icon">?<span class="tooltip-content">Correctness vs ground truth (TP=True Positive, FN=False Negative, etc.).</span></span></span>
                                <span class="metric-value nested <?php echo ($result['WOA']['Correct'] ?? null) === true ? 'value-benign' : (($result['WOA']['Correct'] ?? null) === false ? 'value-malignant' : ''); ?>" data-field="woa-accuracy">
                                    <?php echo isset($result['WOA']['Correct']) ? ($result['WOA']['Correct'] ? 'Correct' : 'Incorrect') : 'N/A'; ?>
                                    (<?php echo htmlspecialchars($result['WOA']['Outcome'] ?? 'N/A'); ?>)
                                </span>
                             </li>
                            <?php endif; ?>
                       </ul>
                    </div>
                  </li>
                </ul>
              </div>
            </div>

            <div class="step-card comparison-card ewoa-card">
              <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background: var(--accent-glow);">E</div> <h2>Enhanced WOA</h2> </div> <button class="maximize-card-btn" data-modal-title="Enhanced WOA Results" data-modal-type="content" data-modal-target="#ewoa-card-content"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button> </div>
              <div id="ewoa-card-content">
                <ul class="comparison-metrics simplified">
                  <li> <span class="metric-label">Prediction <span class="tooltip-icon">?<span class="tooltip-content">Benign or Malignant classification result.</span></span></span> <span class="metric-value <?php echo ($result['EWOA']['Prediction'] ?? '') == 'Malignant' ? 'value-malignant' : 'value-benign'; ?>" data-field="ewoa-prediction"> <?php echo htmlspecialchars($result['EWOA']['Prediction'] ?? 'N/A'); ?> </span> </li>
                  <li> <span class="metric-label">Confidence <span class="tooltip-icon">?<span class="tooltip-content">Model certainty (distance ratio based). Closer to 1 = higher confidence.</span></span></span> <span class="metric-value" data-field="ewoa-confidence"><?php echo htmlspecialchars($result['EWOA']['Confidence'] ?? 'N/A'); ?></span> </li>
                  <li> <span class="metric-label">Exec. Time <span class="tooltip-icon">?<span class="tooltip-content">Time for this algorithm run (seconds).</span></span></span> <span class="metric-value" data-field="ewoa-time"><?php echo htmlspecialchars($result['EWOA']['Execution Time'] ?? 'N/A'); ?> s</span> </li>
                  <li class="collapsible-container"> <button type="button" class="details-toggle-btn" data-target="#ewoa-details-content">Show Technical Details</button>
                    <div id="ewoa-details-content" class="collapsible-content">
                       <ul class="comparison-metrics nested-details">
                           <li><span class="metric-label nested">Dist. Ratio <span class="tooltip-icon">?<span class="tooltip-content">Ratio dM/dB. Lower values lean towards Malignant.</span></span></span> <span class="metric-value nested" data-field="ewoa-ratio"><?php echo htmlspecialchars($result['EWOA']['Distance Ratio'] ?? 'N/A'); ?></span></li>
                           <li><span class="metric-label nested">Threshold (τ) <span class="tooltip-icon">?<span class="tooltip-content">Decision threshold used (Ratio ≤ τ suggests Malignant).</span></span></span> <span class="metric-value nested" data-field="ewoa-tau"><?php echo htmlspecialchars($result['EWOA']['Tau Used'] ?? 'N/A'); ?></span></li>
                           <li><span class="metric-label nested">Top Features <span class="tooltip-icon">?<span class="tooltip-content">Top 5 features influencing the distance calculation.</span></span></span> <span class="metric-value metric-features nested" data-field="ewoa-features"><?php echo isset($result['EWOA']['Top Features']) ? implode(', ', $result['EWOA']['Top Features']) : 'N/A'; ?></span></li>
                           <?php if(isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') != 'N/A (no ground truth)'): ?>
                            <li><span class="metric-label nested">Accuracy <span class="tooltip-icon">?<span class="tooltip-content">Correctness vs ground truth (TP=True Positive, FN=False Negative, etc.).</span></span></span>
                                <span class="metric-value nested <?php echo ($result['EWOA']['Correct'] ?? null) === true ? 'value-benign' : (($result['EWOA']['Correct'] ?? null) === false ? 'value-malignant' : ''); ?>" data-field="ewoa-accuracy">
                                    <?php echo isset($result['EWOA']['Correct']) ? ($result['EWOA']['Correct'] ? 'Correct' : 'Incorrect') : 'N/A'; ?>
                                    (<?php echo htmlspecialchars($result['EWOA']['Outcome'] ?? 'N/A'); ?>)
                                </span>
                            </li>
                           <?php endif; ?>
                       </ul>
                    </div>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <div class="step-card animate-slide-up" style="animation-delay: 100ms; margin-top: 2rem;">
              <div class="step-header"> <div class="step-header-left"> <div class="step-number"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path><path d="m9 12 2 2 4-4"></path></svg></div> <h2>Overall Summary</h2> </div> <button class="maximize-card-btn" data-modal-title="Summary & Feature Comparison" data-modal-type="chart" data-modal-target="feature-radar-chart"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" /></svg> </button> </div>
              <?php if(isset($result['Ground Truth']) && ($result['Ground Truth'] ?? '') != 'N/A (no ground truth)'): ?>
                  <p class="ground-truth-display">
                      <span class="tooltip-icon" style="background: var(--accent-success-tint); color: var(--accent-success);">i</span>
                      Ground Truth Label: <strong><?php echo htmlspecialchars($result['Ground Truth']); ?></strong>
                      <span class="tooltip-icon">?<span class="tooltip-content">The known correct classification for this image, used for accuracy calculation. Provided via CSV or command line.</span></span>
                  </p>
              <?php elseif(isset($result['Ground Truth'])): ?>
                   <p class="ground-truth-display" style="background: var(--accent-warning-tint); border-color: var(--accent-warning);">
                       <span class="tooltip-icon" style="background: var(--accent-warning-tint); color: var(--accent-warning);">!</span>
                       Ground Truth Label: <strong>Not Provided</strong>
                       <span class="tooltip-icon">?<span class="tooltip-content">The correct classification for this image was not provided (e.g., via CSV file), so accuracy cannot be calculated.</span></span>
                    </p>
              <?php endif; ?>
              <div class="comparison-summary">
                <div class="summary-metric"> <span class="metric-label">Time Improvement <span class="tooltip-icon">?<span class="tooltip-content">Percentage difference in execution time. Positive means EWOA was faster.</span></span></span>
                  <?php $woa_time = (float)($result['WOA']['Execution Time'] ?? 0); $ewoa_time = (float)($result['EWOA']['Execution Time'] ?? 0); $time_diff = $woa_time - $ewoa_time; $percent_diff = ($woa_time > 0) ? ($time_diff / $woa_time) * 100 : 0; ?>
                  <span class="metric-value <?php echo $time_diff >= 0 ? 'value-benign' : 'value-malignant'; ?>"> <?php if ($woa_time == 0) echo 'N/A'; elseif ($time_diff >= 0) echo 'EWOA ' . number_format($percent_diff, 1) . '% faster'; else echo 'EWOA ' . number_format(abs($percent_diff), 1) . '% slower'; ?> </span>
                </div>
                <div class="summary-metric"> <span class="metric-label">Total Python Runtime <span class="tooltip-icon">?<span class="tooltip-content">Combined time spent inside Python for both runs.</span></span></span> <span class="metric-value"><?php echo htmlspecialchars(number_format($woa_time + $ewoa_time, 3)); ?> s</span> </div>
              </div>
              <h3 class="chart-title">Feature Selection Comparison <span class="tooltip-icon">?<span class="tooltip-content">Radar chart showing which of the Top 5 features were selected by each algorithm (1=Selected, 0=Not Selected).</span></span></h3>
              <div class="chart-container" style="height: 300px; margin-top: 1rem;"> <canvas id="feature-radar-chart"></canvas> </div>
          </div>
        </div>
      <?php else: ?>
        <div id="comparison-placeholder" class="placeholder-card single-placeholder animate-slide-up"> <div class="step-header"> <div class="step-header-left"> <div class="step-number" style="background: var(--text-dark); opacity: 0.5;">?</div> <h2>Results</h2> </div> </div> <div class="placeholder-content"> <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg> <h3>Waiting for Image</h3> <p>Upload a mammogram image to begin the comparison.</p> </div> </div>
      <?php endif; ?>
    </div>
  </div>

  <div id="card-modal-overlay"> <div id="card-modal-content"> <button class="close-modal-btn">&times;</button> <h2 class="modal-title">Modal Title</h2> <div class="modal-body" id="card-modal-body"></div> </div> </div>
  <div class="loader-overlay" id="loader-overlay" style="display: none; opacity: 0;"> <div class="scanner-wrapper"> <div class="scanner-wave"></div> <div class="scanner-ring"> <div class="inner-ring"></div> </div> </div> <div class="scanner-text"> <div id="scan-status">Running Comparison...</div> <div class="progress-bar" style="background: rgba(216, 27, 96, 0.15);"> <div id="progress-fill" style="width: 100%;"></div> </div> </div> </div>

  <footer> <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p> </footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- Refs, State, Config (Keep as is) ---
    const form=document.getElementById('comparison-form'),fileInput=document.getElementById('image-upload'),uploadArea=document.getElementById('upload-area'),previewWrapper=document.getElementById('image-preview-wrapper'),previewImg=document.getElementById('image-preview'),fileMetaText=document.getElementById('file-meta-text'),runButton=document.getElementById('run-comparison-btn'),resetButton=document.getElementById('reset-btn'),loaderOverlay=document.getElementById('loader-overlay'),modalOverlay=document.getElementById('card-modal-overlay'),modalContent=document.getElementById('card-modal-content'),modalTitle=modalContent.querySelector('.modal-title'),modalBody=modalContent.querySelector('#card-modal-body'),closeModalBtn=modalContent.querySelector('.close-modal-btn');
    let radarChartConfig={},modalChartInstance=null; const PRETTY_NAMES = <?php global $pretty_names; echo json_encode($pretty_names); ?> || {};

    // --- Utility Functions (Keep as is) ---
     function handleFile(f){if(f&&f.type.startsWith('image/')){const r=new FileReader();r.onload=e=>{previewImg.src=e.target.result;previewWrapper.style.display='flex';uploadArea.style.display='none';runButton.disabled=false;resetButton.disabled=false;runButton.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="21" x2="6" y2="3"></line><line x1="18" y1="21" x2="18" y2="3"></line><line x1="2" y1="12" x2="22" y2="12"></line></svg> Run Comparison'};r.readAsDataURL(f);fileMetaText.textContent=`${f.name} (${(f.size/1024).toFixed(1)} KB)`;fileMetaText.style.display='block'}}
    fileInput.addEventListener('change',e=>handleFile(e.target.files[0]));['dragenter','dragover','dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,e=>{e.preventDefault();e.stopPropagation()},!1)});['dragenter','dragover'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.add('dragover'),!1)});['dragleave','drop'].forEach(n=>{uploadArea.addEventListener(n,()=>uploadArea.classList.remove('dragover'),!1)});uploadArea.addEventListener('drop',e=>{const d=e.dataTransfer;const f=d.files[0];fileInput.files=d.files;handleFile(f)},!1);
    form.addEventListener('submit',e=>{const s=e.submitter||document.activeElement;if(s&&s.id==='run-comparison-btn'){if(fileInput.files.length>0||previewWrapper.style.display==='flex'){loaderOverlay.style.display='flex';setTimeout(()=>{loaderOverlay.style.opacity='1'},10)}else{e.preventDefault()}}});
    if(previewWrapper.style.display==='flex'){runButton.disabled=true;fileMetaText.textContent='Previously uploaded image. Upload a new file to run.';fileMetaText.style.display='block';runButton.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="21" x2="6" y2="3"></line><line x1="18" y1="21" x2="18" y2="3"></line><line x1="2" y1="12" x2="22" y2="12"></line></svg> Re-run Comparison'}

    // --- Render Radar Chart ---
    <?php if ($result): ?>
    try {
        const ctx = document.getElementById('feature-radar-chart')?.getContext('2d');
        if (ctx) {
            const woaFeatures = <?php echo json_encode($result['WOA']['Top Features'] ?? []); ?>;
            const ewoaFeatures = <?php echo json_encode($result['EWOA']['Top Features'] ?? []); ?>;
            const allFeaturesRaw = [...new Set([...woaFeatures, ...ewoaFeatures])].sort();
            const allFeaturesPretty = allFeaturesRaw.map(f => PRETTY_NAMES[f] || f);
            const woaData = allFeaturesRaw.map(f => woaFeatures.includes(f) ? 1 : 0);
            const ewoaData = allFeaturesRaw.map(f => ewoaFeatures.includes(f) ? 1 : 0);
            radarChartConfig = { type: 'radar', data: { labels: allFeaturesPretty, datasets: [ { label: 'WOA', data: woaData, borderColor: 'rgba(127, 140, 141, 0.8)', backgroundColor: 'rgba(127, 140, 141, 0.3)', borderWidth: 2 }, { label: 'EWOA', data: ewoaData, borderColor: 'rgba(216, 27, 96, 0.8)', backgroundColor: 'rgba(216, 27, 96, 0.3)', borderWidth: 2 } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { r: { beginAtZero: true, max: 1, ticks: { stepSize: 1, display: false }, pointLabels: { font: { size: 10 } }, grid: { color: 'rgba(0,0,0,0.05)' } } }, plugins: { tooltip: { callbacks: { label: (c) => (c.raw === 1 ? 'Selected' : 'Not Selected') } } } } };
            new Chart(ctx, radarChartConfig);
        }
    } catch (e) { console.error("Failed to render radar chart:", e); }
    <?php endif; ?>

    // --- Modal Logic (Keep as is) ---
    function openModal(t,y,i){modalTitle.textContent=t;modalBody.innerHTML='';if(modalChartInstance){modalChartInstance.destroy()}if(y==='content'){const e=document.querySelector(i);if(e){const c=e.cloneNode(!0);modalBody.appendChild(c);modalBody.querySelectorAll('.collapsible-content').forEach(el=>{el.style.maxHeight='none';el.classList.remove('initially-hidden')});modalBody.querySelectorAll('.details-toggle-wrapper').forEach(el=>el.style.display='none')}}else if(y==='chart'){if(i==='feature-radar-chart'&&radarChartConfig){const c=document.createElement('div');c.className='modal-chart-container';c.innerHTML='<canvas id="modal-chart-canvas"></canvas>';modalBody.appendChild(c);const m=document.getElementById('modal-chart-canvas').getContext('2d');modalChartInstance=new Chart(m,radarChartConfig)}else{modalBody.textContent='Chart data not available.'}}modalOverlay.classList.add('visible')}
    closeModalBtn.addEventListener('click',()=>{modalOverlay.classList.remove('visible');if(modalChartInstance)modalChartInstance.destroy()});modalOverlay.addEventListener('click',e=>{if(e.target===modalOverlay){modalOverlay.classList.remove('visible');if(modalChartInstance)modalChartInstance.destroy()}});document.addEventListener('click',e=>{const m=e.target.closest('.maximize-card-btn');if(m){const t=m.dataset.modalTitle;const y=m.dataset.modalType;const i=m.dataset.modalTarget;openModal(t,y,i)}});

    // --- Details Toggle Logic (Keep as is) ---
     document.addEventListener('click', function(event) { const button = event.target.closest('.details-toggle-btn'); if (button) { const targetSelector = button.dataset.target; const content = document.querySelector(targetSelector); if (content) { button.classList.toggle('active'); if (content.style.maxHeight && content.style.maxHeight !== '0px') { content.style.maxHeight = null; if(button.classList.contains('expand-btn')) button.textContent = 'Expand'; else button.textContent = button.textContent.replace('Hide', 'Show'); } else { content.style.maxHeight = content.scrollHeight + "px"; if(button.classList.contains('expand-btn')) button.textContent = 'Collapse'; else button.textContent = button.textContent.replace('Show', 'Hide'); } } } });

});
</script>

<style> @keyframes indeterminate-progress{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}#progress-fill{position:relative;overflow:hidden;background:var(--accent-glow)}#progress-fill::after{content:'';position:absolute;top:0;left:0;bottom:0;right:0;transform:translateX(-100%);background:rgba(255,255,255,0.3);animation:indeterminate-progress 2s infinite ease-in-out} </style>

</body>
</html>
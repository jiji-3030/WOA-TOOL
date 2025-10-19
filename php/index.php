<?php
require __DIR__ . '/config.php';
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$result = null;
$error  = null;
$uploadedImageWebPath = null;   // add this

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $uploadDir = __DIR__ . '/test_uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileName   = uniqid('', true) . '-' . basename($_FILES['image']['name']);
    $targetPath = $uploadDir . $fileName;                 // absolute path

    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
        // optional web path for preview
        $uploadedImageWebPath = 'test_uploads/' . basename($targetPath);

        // ✅ PASS RAW PATH; build_predict_cmd() will escape once
        $cmd = build_predict_cmd($targetPath);

        $desc = [
          0 => ['pipe','r'],
          1 => ['pipe','w'],
          2 => ['pipe','w'],
        ];
        $proc = proc_open($cmd, $desc, $pipes, get_workdir());
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code   = proc_close($proc);

            $decoded = json_decode($stdout, true);
            if (is_array($decoded)) {
                $result = $decoded;
            } else {
                $error = "Model did not return valid JSON on stdout."
                       . "<br><b>Exit code:</b> $code"
                       . "<br><b>STDERR:</b><pre>" . htmlspecialchars($stderr ?: '(empty)') . "</pre>"
                       . "<br><b>STDOUT (first 500 chars):</b><pre>" . htmlspecialchars(substr($stdout,0,500)) . "</pre>"
                       . "<br><b>CMD:</b><pre>" . htmlspecialchars($cmd) . "</pre>";
            }
        } else {
            $error = "proc_open failed — shell execution not allowed?";
        }
    } else {
        $error = "⚠️ File upload failed (permissions?).";
    }
}

// If AJAX, return pure JSON
if (!empty($_POST['ajax'])) {
    $noise = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'     => (bool)$result,
        'result' => $result,
        'error'  => $error,
        'noise'  => $noise ?: null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// Prepare data for front-end
$jsonData = $result ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, maximum-scale=1"
  />
  <title>WOA & EWOA Breast Cancer Feature Detection</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">

  <!-- fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
  >

  <!-- external stylesheet: place style.css next to this file -->
  <link rel="stylesheet" href="style.css?v=1">

  <!-- libs -->
  <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- bootstrap data for JS -->
  <script>
    window.__PREDICT__ = <?php echo $jsonData; ?>;
    window.__UPLOADED_IMAGE__ = <?php echo json_encode($uploadedImageWebPath ?: null); ?>;
  </script>
</head>
<body>
  <div id="aurora-background"></div>

  <div class="main-container">
    <header class="header">
      <h1>WOA & EWOA Breast Cancer Feature Detection</h1>

      <div class="quick-guide">
        <h3>🧭 Quick Start Guide</h3>
        <ul>
          <li><strong>Step 1:</strong> Upload a mammogram image (<code>.png</code>, <code>.jpg</code>, or <code>.tif</code>).</li>
          <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong> to start the analysis.</li>
          <li><strong>Step 3:</strong> View detected features and the final classification result.</li>
        </ul>
      </div>
    </header>

    <!-- Step 1: Upload -->
    <div class="step-card">
      <div class="step-header">
        <div class="step-number">1</div>
        <h2>Upload Image</h2>
      </div>

      <form id="image-upload-form" method="post" enctype="multipart/form-data">
        <div id="image-preview-wrapper">
          <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                 fill="currentColor" viewBox="0 0 16 16">
              <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
            </svg>
          </button>
          <canvas></canvas>
          <p id="image-filename" class="file-meta" style="display:none;"></p>
        </div>

        <div class="upload-area" id="upload-area">
          <input type="file" name="image" id="file-input"
                 accept=".tif,.tiff,.png,.jpg,.jpeg" required>
          <svg class="upload-area__icon" xmlns="http://www.w3.org/2000/svg"
               fill="none" viewBox="0 0 24 24" stroke="currentColor"
               stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
          <p class="upload-area__text">
            Drag & Drop image file or <span>browse</span> to upload.
          </p>
        </div>
      </form>
    </div>

    <!-- Step 2: Run -->
    <div class="step-card text-center">
      <div class="step-header">
        <div class="step-number">2</div>
        <h2>Run Analysis</h2>
      </div>

      <p style="color:var(--text-dark); margin-bottom: 2rem;">
        Once an image is selected, the analysis button will become active.
      </p>

      <button class="btn" type="submit" id="submit-btn" disabled form="image-upload-form">
        <span id="btn-text">Run Prediction</span>
        <div class="spinner" id="spinner" style="display:none;"></div>
      </button>

      <button class="btn" type="button" id="clear-btn" style="margin-top:0; margin-left:.75rem;">
        ↺ Reset
      </button>
    </div>

    <div id="error-container"></div>

    <!-- Skeleton -->
    <div class="skeleton-container animate-slide-up" id="skeleton-loader">
      <div class="step-card skeleton-card">
        <div class="step-number">3</div>
        <h2 style="text-align:left;">Analyzing...</h2>
        <div class="skeleton-item" style="height:40px; margin-bottom:1rem;"></div>
        <div class="skeleton-item" style="height:200px; margin-bottom:1.5rem;"></div>
        <div class="skeleton-item" style="height:200px;"></div>
      </div>
    </div>

    <!-- Results -->
<div class="results-container animate-slide-up" id="results-container" style="display:none;">
  <div class="step-card">
    <div class="step-header">
      <div class="step-number">3</div>
      <h2>View Results</h2>
    </div>

    <!-- THIS DIV MUST EXIST -->
    <div class="results-grid" id="results-grid"></div>
  </div>
</div>

      <!-- Full tabular fallback (from simpler index) -->
      <?php if ($result): ?>
        <div class="step-card" style="margin-top:1rem;">
          <h2 style="text-align:left;">Full Result (Tabular)</h2>

          <h3 class="mt-3">Final Prediction</h3>
          <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
            <tr><th>Final Prediction</th><td><?php echo htmlspecialchars($result['final_prediction'] ?? '—'); ?></td></tr>
          </table>

          <?php if (!empty($result['probabilities']) && is_array($result['probabilities'])): ?>
            <h3 class="mt-3">Probabilities</h3>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
              <?php foreach ($result['probabilities'] as $k=>$v): ?>
                <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo round((float)$v, 4); ?></td></tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>

          <?php if (!empty($result['abnormality_type']) || !empty($result['abnormality_scores'])): ?>
            <h3 class="mt-3">Abnormality</h3>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
              <tr><th>Type</th><td><?php echo htmlspecialchars($result['abnormality_type'] ?? '—'); ?></td></tr>
              <?php if (!empty($result['abnormality_scores']) && is_array($result['abnormality_scores'])): ?>
                <?php foreach ($result['abnormality_scores'] as $k=>$v): ?>
                  <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo round((float)$v, 4); ?></td></tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </table>
          <?php endif; ?>

          <?php if (!empty($result['background_tissue']) && is_array($result['background_tissue'])): ?>
            <h3 class="mt-3">Background Tissue</h3>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
              <tr><td>Code</td><td><?php echo htmlspecialchars($result['background_tissue']['code'] ?? '—'); ?></td></tr>
              <tr><td>Text</td><td><?php echo htmlspecialchars($result['background_tissue']['text'] ?? '—'); ?></td></tr>
              <tr><td>Explain</td><td><?php echo htmlspecialchars($result['background_tissue']['explain'] ?? '—'); ?></td></tr>
            </table>
          <?php endif; ?>

          <?php if (!empty($result['explanation']) && is_array($result['explanation'])): ?>
            <h3 class="mt-3">Explanations</h3>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
              <tr>
                <th>Class-based</th>
                <td>
                  <?php
                    if (!empty($result['explanation']['class']) && is_array($result['explanation']['class'])) {
                      foreach ($result['explanation']['class'] as $e) echo htmlspecialchars($e) . "<br>";
                    } else { echo '—'; }
                  ?>
                </td>
              </tr>
              <tr>
                <th>Abnormality-based</th>
                <td>
                  <?php
                    if (!empty($result['explanation']['abnormality']) && is_array($result['explanation']['abnormality'])) {
                      foreach ($result['explanation']['abnormality'] as $e) echo htmlspecialchars($e) . "<br>";
                    } else { echo '—'; }
                  ?>
                </td>
              </tr>
            </table>
          <?php endif; ?>

          <?php if (!empty($result['selected_features_used']) && is_array($result['selected_features_used'])): ?>
            <h3 class="mt-3">Selected Features Used</h3>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:.5rem;">
              <?php foreach ($result['selected_features_used'] as $f): ?>
                <tr><td><?php echo htmlspecialchars($f); ?></td></tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal -->
  <div id="image-modal-overlay"></div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('file-input');
    const submitBtn = document.getElementById('submit-btn');
    const form = document.getElementById('image-upload-form');
    const spinner = document.getElementById('spinner');
    const btnText = document.getElementById('btn-text');
    const skeletonLoader = document.getElementById('skeleton-loader');
    const uploadArea = document.getElementById('upload-area');
    const resultsContainer = document.getElementById('results-container');
    const resultsGrid = document.getElementById('results-grid');
    const modalOverlay = document.getElementById('image-modal-overlay');
    const errorContainer = document.getElementById('error-container');

    function showError(message) {
      errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${message}</div>`;
    }

    function renderToCanvas(file) {
      return new Promise((resolve, reject) => {
        const isTiff = file.type === 'image/tiff' ||
                       file.name.toLowerCase().endsWith('.tif') ||
                       file.name.toLowerCase().endsWith('.tiff');
        const reader = new FileReader();

        if (isTiff) {
          reader.onload = e => {
            try {
              Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 });
              const tiff = new Tiff({ buffer: e.target.result });
              resolve(tiff.toCanvas());
            } catch (err) { reject(err); }
          };
          reader.onerror = reject;
          reader.readAsArrayBuffer(file);
        } else {
          reader.onload = e => {
            const img = new Image();
            img.onload = () => {
              const canvas = document.createElement('canvas');
              canvas.width = img.width;
              canvas.height = img.height;
              canvas.getContext('2d').drawImage(img, 0, 0);
              resolve(canvas);
            };
            img.onerror = reject;
            img.src = e.target.result;
          };
          reader.onerror = reject;
          reader.readAsDataURL(file);
        }
      });
    }

    function scaleCanvasToFit(srcCanvas, maxW, maxH) {
      const w = srcCanvas.width, h = srcCanvas.height;
      const scale = Math.min(maxW / w, maxH / h, 1);
      const out = document.createElement('canvas');
      out.width  = Math.round(w * scale);
      out.height = Math.round(h * scale);
      out.getContext('2d').drawImage(srcCanvas, 0, 0, out.width, out.height);
      return out;
    }

    function displayCanvas(canvas, containerElement) {
      containerElement.innerHTML = '';
      containerElement.appendChild(canvas);

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'maximize-btn';
      btn.title = 'Maximize Image';
      btn.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
             fill="currentColor" viewBox="0 0 16 16">
          <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
        </svg>`;
      containerElement.appendChild(btn);
      containerElement.style.display = 'block';
    }

    function handleFileSelect() {
      if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        const previewWrapper = document.getElementById('image-preview-wrapper');

        renderToCanvas(file)
          .then(rawCanvas => {
            const maxW = previewWrapper.clientWidth || 900;
            const maxH = 300;
            const scaled = scaleCanvasToFit(rawCanvas, maxW, maxH);
            previewWrapper.dataset.fullImage = rawCanvas.toDataURL();
            displayCanvas(scaled, previewWrapper);

            const nameEl = document.getElementById('image-filename');
            if (nameEl) { nameEl.textContent = file.name; nameEl.style.display = 'block'; }

            submitBtn.disabled = false;
            document.getElementById('clear-btn').style.display = 'inline-flex';
          })
          .catch(err => {
            console.error(err);
            showError('Could not read or render the selected image file.');
          });
      }
    }

    uploadArea.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', handleFileSelect);

    ['dragenter','dragover','dragleave','drop'].forEach(ev => {
      uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false);
    });
    ['dragenter','dragover'].forEach(ev => {
      uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover'), false);
    });
    ['dragleave','drop'].forEach(ev => {
      uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover'), false);
    });
    uploadArea.addEventListener('drop', e => {
      fileInput.files = e.dataTransfer.files;
      handleFileSelect();
    });

    form.addEventListener('submit', async e => {
  e.preventDefault();
  submitBtn.disabled = true;
  spinner.style.display = 'block';
  btnText.textContent = 'Analyzing...';
  skeletonLoader.style.display = 'block';
  resultsContainer.style.display = 'none';
  errorContainer.innerHTML = '';

  try {
    const formData = new FormData(form);
    formData.set('ajax', '1'); // tell PHP to return JSON

    const response = await fetch(window.location.href, {
      method: 'POST',
      body: formData
    });

    // Helpful errors if server sends 4xx/5xx or non-JSON
    const contentType = response.headers.get('content-type') || '';
    if (!response.ok) {
      const text = await response.text();
      throw new Error(`HTTP ${response.status}\n\n${text.slice(0, 2000)}`);
    }
    if (!contentType.includes('application/json')) {
      const text = await response.text();
      throw new Error(`Expected JSON, got:\n\n${text.slice(0, 2000)}`);
    }

    const payload = await response.json();
    console.log('AJAX payload:', payload);

    if (payload.ok && payload.result) {
      displayResults(payload.result);
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    } else {
      throw new Error(payload.error || 'Backend returned no result.');
    }
  } catch (err) {
    console.error(err);
    showError(err?.message?.replace(/\n/g, '<br>') || 'An error occurred.');
  } finally {
    skeletonLoader.style.display = 'none';
    spinner.style.display = 'none';
    btnText.textContent = 'Run Prediction';
    submitBtn.disabled = false;
  }
});

    // Reset
    const clearBtn = document.getElementById('clear-btn');
    clearBtn.addEventListener('click', () => {
      fileInput.value = '';
      const previewWrapper = document.getElementById('image-preview-wrapper');
      previewWrapper.style.display = 'none';
      previewWrapper.removeAttribute('data-full-image');
      previewWrapper.innerHTML = `
        <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
               fill="currentColor" viewBox="0 0 16 16">
            <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
          </svg>
        </button>
        <canvas></canvas>
        <p id="image-filename" class="file-meta" style="display:none;"></p>
      `;

      const nameEl = document.getElementById('image-filename');
      if (nameEl) nameEl.style.display = 'none';

      resultsContainer.style.display = 'none';
      errorContainer.innerHTML = '';
      skeletonLoader.style.display = 'none';

      btnText.textContent = 'Run Prediction';
      submitBtn.disabled = true;
      clearBtn.style.display = 'none';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Modal for image
    document.body.addEventListener('click', e => {
      if (e.target.closest('.maximize-btn')) {
        const dataURL = document.getElementById('image-preview-wrapper')?.dataset?.fullImage;
        if (dataURL) showImageInModal(dataURL);
      }
    });
    function showImageInModal(dataURL) {
      const img = new Image();
      img.src = dataURL;
      img.style.maxWidth = '90vw';
      img.style.maxHeight = '90vh';
      img.style.borderRadius = '12px';
      modalOverlay.innerHTML = '';
      modalOverlay.appendChild(img);
      modalOverlay.classList.add('visible');
    }
    modalOverlay.addEventListener('click', e => {
      if (e.target === modalOverlay) modalOverlay.classList.remove('visible');
    });

    // Render result cards + chart
    function displayResults(resultData) {
      resultsContainer.style.display = 'block';

      const pred = `
        <div class="step-card animate-slide-up">
          <h2 style="padding-left:0; text-align:center;">Final Prediction</h2>
          <div style="text-align:center; font-size:2.5rem; font-weight:800; color:${
            resultData.final_prediction === 'Malignant' ? '#f87171' : '#4ade80'
          }">${resultData.final_prediction || '—'}</div>
        </div>`;

      const chart = `
        <div class="step-card animate-slide-up" style="animation-delay:.1s;">
          <h2>Probabilities</h2>
          <canvas id="probability-chart"></canvas>
        </div>`;

      const explain = `
        <div class="step-card animate-slide-up" style="animation-delay:.2s;">
          <h2>AI Assistant</h2>
          <div class="gemini-controls" style="display:flex; gap:1rem; justify-content:center;">
            <button class="btn gemini-btn" id="explain-btn">✨ Explain Results</button>
            <button class="btn gemini-btn" id="report-btn">✨ Generate Summary</button>
          </div>
          <div id="gemini-explanation" style="border-top:1px solid var(--border-color); padding-top:1.5rem; min-height:50px;">
            <div class="spinner" id="gemini-spinner" style="display:none; margin:2rem auto;"></div>
            <p id="gemini-placeholder" style="color:var(--text-dark);">Click a button above for an AI-powered explanation.</p>
          </div>
        </div>`;

      const resultsGrid = document.getElementById('results-grid');
        if (!resultsGrid) {
          console.error('Missing #results-grid container.');
          return;
        }

      resultsGrid.innerHTML = pred + chart + explain;

      // Chart
      const probs = resultData.probabilities || {};
      const ctx = document.getElementById('probability-chart').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: Object.keys(probs),
          datasets: [{
            data: Object.values(probs),
            backgroundColor: ['#3b82f633'],
            borderColor: ['#3b82f6'],
            borderWidth: 1
          }]
        },
        options: {
          indexAxis: 'y',
          scales: {
            x: { beginAtZero: true, max: 1, ticks: { color: 'var(--text-dark)' }, grid: { color: 'var(--border-color)' } },
            y: { ticks: { color: 'var(--text-dark)' }, grid: { color: 'transparent' } }
          },
          plugins: { legend: { display: false } }
        }
      });

      
  
    }

    function displayResults(resultData) {
  resultsContainer.style.display = 'block';

  const probRows = Object.entries(resultData.probabilities || {})
    .map(([k, v]) => `<tr><td>${k}</td><td>${(v ?? 0).toFixed(4)}</td></tr>`)
    .join('');

  const abnRows = Object.entries(resultData.abnormality_scores || {})
    .map(([k, v]) => `<tr><td>${k}</td><td>${(v ?? 0).toFixed(4)}</td></tr>`)
    .join('');

  const classExplain = Array.isArray(resultData.explanation?.class)
    ? resultData.explanation.class.map(e => `${e}`).join('<br>')
    : '—';

  const abnExplain = Array.isArray(resultData.explanation?.abnormality)
    ? resultData.explanation.abnormality.map(e => `${e}`).join('<br>')
    : '—';

  const featuresList = Array.isArray(resultData.selected_features_used)
    ? resultData.selected_features_used.map(f => `<li>${f}</li>`).join('')
    : '';

  const predCard = `
    <div class="step-card animate-slide-up">
      <h2 style="padding-left:0; text-align:center;">Final Prediction</h2>
      <div style="text-align:center; font-size:2.5rem; font-weight:800; color:${
        resultData.final_prediction === 'Malignant' ? '#f87171' : '#4ade80'
      }">${resultData.final_prediction || '—'}</div>
    </div>`;

  const chartCard = `
    <div class="step-card animate-slide-up" style="animation-delay:.1s;">
      <h2>Probabilities</h2>
      <canvas id="probability-chart"></canvas>
      <table class="data-table" style="margin-top:1rem;">
        ${probRows ? `<tbody>${probRows}</tbody>` : '<tbody><tr><td colspan="2">No probabilities</td></tr></tbody>'}
      </table>
    </div>`;

  const abnormalityCard = `
    <div class="step-card animate-slide-up" style="animation-delay:.15s;">
      <h2>Abnormality</h2>
      <table class="data-table">
        <tr><th>Type</th><td>${resultData.abnormality_type || '—'}</td></tr>
      </table>
      <table class="data-table" style="margin-top:.5rem;">
        ${abnRows ? `<tbody>${abnRows}</tbody>` : '<tbody><tr><td colspan="2">No scores</td></tr></tbody>'}
      </table>
    </div>`;

  const bg = resultData.background_tissue || {};
  const backgroundCard = `
    <div class="step-card animate-slide-up" style="animation-delay:.2s;">
      <h2>Background Tissue</h2>
      <table class="data-table">
        <tr><td>Code</td><td>${bg.code ?? '—'}</td></tr>
        <tr><td>Text</td><td>${bg.text ?? '—'}</td></tr>
        <tr><td>Explain</td><td>${bg.explain ?? '—'}</td></tr>
      </table>
    </div>`;

  const explainCard = `
    <div class="step-card animate-slide-up" style="animation-delay:.25s;">
      <h2>Explanations</h2>
      <table class="data-table">
        <tr><th>Class-based</th><td>${classExplain}</td></tr>
        <tr><th>Abnormality-based</th><td>${abnExplain}</td></tr>
      </table>
    </div>`;

  const featuresCard = `
    <div class="step-card animate-slide-up" style="animation-delay:.3s;">
      <h2>Selected Features Used</h2>
      ${
        featuresList
          ? `<ul style="columns:2; gap:1.5rem; margin-top:.5rem;">${featuresList}</ul>`
          : '<p>—</p>'
      }
    </div>`;

resultsGrid.innerHTML =
  predCard + chartCard + abnormalityCard + backgroundCard + explainCard + featuresCard;

  // Build the chart
  const probs = resultData.probabilities || {};
  const ctx = document.getElementById('probability-chart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: Object.keys(probs),
      datasets: [{ data: Object.values(probs), borderWidth: 1 }]
    },
    options: {
      indexAxis: 'y',
      scales: {
        x: { beginAtZero: true, max: 1, ticks: { color: 'var(--text-dark)' }, grid: { color: 'var(--border-color)' } },
        y: { ticks: { color: 'var(--text-dark)' }, grid: { color: 'transparent' } }
      },
      plugins: { legend: { display: false } }
    }
  });

  
}

    function showContentInModal(title, htmlContent) {
      modalOverlay.innerHTML = `<div class="modal-content-wrapper"><h2>${title}</h2><div>${htmlContent}</div></div>`;
      modalOverlay.classList.add('visible');
    }

    // Auto-render if backend already produced a result
    if (window.__PREDICT__ && typeof window.__PREDICT__ === 'object') {
      displayResults(window.__PREDICT__);
      document.getElementById('clear-btn').style.display = 'inline-flex';
    }
  });
  </script>
</body>
</html>

<?php
// ─────────────────────────────────────────────────────────────
// 1. BACKEND LOGIC
// ─────────────────────────────────────────────────────────────
session_start();
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ignore_user_abort(true);

$config = require __DIR__ . '/config.php';

$results = null;
$error_message = null;
$run_complete = false;

// Helper Functions
function format_metric_name(string $key): string {
    $map = [
        'best_mean'             => 'Mean Best Fitness',
        'best_std'              => 'Std Dev (Fitness)',
        'average_eer'           => 'EER',
        'runtime_s'             => 'Mean Runtime (s)',
        'convergence_rate_mean' => 'Convergence Rate',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function format_num($value, int $decimals = 6): string {
    if ($value === null) return '—';
    if (is_int($value)) return (string)$value;
    if (!is_numeric($value)) return htmlspecialchars((string)$value);
    
    // Handle scientific notation for very small numbers
    if ($value != 0 && abs($value) < 0.0001) {
        return sprintf('%.2e', $value);
    }
    return sprintf('%.' . $decimals . 'f', (float)$value);
}

function format_significance(float $pValue, float $alpha = 0.05): string {
    if ($pValue < $alpha) {
        return "<span style='color: #28a745; font-weight:bold;'>Statistically Significant (p < {$alpha})</span>";
    }
    return "<span style='color: #ffc107; font-weight:bold;'>Not Statistically Significant (p ≥ {$alpha})</span>";
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // FIXED PARAMETERS
    $bench = [
        'pop'   => 30,
        'iters' => 500,
        'dim'   => 30,
        'runs'  => 30,
        'algo'  => 'both'
    ];

    $selected_funcs = $_POST['function'] ?? ['griewank'];
    
    $python  = $config['python_path'] ?? null;
    $workdir = $config['workdir'] ?? null;

    if (!$python || !$workdir) {
        $error_message = "Configuration error: python_path or workdir missing.";
    } else {
        $seed = random_int(1, 100000);

        // Construct Command
        $cmd = sprintf(
            'cd %s && PYTHONPATH=%s %s -m woa_tool.bench --functions %s --algo both --pop %d --iters %d --runs %d --dim %d --seed %d',
            escapeshellarg($workdir),
            escapeshellarg($workdir),
            escapeshellcmd($python),
            implode(' ', $selected_funcs),
            $bench['pop'],
            $bench['iters'],
            $bench['runs'],
            $bench['dim'],
            $seed
        );

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, $workdir);

        if (!is_resource($process)) {
            $error_message = "Failed to start benchmark process.";
        } else {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exit_code = proc_close($process);
            $decoded = json_decode(trim($stdout), true);

            if ($exit_code === 0 && json_last_error() === JSON_ERROR_NONE) {
                $results = $decoded;
                $run_complete = true;
            } else {
                $error_message  = "Benchmark Execution Failed (Exit: $exit_code). <br>";
                if ($stderr) $error_message .= "<small>" . htmlspecialchars($stderr) . "</small>";
                if (!$decoded && $stdout) $error_message .= "<br><small>Output: " . htmlspecialchars($stdout) . "</small>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Benchmark Functions | WOA-Tool</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>

<body>

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
        <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature
          Detection</a>
        <a href="benchmark.php"
          class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a>
        <a href="comparison.php"
          class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
      </nav>
    </div>
  </header>

  <div id="aurora-background"></div>

  <div class="main-container">

    <div class="header">
      <h1>
        <span class="header-logo" style="font-size: 2.2rem; width: 60px; height: 60px;">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="20" x2="18" y2="10"></line>
            <line x1="12" y1="20" x2="12" y2="4"></line>
            <line x1="6" y1="20" x2="6" y2="14"></line>
          </svg>
        </span>
        Benchmark Functions
      </h1>
      <p>Evaluate <strong>WOA</strong> and <strong>EWOA</strong> across standard mathematical test functions to assess
        convergence, exploration, and stability.</p>

      <?php if ($error_message): ?>
      <div
        style="background: rgba(220, 53, 69, 0.1); border: 1px solid #dc3545; color: #dc3545; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
        <strong>Error:</strong> <?= $error_message ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="left-column">

      <div class="step-card animate-slide-up">
        <div class="step-header">
          <div class="step-header-left">
            <div class="step-number" style="background: var(--text-light);">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="4" y1="21" x2="4" y2="14"></line>
                <line x1="4" y1="10" x2="4" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12" y2="3"></line>
                <line x1="20" y1="21" x2="20" y2="16"></line>
                <line x1="20" y1="12" x2="20" y2="3"></line>
                <line x1="1" y1="14" x2="7" y2="14"></line>
                <line x1="9" y1="8" x2="15" y2="8"></line>
                <line x1="17" y1="16" x2="23" y2="16"></line>
              </svg>
            </div>
            <h2>Setup</h2>
          </div>
        </div>

        <form id="benchmark-form" method="POST" action="">

          <div class="form-group">
            <label>Benchmark Function(s)</label>
            <div class="form-group-checkboxes">
              <input type="checkbox" id="func-griewank" name="function[]" value="griewank"
                <?= (isset($_POST['function']) && in_array('griewank', $_POST['function'])) ? 'checked' : 'checked' ?>>
              <label for="func-griewank">Griewank</label>

              <input type="checkbox" id="func-rosenbrock" name="function[]" value="rosenbrock"
                <?= (isset($_POST['function']) && in_array('rosenbrock', $_POST['function'])) ? 'checked' : '' ?>>
              <label for="func-rosenbrock">Rosenbrock</label>
            </div>
          </div>
          <hr class="form-divider">

          <button type="submit" id="run-benchmark-btn" class="btn btn-primary-full mt-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="5 3 19 12 5 21 5 3"></polygon>
            </svg>
            Run Benchmark
          </button>
        </form>

      </div>
    </div>

    <div class="right-column">

      <?php if (!$run_complete): ?>
      <div id="benchmark-results-placeholder" class="placeholder-card single-placeholder animate-slide-up">
        <div class="step-header">
          <div class="step-header-left">
            <div class="step-number" style="background: var(--text-dark); opacity: 0.5;">?</div>
            <h2>Results</h2>
          </div>
        </div>
        <div class="placeholder-content">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>
            <path d="M22 12A10 10 0 0 0 12 2v10z"></path>
          </svg>
          <h3>Ready to Start</h3>
          <p>Select functions on the left and click "Run Benchmark".</p>
        </div>
      </div>
      <?php endif; ?>

      <div id="benchmark-loader" class="step-card loader-card" style="display: none;">
        <div class="loader-inner">
          <div class="scan-loader">
            <span></span><span></span><span></span><span></span>
          </div>
          <div class="loader-caption" id="loader-caption">Running Benchmark... <br><small>This may take a
              moment.</small></div>
        </div>
      </div>

      <?php if ($run_complete && $results): ?>
      <div id="benchmark-results" class="results-grid" style="display: grid; grid-template-columns: 1fr; gap: 2rem;">

        <?php foreach ($results as $funcName => $data): ?>
        <div class="step-card animate-slide-up">
          <div class="step-header">
            <div class="step-header-left">
              <div class="step-number">1</div>
              <h2>Results: <?= ucfirst(htmlspecialchars($funcName)) ?></h2>
            </div>
          </div>

          <div class="table-wrapper-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Metric</th>
                  <th>WOA</th>
                  <th>EWOA</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (['best_mean','best_std','average_eer','convergence_rate_mean','runtime_s'] as $k): ?>
                <tr>
                  <td><?= format_metric_name($k) ?></td>
                  <td class="mono"><?= format_num($data['woa'][$k] ?? null) ?></td>
                  <td class="mono" style="color: var(--accent-glow);"><?= format_num($data['ewoa'][$k] ?? null) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if (isset($data['wilcoxon']) && isset($data['wilcoxon']['p_value'])): ?>
          <div style="padding: 1rem; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 1rem;">
            <strong style="color: #bbb;">Wilcoxon Rank-Sum Test:</strong><br>
            <div class="mono" style="margin-top: 5px;">
              p-value: <?= format_num($data['wilcoxon']['p_value'], 8) ?>
              <br>
              <?= format_significance($data['wilcoxon']['p_value']) ?>
            </div>
          </div>
          <?php endif; ?>

          <?php 
                $runKey = 'all_fitness'; 
                $woaRuns = $data['woa'][$runKey] ?? [];
                $ewoaRuns = $data['ewoa'][$runKey] ?? [];
              ?>

          <?php if (!empty($woaRuns) || !empty($ewoaRuns)): ?>
          <details class="runs-details" style="margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05);">
            <summary
              style="padding: 1rem; cursor: pointer; color: var(--accent-glow); font-size: 0.9rem; font-weight: 500;">
              View Independent Runs (30) ▼
            </summary>
            <div class="table-wrapper-scroll" style="max-height: 300px; overflow-y: auto;">
              <table class="data-table" style="font-size: 0.85rem;">
                <thead>
                  <tr>
                    <th>Run #</th>
                    <th>WOA Fitness</th>
                    <th>EWOA Fitness</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                                $count = max(count($woaRuns), count($ewoaRuns));
                                for ($i = 0; $i < $count; $i++): 
                            ?>
                  <tr>
                    <td style="color: #666;"><?= $i + 1 ?></td>
                    <td class="mono"><?= format_num($woaRuns[$i] ?? null) ?></td>
                    <td class="mono"><?= format_num($ewoaRuns[$i] ?? null) ?></td>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
          </details>
          <?php endif; ?>

        </div>
        <?php endforeach; ?>

      </div>
      <?php endif; ?>

    </div>
  </div>

  <footer>
    <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p>
  </footer>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('benchmark-form');
    const loader = document.getElementById('benchmark-loader');
    const placeholders = document.querySelectorAll('.placeholder-card');
    const results = document.getElementById('benchmark-results');
    const btn = document.getElementById('run-benchmark-btn');

    form.addEventListener('submit', () => {
      if (results) results.style.display = 'none';
      placeholders.forEach(el => el.style.display = 'none');
      loader.style.display = 'flex';
      btn.disabled = true;
      btn.innerHTML = 'Processing...';
    });
  });
  </script>

</body>

</html>
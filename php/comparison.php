<?php
session_start();

$config  = require __DIR__ . '/config.php';
$python  = $config['python_path'];
$workdir = $config['workdir'];

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
  $uploadDir = __DIR__ . '/test_uploads/';
  @mkdir($uploadDir, 0777, true);
  $imagePath = $uploadDir . basename($_FILES['image']['name']);
  move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);

  // === Correct path to compare_predict.py ===
  $cmd = sprintf(
    'PYTHONPATH=%s %s %s/woa_tool/compare_predict.py --image %s --ewoa %s/models/model_ewoa_finalfinal.json --woa %s/models/model_woa.json',
    escapeshellarg($workdir),
    escapeshellarg($python),
    escapeshellarg($workdir),
    escapeshellarg($imagePath),
    escapeshellarg($workdir),
    escapeshellarg($workdir)
  );

  exec($cmd . ' 2>&1', $output, $code);
  $raw = implode("\n", $output);
  $decoded = json_decode($raw, true);

  if ($decoded) $result = $decoded;
  else $error = "Failed to parse Python output.<br><pre>$raw</pre>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WOA vs EWOA Comparison | WOA-Tool</title>
  <link rel="stylesheet" href="style.css">
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
    <div class="header">
      <h1> WOA vs EWOA Performance Comparison</h1>
      <p>Visualize the performance improvements of the Enhanced WOA in both benchmark and feature selection contexts.</p>
    </div>

  <form method="POST" enctype="multipart/form-data" class="upload-box">
    <label><strong>Upload Mammogram Image</strong></label><br>
    <input type="file" name="image" accept="image/*" required>
    <br>
    <button type="submit">Run Comparison</button>
  </form>
  </div>

  <?php if ($error): ?>
    <div class="error"><?php echo $error; ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <table>
      <thead>
        <tr>
          <th>Algorithm</th>
          <th>Prediction</th>
          <th>Confidence</th>
          <th>Top Features</th>
          <th>Execution Time (s)</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>WOA</strong></td>
          <td><?php echo htmlspecialchars($result['WOA']['Prediction']); ?></td>
          <td><?php echo htmlspecialchars($result['WOA']['Confidence']); ?></td>
          <td><?php echo implode(', ', $result['WOA']['Top Features']); ?></td>
          <td><?php echo htmlspecialchars($result['WOA']['Execution Time']); ?></td>
        </tr>
        <tr>
          <td><strong>EWOA</strong></td>
          <td><?php echo htmlspecialchars($result['EWOA']['Prediction']); ?></td>
          <td><?php echo htmlspecialchars($result['EWOA']['Confidence']); ?></td>
          <td><?php echo implode(', ', $result['EWOA']['Top Features']); ?></td>
          <td><?php echo htmlspecialchars($result['EWOA']['Execution Time']); ?></td>
        </tr>
      </tbody>
    </table>

    <div class="runtime">
      Total Runtime: <?php echo htmlspecialchars($result['Total Runtime']); ?> seconds
    </div>
  <?php endif; ?>
</div>

</body>
</html>

<?php
require __DIR__ . '/config.php';

// index.php - Simple frontend for WOA-Tool prediction
ini_set('display_errors', 1);
error_reporting(E_ALL);

$result = null;
$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["image"])) {
    $uploadDir = __DIR__ . "/test_uploads/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = basename($_FILES["image"]["name"]);
    $targetPath = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetPath)) {
        $python = escapeshellcmd("/Users/Jae/Documents/WOA-TOOL/.venv/bin/python3");
        $cli = escapeshellarg("/Users/Jae/Documents/WOA-TOOL/woa_tool/cli.py");
        $model = escapeshellarg("/Users/Jae/Documents/WOA-TOOL/models/model.json");
        $image = escapeshellarg($targetPath);

       $cmd = build_predict_cmd($image);
        $output = shell_exec($cmd . " 2>&1");

        $decoded = json_decode($output, true);
        if ($decoded) {
            $result = $decoded;
        } else {
            $error = "Invalid JSON output:<br><pre>" . htmlspecialchars($output) . "</pre>";
        }
    } else {
        $error = "⚠️ Upload failed!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>WOA-Tool Image Prediction</title>
</head>
<body>
  <h1>WOA-Tool Image Prediction</h1>
  <form method="post" enctype="multipart/form-data">
    <label>Upload a test image (.tif):</label>
    <input type="file" name="image" accept=".tif" required>
    <button type="submit">Run Prediction</button>
  </form>
  <hr>

  <?php if ($error): ?>
    <div style="color:red;"><?php echo $error; ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <h2>Prediction Result</h2>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>Final Prediction</th><td><?php echo htmlspecialchars($result['final_prediction']); ?></td></tr>
    </table>

    <h3>Probabilities</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <?php foreach ($result['probabilities'] as $k=>$v): ?>
        <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo round($v, 4); ?></td></tr>
      <?php endforeach; ?>
    </table>

    <h3>Abnormality</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>Type</th><td><?php echo htmlspecialchars($result['abnormality_type']); ?></td></tr>
      <?php foreach ($result['abnormality_scores'] as $k=>$v): ?>
        <tr><td><?php echo htmlspecialchars($k); ?></td><td><?php echo round($v, 4); ?></td></tr>
      <?php endforeach; ?>
    </table>

    <h3>Background Tissue</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><td>Code</td><td><?php echo htmlspecialchars($result['background_tissue']['code']); ?></td></tr>
      <tr><td>Text</td><td><?php echo htmlspecialchars($result['background_tissue']['text']); ?></td></tr>
      <tr><td>Explain</td><td><?php echo htmlspecialchars($result['background_tissue']['explain']); ?></td></tr>
    </table>

    <h3>Explanations</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>Class-based</th><td>
        <?php foreach ($result['explanation']['class'] as $e) echo htmlspecialchars($e) . "<br>"; ?>
      </td></tr>
      <tr><th>Abnormality-based</th><td>
        <?php foreach ($result['explanation']['abnormality'] as $e) echo htmlspecialchars($e) . "<br>"; ?>
      </td></tr>
    </table>

    <h3>Selected Features Used</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <?php foreach ($result['selected_features_used'] as $f): ?>
        <tr><td><?php echo htmlspecialchars($f); ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</body>
</html>

<?php
session_start();
$config = require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Benchmark Functions | WOA-Tool</title>
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
      <h1> Benchmark Functions</h1>
      <p>Evaluate <strong>WOA</strong> and <strong>EWOA</strong> across standard mathematical test functions to assess convergence, exploration, and stability.</p>
    </div>
  <footer>
    <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p>
  </footer>

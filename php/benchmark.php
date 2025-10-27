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
      <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">Feature Detection</a>
      <a href="benchmark.php" class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark Functions</a>
      <a href="comparison.php" class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
    </nav>
  </div>
</header>
  <div id="aurora-background"></div>

  <div class="main-container">
    <div class="header">
        <h1>
            <span class="header-logo" style="font-size: 2.2rem; width: 60px; height: 60px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-bar-chart-2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
            </span>
            Benchmark Functions
        </h1>
      <p>Evaluate <strong>WOA</strong> and <strong>EWOA</strong> across standard mathematical test functions to assess convergence, exploration, and stability.</p>
    </div>

    <div class="left-column">
      <div class="step-card animate-slide-up">
        <div class="step-header">
          <div class="step-header-left">
            <div class="step-number" style="background: var(--text-light);">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
            </div>
            <h2>Setup</h2>
          </div>
        </div>

        <form id="benchmark-form">
          <div class="form-group">
            <label>Algorithm(s)</label>
            <div class="form-group-checkboxes">
              <input type="checkbox" id="algo-woa" name="algorithm[]" value="WOA" checked>
              <label for="algo-woa">WOA</label>
              <input type="checkbox" id="algo-ewoa" name="algorithm[]" value="EWOA">
              <label for="algo-ewoa">EWOA</label>
            </div>
          </div>

          <div class="form-group">
            <label>Benchmark Function(s)</label>
            <div class="form-group-checkboxes">
              <input type="checkbox" id="func-griewank" name="function[]" value="griewank" data-name="Griewank" checked>
              <label for="func-griewank">Griewank</label>
              <input type="checkbox" id="func-rosenbrock" name="function[]" value="rosenbrock" data-name="Rosenbrock">
              <label for="func-rosenbrock">Rosenbrock</label>
            </div>
          </div>
          <hr class="form-divider">

          <div class="form-group">
            <label for="param-agents">
              Search Agents (Population)
              <span class="tooltip-icon">?
                <span class="tooltip-content">The number of 'whales' (solutions) searching the problem space in each generation. Higher numbers increase exploration.</span>
              </span>
            </label>
            <input type="number" id="param-agents" name="agents" value="30" min="10" step="1">
          </div>

          <div class="form-group">
            <label for="param-iterations">
              Max Iterations
              <span class="tooltip-icon">?
                <span class="tooltip-content">The total number of generations the algorithm will run. A higher number allows for better convergence.</span>
              </span>
            </label>
            <input type="number" id="param-iterations" name="iterations" value="500" min="50" step="10">
          </div>

          <div class="form-group">
            <label for="param-dimensions">
              Dimensions ($D$)
              <span class="tooltip-icon">?
                <span class="tooltip-content">The number of variables ($D$) in the test function. Higher dimensions make the problem exponentially harder.</span>
              </span>
            </label>
            <input type="number" id="param-dimensions" name="dimensions" value="30" min="2" step="1">
          </div>
          
          <div class="form-group">
            <label for="param-runs">
              Number of Runs
              <span class="tooltip-icon">?
                <span class="tooltip-content">The number of times to run the algorithm(s) to get statistically stable results (e.g., Average, StdDev).</span>
              </span>
            </label>
            <input type="number" id="param-runs" name="runs" value="10" min="1" step="1">
          </div>

          <button type="submit" id="run-benchmark-btn" class="btn btn-primary-full mt-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
            Run Benchmark(s)
          </button>
        </form>

      </div>
    </div>

    <div class="right-column">
        
        <div id="benchmark-results-placeholder" class="placeholder-card single-placeholder animate-slide-up">
            <div class="step-header">
                <div class="step-header-left">
                    <div class="step-number" style="background: var(--text-dark); opacity: 0.5;">?</div>
                    <h2>Results</h2>
                </div>
            </div>
            <div class="placeholder-content">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                <h3>Waiting for Configuration</h3>
                <p>Select algorithm(s), function(s), and parameters on the left, then click "Run" to visualize performance.</p>
            </div>
        </div>

        <div id="benchmark-loader" class="step-card loader-card" style="display: none;">
            <div class="loader-inner">
                <div class="scan-loader">
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div classs="loader-caption" id="loader-caption">Running Benchmark...</div>
            </div>
        </div>
        
        <div id="benchmark-results" class="results-grid" style="display: none; grid-template-columns: 1fr; gap: 2rem;">
            
            <div class="step-card animate-slide-up">
              <div class="step-header">
                <div class="step-header-left">
                  <div class="step-number">1</div>
                  <h2>Benchmark Statistics</h2>
                </div>
                <button class="maximize-card-btn" data-modal-title="Benchmark Statistics" data-modal-type="content" data-modal-target="#stats-table-wrapper">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                </button>
              </div>
              <div class="table-wrapper-scroll" id="stats-table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Algorithm</th>
                            <th>Function</th>
                            <th>Best Fitness</th>
                            <th>Avg. Fitness</th>
                            <th>Std. Deviation</th>
                        </tr>
                    </thead>
                    <tbody id="results-table-body">
                        </tbody>
                </table>
              </div>
            </div>

            <div class="step-card animate-slide-up" style="animation-delay: 100ms;">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number">2</div>
                        <h2>Convergence Curve</h2>
                    </div>
                    <button class="maximize-card-btn" data-modal-title="Convergence Curve" data-modal-type="chart" data-modal-target="convergence-chart">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                    </button>
                </div>
                <div class="chart-container" style="height: 350px;">
                    <canvas id="convergence-chart"></canvas>
                </div>
            </div>

            <div class="step-card animate-slide-up" style="animation-delay: 200ms;">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number">3</div>
                        <h2>Function Landscape (2D)</h2>
                    </div>
                    <button class="maximize-card-btn" data-modal-title="Function Landscape (2D)" data-modal-type="chart" data-modal-target="function-plot-chart">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                    </button>
                </div>
                <p class="chart-note" id="plot-note">This visualization is a 2D contour plot, available when $D=2$.</p>
                <div class="chart-container" style="height: 350px;">
                    <canvas id="function-plot-chart"></canvas>
                </div>
            </div>
        </div>

    </div>
  </div> <div id="card-modal-overlay">
    <div id="card-modal-content">
      <button class="close-modal-btn">&times;</button>
      <h2 class="modal-title">Modal Title</h2>
      <div class="modal-body" id="card-modal-body">
        </div>
    </div>
  </div>

  <footer>
    <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p>
  </footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    
    let convergenceChartInstance = null;
    let functionPlotInstance = null;
    let modalChartInstance = null;
    let convergenceChartConfig = {};
    let plotChartConfig = {};
    
    const form = document.getElementById('benchmark-form');
    const runButton = document.getElementById('run-benchmark-btn');
    const placeholder = document.getElementById('benchmark-results-placeholder');
    const loader = document.getElementById('benchmark-loader');
    const loaderCaption = document.getElementById('loader-caption');
    const resultsContainer = document.getElementById('benchmark-results');
    const resultsTableBody = document.getElementById('results-table-body');
    const plotNote = document.getElementById('plot-note');
    const chartCtx = document.getElementById('convergence-chart').getContext('2d');
    const plotCtx = document.getElementById('function-plot-chart').getContext('2d');

    const modalOverlay = document.getElementById('card-modal-overlay');
    const modalContent = document.getElementById('card-modal-content');
    const modalTitle = modalContent.querySelector('.modal-title');
    const modalBody = modalContent.querySelector('#card-modal-body');
    const closeModalBtn = modalContent.querySelector('.close-modal-btn');

    const chartColors = ['#D81B60', '#1A3045', '#28A745', '#34495E'];

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const formData = new FormData(form);
        const selectedAlgos = Array.from(document.querySelectorAll('input[name="algorithm[]"]:checked')).map(cb => cb.value);
        const selectedFuncsData = Array.from(document.querySelectorAll('input[name="function[]"]:checked'))
                                    .map(cb => ({ value: cb.value, name: cb.dataset.name }));

        const config = {
            agents: formData.get('agents'),
            iterations: parseInt(formData.get('iterations'), 10),
            dimensions: parseInt(formData.get('dimensions'), 10),
            runs: formData.get('runs'),
        };

        if (selectedAlgos.length === 0) { alert('Please select at least one algorithm.'); return; }
        if (selectedFuncsData.length === 0) { alert('Please select at least one benchmark function.'); return; }
        
        let simulationQueue = [];
        selectedAlgos.forEach(algo => {
            selectedFuncsData.forEach(func => {
                simulationQueue.push({
                    algorithm: algo,
                    function: func.value,
                    functionName: func.name,
                    ...config
                });
            });
        });

        placeholder.style.display = 'none';
        resultsContainer.style.display = 'none';
        loader.style.display = 'flex';
        runButton.disabled = true;
        loaderCaption.textContent = `Running ${simulationQueue.length} benchmark(s)...`;

        setTimeout(() => {
            const allResults = simulationQueue.map(runConfig => generateMockResults(runConfig));

            populateResultsTable(allResults);
            renderConvergenceChart(allResults);
            renderFunctionPlot(allResults[0]);

            loader.style.display = 'none';
            resultsContainer.style.display = 'grid';
            runButton.disabled = false;

        }, 1500 + 300 * simulationQueue.length);
    });

    function generateMockResults(config) {
        let bestFitness = Math.random() * 0.001;
        let avgFitness = bestFitness + Math.random() * 0.01;
        let stdDev = Math.random() * 0.005;

        const labels = Array.from({ length: config.iterations / 10 }, (_, i) => i * 10);
        const curveData = [];
        let currentValue = 10 + Math.random() * 5;
        let convergenceRate = config.algorithm === 'EWOA' ? 0.82 : 0.85;

        for (let i = 0; i < labels.length; i++) {
            currentValue = currentValue * (convergenceRate + Math.random() * 0.1);
            curveData.push(currentValue + Math.random());
        }

        return { config, bestFitness, avgFitness, stdDev, convergenceCurve: curveData, labels };
    }

    function populateResultsTable(allResults) {
        resultsTableBody.innerHTML = '';
        allResults.forEach(result => {
            const row = `
                <tr>
                    <td><strong>${result.config.algorithm}</strong></td>
                    <td>${result.config.functionName} (${result.config.dimensions}D)</td>
                    <td class="mono">${result.bestFitness.toFixed(8)}</td>
                    <td class="mono">${result.avgFitness.toFixed(8)}</td>
                    <td class="mono">${result.stdDev.toFixed(8)}</td>
                </tr>
            `;
            resultsTableBody.innerHTML += row;
        });
    }

    function renderConvergenceChart(allResults) {
        if (convergenceChartInstance) convergenceChartInstance.destroy();

        const datasets = allResults.map((result, i) => ({
            label: `${result.config.algorithm} on ${result.config.functionName}`,
            data: result.convergenceCurve,
            borderColor: chartColors[i % chartColors.length],
            backgroundColor: chartColors[i % chartColors.length].replace(')', ', 0.1)'),
            fill: false,
            tension: 0.3,
            pointRadius: 0,
        }));

        convergenceChartConfig = {
            type: 'line',
            data: {
                labels: allResults[0].labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { type: 'logarithmic', title: { display: true, text: 'Best Fitness (Log Scale)' } }, x: { title: { display: true, text: 'Iteration' } } },
                plugins: { tooltip: { mode: 'index', intersect: false }, legend: { position: 'top' } }
            }
        };
        
        convergenceChartInstance = new Chart(chartCtx, convergenceChartConfig);
    }

    function renderFunctionPlot(firstResult) {
        if (functionPlotInstance) functionPlotInstance.destroy();
        const config = firstResult.config;

        if (config.dimensions !== 2) {
            plotNote.textContent = 'Plot is only available for 2 Dimensions. Set $D=2$ and re-run.';
            plotNote.style.color = 'var(--accent-warning)';
            plotChartConfig = null;
            return;
        }
        
        plotNote.textContent = `2D contour plot for ${config.functionName} (simulation).`;
        plotNote.style.color = 'var(--text-dark)';

        const data = [];
        const steps = 20;
        for (let x = -steps; x <= steps; x++) {
            for (let y = -steps; y <= steps; y++) {
                let z = (config.function === 'rosenbrock') ? Math.pow((1 - x), 2) + 100 * Math.pow((y - x*x), 2) : x*x + y*y;
                data.push({ x: x / (steps/5), y: y / (steps/5), v: z, r: Math.max(1, 15 - z / 30) });
            }
        }

        plotChartConfig = {
            type: 'bubble',
            data: {
                datasets: [{
                    label: `Landscape: ${config.functionName}`,
                    data: data,
                    backgroundColor: 'var(--accent-glow-tint)',
                    borderColor: 'var(--accent-glow)',
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { x: { title: { display: true, text: 'Dimension 1 (x1)' } }, y: { title: { display: true, text: 'Dimension 2 (x2)' } } },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (context) => `(x: ${context.raw.x.toFixed(2)}, y: ${context.raw.y.toFixed(2)}) - Value: ${context.raw.v.toFixed(2)}` } }
                }
            }
        };

        functionPlotInstance = new Chart(plotCtx, plotChartConfig);
    }
    
    function openModal(title, type, targetId) {
        modalTitle.textContent = title;
        modalBody.innerHTML = '';
        if (modalChartInstance) {
            modalChartInstance.destroy();
        }

        if (type === 'content') {
            const contentElement = document.querySelector(targetId);
            if (contentElement) {
                const clone = contentElement.cloneNode(true);
                modalBody.appendChild(clone);
            }
        } else if (type === 'chart') {
            let chartConfig = null;
            if (targetId === 'convergence-chart') chartConfig = convergenceChartConfig;
            if (targetId === 'function-plot-chart') chartConfig = plotChartConfig;

            if (chartConfig) {
                const chartContainer = document.createElement('div');
                chartContainer.className = 'modal-chart-container';
                chartContainer.innerHTML = '<canvas id="modal-chart-canvas"></canvas>';
                modalBody.appendChild(chartContainer);
                
                const modalChartCtx = document.getElementById('modal-chart-canvas').getContext('2d');
                modalChartInstance = new Chart(modalChartCtx, chartConfig);
            } else {
                modalBody.textContent = 'Chart data is not available (e.g., 2D plot requires D=2).';
            }
        }

        modalOverlay.classList.add('visible');
    }

    closeModalBtn.addEventListener('click', () => {
        modalOverlay.classList.remove('visible');
        if (modalChartInstance) {
            modalChartInstance.destroy();
        }
    });

    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
            modalOverlay.classList.remove('visible');
            if (modalChartInstance) {
                modalChartInstance.destroy();
            }
        }
    });

    document.addEventListener('click', (e) => {
        const maximizeBtn = e.target.closest('.maximize-card-btn');
        if (maximizeBtn) {
            const title = maximizeBtn.dataset.modalTitle;
            const type = maximizeBtn.dataset.modalType;
            const target = maximizeBtn.dataset.modalTarget;
            openModal(title, type, target);
        }
    });
});
</script>

</body>
</html>

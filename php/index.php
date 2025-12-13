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

// Radiomics CSV path (adjust if different)
$RADIOMICS_CSV = '/Volumes/JANICE/cbis-ddsm-r/data/CBIS-DDSM-R/csv/radiomics_test.csv';

// Working directory used for PYTHONPATH / proc_open (from config)
$WORKDIR = $config['workdir'] ?? getcwd();

// === Utility Helpers ===
if (!function_exists('get_workdir')) {
    function get_workdir()
    {
        global $config;
        return $config['workdir'] ?? getcwd();
    }
}

// Ensure the config has python_path; fallback try system python
if (empty($config['python_path'])) {
    $config['python_path'] = '/usr/bin/python3';
}

// Only declare this if config.php did NOT already define it
if (!function_exists('build_predict_cmd')) {
    function build_predict_cmd($imagePath)
    {
        global $config;
        $python  = escapeshellcmd($config['python_path']);
        $workdir = escapeshellarg($config['workdir']);

        // Assuming model_final_ewoa.json is the correct model for the main prediction
        $model = escapeshellarg($config['workdir'] . '/models/model_ewoa_radiomics.json');

        $image = escapeshellarg($imagePath);

        // Using the radiomics prediction path by default is handled later.
        // Keep a simple helper for backwards compatibility (not used in radiomics workflow below).
        return "PYTHONPATH=$workdir $python -m woa_tool.cli predict --model $model --image $image";
    }
}

// === Pretty Names (Keep this updated!) ===
$pretty_names = [
    // ============================
    // FIRST ORDER FEATURES
    // ============================
    "original_firstorder_10Percentile" => "10th Percentile",
    "original_firstorder_90Percentile" => "90th Percentile",
    "original_firstorder_Energy" => "Energy",
    "original_firstorder_Entropy" => "Entropy",
    "original_firstorder_InterquartileRange" => "Interquartile Range",
    "original_firstorder_Kurtosis" => "Kurtosis",
    "original_firstorder_Maximum" => "Maximum Intensity",
    "original_firstorder_MeanAbsoluteDeviation" => "Mean Absolute Deviation",
    "original_firstorder_Mean" => "Mean Intensity",
    "original_firstorder_Median" => "Median Intensity",
    "original_firstorder_Minimum" => "Minimum Intensity",
    "original_firstorder_Range" => "Intensity Range",
    "original_firstorder_RobustMeanAbsoluteDeviation" => "Robust MAD",
    "original_firstorder_RootMeanSquared" => "Root Mean Square",
    "original_firstorder_Skewness" => "Skewness",
    "original_firstorder_TotalEnergy" => "Total Energy",
    "original_firstorder_Uniformity" => "Uniformity",
    "original_firstorder_Variance" => "Variance",

    // ============================
    // GLCM FEATURES
    // ============================
    "original_glcm_Autocorrelation" => "GLCM Autocorrelation",
    "original_glcm_ClusterProminence" => "GLCM Cluster Prominence",
    "original_glcm_ClusterShade" => "GLCM Cluster Shade",
    "original_glcm_ClusterTendency" => "GLCM Cluster Tendency",
    "original_glcm_Correlation" => "GLCM Correlation",
    "original_glcm_DifferenceAverage" => "GLCM Difference Average",
    "original_glcm_DifferenceEntropy" => "GLCM Difference Entropy",
    "original_glcm_DifferenceVariance" => "GLCM Difference Variance",
    "original_glcm_Idm" => "GLCM Inverse Difference Moment",
    "original_glcm_Idmn" => "GLCM IDM Normalized",
    "original_glcm_Idn" => "GLCM IDN (Normalized)",
    "original_glcm_Imc1" => "GLCM Informational Correlation 1",
    "original_glcm_Imc2" => "GLCM Informational Correlation 2",
    "original_glcm_InverseVariance" => "GLCM Inverse Variance",
    "original_glcm_JointAverage" => "GLCM Joint Average",
    "original_glcm_JointEnergy" => "GLCM Joint Energy",
    "original_glcm_JointEntropy" => "GLCM Joint Entropy",
    "original_glcm_MCC" => "GLCM Max Correlation Coefficient",
    "original_glcm_MaximumProbability" => "GLCM Maximum Probability",
    "original_glcm_SumAverage" => "GLCM Sum Average",
    "original_glcm_SumEntropy" => "GLCM Sum Entropy",
    "original_glcm_SumSquares" => "GLCM Sum of Squares",

    // ============================
    // GLDM FEATURES
    // ============================
    "original_gldm_DependenceEntropy" => "GLDM Dependence Entropy",
    "original_gldm_DependenceNonUniformity" => "GLDM Dependence Non-Uniformity",
    "original_gldm_DependenceNonUniformityNormalized" => "GLDM Dependence Non-Uniformity Normalized",
    "original_gldm_DependenceVariance" => "GLDM Dependence Variance",
    "original_gldm_GrayLevelNonUniformity" => "GLDM Gray Level Non-Uniformity",
    "original_gldm_GrayLevelVariance" => "GLDM Gray Level Variance",
    "original_gldm_HighGrayLevelEmphasis" => "GLDM High Gray Level Emphasis",
    "original_gldm_LargeDependenceEmphasis" => "GLDM Large Dependence Emphasis",
    "original_gldm_LargeDependenceLowGrayLevelEmphasis" => "GLDM Large Dependence Low Gray Level Emphasis",
    "original_gldm_LowGrayLevelEmphasis" => "GLDM Low Gray Level Emphasis",
    "original_gldm_SmallDependenceEmphasis" => "GLDM Small Dependence Emphasis",
    "original_gldm_SmallDependenceHighGrayLevelEmphasis" => "GLDM Small Dependence High Gray Level Emphasis",
    "original_gldm_SmallDependenceLowGrayLevelEmphasis" => "GLDM Small Dependence Low Gray Level Emphasis",

    // ============================
    // GLRLM FEATURES
    // ============================
    "original_glrlm_GrayLevelNonUniformity" => "GLRLM Gray Level Non-Uniformity",
    "original_glrlm_GrayLevelNonUniformityNormalized" => "GLRLM GLN Normalized",
    "original_glrlm_GrayLevelVariance" => "GLRLM Gray Level Variance",
    "original_glrlm_HighGrayLevelRunEmphasis" => "GLRLM High GR Run Emphasis",
    "original_glrlm_LongRunEmphasis" => "GLRLM Long Run Emphasis",
    "original_glrlm_LongRunHighGrayLevelEmphasis" => "GLRLM Long Run High GR Emphasis",
    "original_glrlm_LongRunLowGrayLevelEmphasis" => "GLRLM Long Run Low GR Emphasis",
    "original_glrlm_LowGrayLevelRunEmphasis" => "GLRLM Low Gray Run Emphasis",
    "original_glrlm_RunEntropy" => "GLRLM Run Entropy",
    "original_glrlm_RunLengthNonUniformity" => "GLRLM RL Non-Uniformity",
    "original_glrlm_RunLengthNonUniformityNormalized" => "GLRLM RL Non-Uniformity Normalized",
    "original_glrlm_RunPercentage" => "GLRLM Run Percentage",
    "original_glrlm_RunVariance" => "GLRLM Run Variance",
    "original_glrlm_ShortRunEmphasis" => "GLRLM Short Run Emphasis",
    "original_glrlm_ShortRunHighGrayLevelEmphasis" => "GLRLM Short Run High GR Emphasis",
    "original_glrlm_ShortRunLowGrayLevelEmphasis" => "GLRLM Short Run Low GR Emphasis",

    // ============================
    // GLSZM FEATURES
    // ============================
    "original_glszm_GrayLevelNonUniformity" => "GLSZM Gray Level Non-Uniformity",
    "original_glszm_GrayLevelVariance" => "GLSZM Gray Level Variance",
    "original_glszm_HighGrayLevelZoneEmphasis" => "GLSZM High Gray Level Zone Emphasis",
    "original_glszm_LargeAreaEmphasis" => "GLSZM Large Area Emphasis",
    "original_glszm_LargeAreaHighGrayLevelEmphasis" => "GLSZM Large Area High GR Emphasis",
    "original_glszm_LargeAreaLowGrayLevelEmphasis" => "GLSZM Large Area Low GR Emphasis",
    "original_glszm_SizeZoneNonUniformity" => "GLSZM Size Zone Non-Uniformity",
    "original_glszm_SizeZoneNonUniformityNormalized" => "GLSZM SZN Normalized",
    "original_glszm_SmallAreaEmphasis" => "GLSZM Small Area Emphasis",
    "original_glszm_SmallAreaHighGrayLevelEmphasis" => "GLSZM Small Area High GR Emphasis",
    "original_glszm_SmallAreaLowGrayLevelEmphasis" => "GLSZM Small Area Low GR Emphasis",
    "original_glszm_ZoneEntropy" => "GLSZM Zone Entropy",
    "original_glszm_ZonePercentage" => "GLSZM Zone Percentage",
    "original_glszm_ZoneVariance" => "GLSZM Zone Variance",

    // ============================
    // NGTDM FEATURES
    // ============================
    "original_ngtdm_Busyness" => "NGTDM Busyness",
    "original_ngtdm_Coarseness" => "NGTDM Coarseness",
    "original_ngtdm_Complexity" => "NGTDM Complexity",
    "original_ngtdm_Contrast" => "NGTDM Contrast"
];

// === Standard PHP Setup ===
ob_start();

if (!empty($_POST['ajax'])) {
    // AJAX mode: don't spam notices to JSON
    ini_set('display_errors', 0);
    error_reporting(E_ERROR | E_PARSE);
} else {
    // Normal page request
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

$result = null;
$error = null;
$uploadedImageWebPath = null;
$isDebug = isset($_GET['debug']);
$debug_pack = null;

// Helper: create one-row CSV from master radiomics CSV for a given base_id
if (!function_exists('make_one_row_radiomics_csv')) {
    /**
     * Tries to find a row in $radiomics_csv where the first path component equals $base_id.
     * On success writes a CSV with header+single row to $out_csv and returns true.
     * This is a simple PHP-based fallback for matching by base id.
     */
    function make_one_row_radiomics_csv($radiomics_csv, $base_id, $out_csv)
    {
        if (!is_file($radiomics_csv)) return false;
        $fh = fopen($radiomics_csv, 'r');
        if (!$fh) return false;
        $header = fgetcsv($fh);
        if ($header === false) { fclose($fh); return false; }

        $matchedRow = null;
        while (($row = fgetcsv($fh)) !== false) {
            // Try to extract image path field (ends with .dcm or path)
            // We assume a column named 'image_file_path' exists; fallback to scanning cols.
            if (($idx = array_search('image_file_path', $header)) !== false) {
                $path = $row[$idx] ?? '';
            } else {
                // fallback: search any column containing ".dcm"
                $path = '';
                foreach ($row as $c) {
                    if (strpos($c, '.dcm') !== false) { $path = $c; break; }
                }
            }
            if (!$path) continue;
            $parts = explode('/', $path);
            $candidate = $parts[0] ?? '';
            if ($candidate === $base_id) {
                $matchedRow = $row;
                break;
            }
        }
        fclose($fh);

        if (!$matchedRow) return false;

        // Write out CSV with header + single matched row
        @mkdir(dirname($out_csv), 0777, true);
        $of = fopen($out_csv, 'w');
        if (!$of) return false;
        fputcsv($of, $header);
        fputcsv($of, $matchedRow);
        fclose($of);
        return file_exists($out_csv);
    }
}

// === Handle Upload + Prediction ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {

    // Basic upload checks
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error code: " . $_FILES['image']['error'];
    } elseif ($_FILES['image']['size'] == 0) {
        $error = "Uploaded file is empty.";
    } elseif (!is_uploaded_file($_FILES['image']['tmp_name'])) {
        $error = "Possible file upload attack.";
    } else {
        // Normalize filename: unique prefix + sanitized original name
        $orig_name = basename($_FILES['image']['name']);
        $fileName = uniqid('img_', true) . '-' .
            preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $orig_name);
        $targetPath = $upload_dir . '/' . $fileName;

        // We will also save the full DICOM in a different folder (preserve original)
        $full_dir = __DIR__ . '/full_uploads';
        @mkdir($full_dir, 0777, true);
        $target_full = $full_dir . '/' . $fileName; // full DICOM copy
        // Save original to full_uploads, copy preview into test_uploads (converted client-side to preview)
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_full)) {

    // Create browser-preview copy (just the original file)
    copy($target_full, $targetPath);

$resp = [];   // <--- ADD THIS LINE

// Build web path for raw file (not the preview)
$uploaded_web_path = 'test_uploads/' . basename($targetPath);
$resp['image'] = $uploaded_web_path;
$uploadedImageWebPath = $uploaded_web_path;   

// ===============================
// DEBUG: Preview generation
// ===============================

// Verify paths
$debug_info = [];
$debug_info['python_path'] = $config['python_path'];
$debug_info['workdir']     = $WORKDIR;
$debug_info['uploaded_full_exists'] = file_exists($target_full);
$debug_info['upload_dir_writable']  = is_writable($upload_dir);

// Build preview PNG path
$preview_png = $upload_dir . '/preview_' . uniqid() . '.png';
$debug_info['preview_png_path'] = $preview_png;

// Build command
$cmd_preview =
    "PYTHONPATH=" . escapeshellarg($WORKDIR) . " " .
    escapeshellcmd($config['python_path']) .
    " -m woa_tool.cbis_overlay_from_upload " .
    " --preview-only " .
    " --uploaded " . escapeshellarg($target_full) .
    " --out " . escapeshellarg($preview_png);

// Execute with stderr capture
$preview_output = [];
$preview_return = 0;

exec($cmd_preview . " 2>&1", $preview_output, $preview_return);

$debug_info['cmd_preview']      = $cmd_preview;
$debug_info['preview_stdout']   = implode("\n", $preview_output);
$debug_info['preview_exitcode'] = $preview_return;
$debug_info['png_exists_after'] = file_exists($preview_png);

// Attach debug into AJAX response:
$resp['preview_debug'] = $debug_info;

// Normal behavior
if (file_exists($preview_png)) {
    $resp['preview'] = 'test_uploads/' . basename($preview_png);
} else {
    $resp['preview'] = null;
}


    // ============================================================
    // DETERMINE ONE-ROW RADIOMICS CSV FOR PREDICTION
    // ============================================================

            $tmp_csv = $WORKDIR . '/tmp/radiomics_one_' . uniqid() . '.csv';
            @mkdir(dirname($tmp_csv), 0777, true);

            $has_base_from_name = false;
            $base_id = '';

            // Try to infer base_id from filename if possible (Calc- or Mass- or full base)
            if (preg_match('/^(Calc|Mass)-[A-Za-z0-9_\-]+/i', $orig_name, $m)) {
                // If filename includes a base like Calc-Training_P_00005_RIGHT_MLO or similar
                $base_id = explode('.', $orig_name)[0]; // approximate
            } else {
                // Try to parse a CBIS-style base from the file's DICOM path heuristics:
                // If user uploaded a file that is inside a CBIS folder, we may extract the case
                $parts = explode('/', $target_full);
                // Try to find a token starting with Calc- or Mass-
                foreach ($parts as $p) {
                    if (preg_match('/^(Calc|Mass)-/', $p)) {
                        $base_id = $p;
                        break;
                    }
                }
            }

            // Try simple matching first (PHP helper)
            if ($base_id) {
                $matched = make_one_row_radiomics_csv($RADIOMICS_CSV, $base_id, $tmp_csv);
                if ($matched) {
                    $has_base_from_name = true;
                } else {
                    $has_base_from_name = false;
                }
            }

            // If no simple match → run Python matcher
            if (!$has_base_from_name) {
                $python_esc  = escapeshellcmd($config['python_path']);
                $workdir_esc = escapeshellarg($config['workdir']);
                $uploaded_esc = escapeshellarg($target_full);
                $radiomics_esc = escapeshellarg($RADIOMICS_CSV);
                $tmpcsv_esc = escapeshellarg($tmp_csv);

                $cmd_match = "PYTHONPATH={$workdir_esc} {$python_esc} -m woa_tool.match_radiomics_row "
                           . "--uploaded {$uploaded_esc} "
                           . "--radiomics {$radiomics_esc} "
                           . "--out {$tmpcsv_esc}";

                $desc = [
                    0 => ['pipe','r'],
                    1 => ['pipe','w'],
                    2 => ['pipe','w'],
                ];

                $proc = proc_open($cmd_match, $desc, $pipes, $WORKDIR);

                if (!is_resource($proc)) {
                    // Clean up
                    @unlink($target_full);
                    @unlink($targetPath);
                    $error = "proc_open failed for matcher script. Check PHP configuration (disable_functions).";
                    // On AJAX call we should return JSON; fall through to AJAX handler below.
                } else {
                    fclose($pipes[0]);
                    $m_stdout = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    $m_stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                    $m_exit = proc_close($proc);

                    if ($m_exit !== 0 || !file_exists($tmp_csv)) {
                        @unlink($target_full);
                        @unlink($targetPath);
                        $error = "Failed to match uploaded DICOM to radiomics CSV. Stderr: " . htmlspecialchars($m_stderr);
                    } else {
                        // success; the tmp csv exists
                    }
                }
            }

            // If an error occurred during matching, $error contains a message.
            if (empty($error)) {
                // At this point $tmp_csv contains exactly 1 row for prediction
                $targetCsv = $tmp_csv;

    // ============================================================
// EXTRACT base_id from the matched 1-row radiomics CSV
// ============================================================

if (file_exists($targetCsv)) {
    $fh = fopen($targetCsv, 'r');
    $header = fgetcsv($fh);
    $row = fgetcsv($fh);
    fclose($fh);

    // Find the radiomics "image_file_path" column
    $idx = array_search('image_file_path', $header);

    if ($idx !== false && !empty($row[$idx])) {
        // Example value:
        // "Calc-Test_P_00038_LEFT_CC/1.3.6.1.4.1.XXXXXX/000000.dcm"
        $parts = explode('/', $row[$idx]);
        $base_id = $parts[0];      // <-- EXACT CBIS folder name
// -----------------------------
// CALL describe_lesion.py (robust)
// -----------------------------
$python_esc  = escapeshellcmd($config['python_path']);
$workdir_esc = escapeshellarg($config['workdir']);
$baseid_esc  = escapeshellarg($base_id);
$tmpcsv_esc  = isset($targetCsv) ? escapeshellarg($targetCsv) : '';

$cmd_desc = "PYTHONPATH={$workdir_esc} {$python_esc} -m woa_tool.describe_lesion --base-id {$baseid_esc}";
if ($tmpcsv_esc) $cmd_desc .= " --radiomics {$tmpcsv_esc}";

$des_desc = [
    0 => ['pipe','r'],
    1 => ['pipe','w'],
    2 => ['pipe','w'],
];

$proc_desc = proc_open($cmd_desc, $des_desc, $pipes_desc, $WORKDIR);

$desc_data = null;
if (is_resource($proc_desc)) {
    fclose($pipes_desc[0]);
    $stdout_desc = stream_get_contents($pipes_desc[1]);
    fclose($pipes_desc[1]);
    $stderr_desc = stream_get_contents($pipes_desc[2]);
    fclose($pipes_desc[2]);
    $exit_desc = proc_close($proc_desc);

    if ($exit_desc === 0 && !empty($stdout_desc)) {
        $decoded = json_decode($stdout_desc, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $desc_data = $decoded;
        } else {
            // fallback: try to salvage partial JSON by trimming
            $trim = trim(preg_replace('/[^\x20-\x7E\s]+/','', $stdout_desc));
            $decoded2 = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2)) {
                $desc_data = $decoded2;
            } else {
                // decoding failed; keep desc_data null but preserve stderr for debug
                if ($isDebug) {
                    $debug_pack['describe_stdout'] = $stdout_desc;
                    $debug_pack['describe_stderr'] = $stderr_desc;
                    $debug_pack['describe_cmd'] = $cmd_desc;
                    $debug_pack['describe_exit'] = $exit_desc;
                }
            }
        }
    } else {
        // no JSON or non-zero exit: record debug info if debug mode
        if ($isDebug) {
            $debug_pack['describe_stdout'] = $stdout_desc;
            $debug_pack['describe_stderr'] = $stderr_desc;
            $debug_pack['describe_cmd'] = $cmd_desc;
            $debug_pack['describe_exit'] = $exit_desc;
        }
    }
} else {
    if ($isDebug) {
        $debug_pack['describe_error'] = 'proc_open failed for describe_lesion';
        $debug_pack['describe_cmd'] = $cmd_desc;
    }
}

// Map results into variables used later by PHP + frontend
$meta_data = null;
$lesion_narrative = null;
$lesion_birads = null;

if (is_array($desc_data)) {
    // Standard keys: metadata, narrative, birads, base_id
    if (isset($desc_data['metadata']) && is_array($desc_data['metadata'])) {
        $meta_data = $desc_data['metadata'];
    }
    if (isset($desc_data['narrative']) && is_string($desc_data['narrative'])) {
        $lesion_narrative = trim($desc_data['narrative']);
    }
    if (isset($desc_data['birads']) && $desc_data['birads'] !== '') {
        $lesion_birads = (string)$desc_data['birads'];
    }

    // If nothing found, keep them null (frontend will show "No lesion metadata available.")
}

    }
}

// If STILL no base_id → overlay CANNOT proceed
if (empty($base_id)) {
    $error = "ERROR: base_id could not be determined from radiomics CSV.";
}


// ============================================================
// Generate ROI Overlay PNG
// ============================================================
$overlay_png = $upload_dir . '/overlay_' . uniqid() . '.png';

$cmd_overlay =
    "PYTHONPATH=" . escapeshellarg($config['workdir']) . " " .
    escapeshellcmd($config['python_path']) .
    " -m woa_tool.cbis_overlay_from_upload " .
    " --uploaded " . escapeshellarg($target_full) .
    " --base-id " . escapeshellarg($base_id) .
    " --out " . escapeshellarg($overlay_png);

$overlay_output = shell_exec($cmd_overlay);

if (file_exists($overlay_png)) {
    $resp['overlay'] = 'test_uploads/' . basename($overlay_png);
} else {
    $resp['overlay'] = null;
}


                // ============================================================
                // RUN REAL PREDICTION (predict-radiomics)
                // ============================================================
                if (empty($_POST['mock'])) {

                    $cmd = "PYTHONPATH=" . escapeshellarg($config['workdir'])
                         . " " . escapeshellcmd($config['python_path'])
                         . " -m woa_tool.cli predict-radiomics "
                         . "--model " . escapeshellarg($config['workdir'] . '/models/model_ewoa_radiomics.json')
                         . " --csv " . escapeshellarg($targetCsv);

                    $desc = [
                        0 => ['pipe','r'],
                        1 => ['pipe','w'],
                        2 => ['pipe','w'],
                    ];

                    $proc = proc_open($cmd, $desc, $pipes, $WORKDIR);

                    if (!is_resource($proc)) {
                        $error = "proc_open failed — check PHP disable_functions and permissions.";
                    } else {
                        fclose($pipes[0]);
                        $stdout = stream_get_contents($pipes[1]);
                        fclose($pipes[1]);
                        $stderr = stream_get_contents($pipes[2]);
                        fclose($pipes[2]);
                        $code = proc_close($proc);

                        $decoded = json_decode($stdout, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {

                            // Normalize abnormality_type
                            if (!isset($decoded['abnormality_type'])) {
                                if (isset($decoded['abnormality']['type'])) {
                                    $decoded['abnormality_type'] = $decoded['abnormality']['type'];
                                } elseif (isset($decoded['abnormality'])) {
                                    $decoded['abnormality_type'] = is_array($decoded['abnormality'])
                                        ? ($decoded['abnormality']['label'] ?? null)
                                        : $decoded['abnormality'];
                                } elseif (isset($decoded['lesion_type'])) {
                                    $decoded['abnormality_type'] = $decoded['lesion_type'];
                                }
                            }

$result = $decoded;

// Attach metadata, narrative, and BI-RADS from describe_lesion.py
if (!empty($meta_data)) {
    $result['lesion_metadata'] = $meta_data;
}

if (!empty($lesion_narrative)) {
    $result['lesion_narrative'] = $lesion_narrative;
}

if (!empty($lesion_birads)) {
    $result['lesion_birads'] = $lesion_birads;
}


                        } else {
                            $jsonErrorMsg = json_last_error_msg();
                            $error = "Model did not return valid JSON (Error: $jsonErrorMsg).";

                            if (!empty($stderr) || $code !== 0 || !empty($stdout)) {
                                $error .= "<br>Exit Code: " . htmlspecialchars($code);
                                if (!empty($stderr)) $error .= "<br>Stderr: <pre>" . htmlspecialchars($stderr) . "</pre>";
                                if (!empty($stdout)) $error .= "<br>Raw Stdout: <pre>" . htmlspecialchars($stdout) . "</pre>";
                            }
                        }

                        if ($isDebug) {
                            $model_path = $config['workdir'] . '/models/model_ewoa_radiomics.json';
                            // Fill this if you want a debug pack
                            $debug_pack = [
                                'model_path' => $model_path,
                                'cmd'        => $cmd,
                                'exit_code'  => $code,
                                'stderr'     => $stderr,
                                'raw_stdout' => $stdout,
                                'matcher_stdout' => $m_stdout ?? null,
                                'matcher_stderr' => $m_stderr ?? null,
                            ];
                        }
                    }
                } else {
                    // Mock mode: produce a fake result or leave $result null and let frontend fallback
                    $result = $result ?? null;
                }
            } // end empty($error)

        } else {
            $error = "Failed to move uploaded file. Check permissions for '$upload_dir'. Error code: " . ($_FILES['image']['error'] ?? 'unknown');
        }
    }
}

// === AJAX Response (no HTML) ===
if (!empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $response = [
        'ok'      => !$error,
        'error'   => $error ?? null,
        'result'  => $result ?? null,
        'image'   => $uploadedImageWebPath ?? null,
        'preview' => $resp['preview'] ?? null,   // <-- ADD THIS
        'overlay' => $resp['overlay'] ?? null,   // <-- ADD THIS
        'debug'   => $debug_pack ?? null,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// === END AJAX Handling ===

// These are injected into JS:
$jsonData        = $result ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
$jsonPrettyNames = json_encode($pretty_names);
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
    <title>WOA & EWOA Breast Cancer Feature Detection</title>

    <link rel="icon"
          href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐳</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=31">

    <!-- TIFF support (if user uploads .tif/.tiff) -->
    <script src="https://cdn.jsdelivr.net/npm/tiff.js@1.0.0/tiff.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // Make PHP data available to your JavaScript
        window.__PREDICT__        = <?php echo $jsonData; ?>;
        window.__UPLOADED_IMAGE__ = <?php echo json_encode($uploadedImageWebPath ?: null); ?>;
        window.__PRETTY_NAMES__   = <?php echo $jsonPrettyNames; ?>;
    </script>
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
                    class="<?= basename($_SERVER['PHP_SELF']) == 'benchmark.php' ? 'active' : '' ?>">Benchmark
                    Functions</a>
                <a href="comparison.php"
                    class="<?= basename($_SERVER['PHP_SELF']) == 'comparison.php' ? 'active' : '' ?>">Comparison</a>
            </nav>
        </div>
    </header>

    <div id="aurora-background"></div>

    <div class="main-container">
        <header class="header">
            <h1> <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                    <path
                        d="M12 2C10.14 2 8.5 3.65 8.5 5.5C8.5 6.4 8.89 7.2 9.5 7.82C7.03 8.35 5.3 10.13 5.3 12.39C5.3 13.53 5.79 14.58 6.6 15.35C5.59 16.32 5 17.58 5 19C5 21.21 6.79 23 9 23C10.86 23 12.5 21.35 12.5 19.5C12.5 18.6 12.11 17.8 11.5 17.18C13.97 16.65 15.7 14.87 15.7 12.61C15.7 11.47 15.21 10.42 14.4 9.65C15.41 8.68 16 7.42 16 6C16 3.79 14.21 2 12 2M12 4C13.1 4 14 4.9 14 6C14 7.03 13.2 7.9 12.18 7.97C12.12 7.99 12.06 8 12 8C10.9 8 10 7.1 10 6C10 4.9 10.9 4 12 4M9 21C7.9 21 7 20.1 7 19C7 17.97 7.8 17.1 8.82 17.03C8.88 17.01 8.94 17 9 17C10.1 17 11 17.9 11 19C11 20.1 10.1 21 9 21" />
                </svg> EWOA Breast Cancer Feature Detection </h1>
            <div class="quick-guide">
                <h3> <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                    </svg> Quick Start Guide </h3>
                <ul>
                    <li><strong>Step 1:</strong> Upload mammogram (<code>.png</code>, <code>.jpg</code>,
                        <code>.tif</code>).</li>
                    <li><strong>Step 2:</strong> Click <strong>Run Prediction</strong>.</li>
                    <li><strong>Step 3:</strong> View results.</li>
                    <li><strong>Step 4:</strong> Check <strong>History</strong> for past runs.</li>
                </ul>
            </div>
        </header>

        <div class="left-column">
            <!-- NEW STICKY CONTROLS -->
            <div class="sticky-controls">
                <button type="button" id="toggle-sticky-btn" class="btn btn-secondary btn-small"
                    title="Toggle Sticky Panel" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                        viewBox="0 0 16 16" class="pin-icon">
                        <path
                            d="M4.146.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L5.793 3 4.146 1.354a.5.5 0 0 1 0-.708zm0 15.708a.5.5 0 0 1 .708 0l2-2a.5.5 0 0 1 0-.708l-2-2a.5.5 0 0 1-.708.708L5.793 13l-1.647-1.646a.5.5 0 0 1 0-.708zM1.146 8.354a.5.5 0 0 1 0-.708l2-2a.5.5 0 0 1 .708.708L2.207 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2zM4 8a.5.5 0 0 1 .5-.5h7.5a.5.5 0 0 1 0 1H4.5A.5.5 0 0 1 4 8z" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                        viewBox="0 0 16 16" class="unpin-icon" style="display:none;">
                        <path
                            d="M4.146.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1 0 .708l-2 2a.5.5 0 0 1-.708-.708L5.793 3 4.146 1.354a.5.5 0 0 1 0-.708zM8 4a.5.5 0 0 1 .5.5v7.5a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4zM1.146 8.354a.5.5 0 0 1 0-.708l2-2a.5.5 0 0 1 .708.708L2.207 8l1.647 1.646a.5.5 0 0 1-.708.708l-2-2zM4 8a.5.5 0 0 1 .5-.5h7.5a.5.5 0 0 1 0 1H4.5A.5.5 0 0 1 4 8z" />
                    </svg>
                    <span id="sticky-btn-text">Pin Panel</span>
                </button>
            </div>

            <div class="step-card">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number">1</div>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg> Upload Image</h2>
                    </div>
                    <span class="tooltip-icon">i<span class="tooltip-content">Accepted formats: .dcm, .tif, .tiff, .png, .jpg,
                            .jpeg. Size limit depends on server config.</span></span>
                </div>
<form id="image-upload-form" method="post" enctype="multipart/form-data">

    <!-- MAIN PREVIEW -->
    <div id="image-preview-wrapper" style="display: none;">
        <button type="button" class="maximize-btn" title="Maximize Image" aria-label="Maximize">
            <svg ...></svg>
        </button>
        <canvas></canvas>
        <p id="image-filename" class="file-meta" style="display:none;"></p>
    </div>

    <!-- ✅ NEW: Overlay goes HERE, under preview -->
    <div id="overlay-preview-wrapper" 
         style="display:none; margin-top:1rem; text-align:center;">
         
        <h4 style="margin-bottom:0.5rem;">ROI Overlay</h4>
        <img id="overlay-preview-img" 
             src="" 
             style="max-width:100%; border-radius:8px;">
    </div>

    <!-- Lesion textual description (metadata + narrative) -->


    <!-- UPLOAD AREA -->
    <div class="upload-area" id="upload-area">
        <input type="file" id="file-input" name="image" accept="*/*">
        <svg class="upload-area__icon" ...></svg>
        <p class="upload-area__text">Drag & Drop image file or <span>browse</span> to upload.</p>
    </div>

</form>
            </div>
            <div class="step-card text-center">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number">2</div>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" />
                            </svg> Run Analysis</h2>
                    </div>
                </div>
                <p style="color:var(--text-dark); margin-bottom: 2rem;">Once image selected, button active.</p>
                <button class="btn" type="submit" id="submit-btn" disabled form="image-upload-form"> <span
                        id="btn-text">Run Prediction</span>
                    <div class="spinner" id="spinner" style="display:none;"></div>
                </button>
                <button class="btn btn-secondary" type="button" id="clear-btn"
                    style="margin-top:0; margin-left:.75rem; display: none;">↺ Reset</button>
            </div>

            <!-- NEW HISTORY CARD -->
            <div class="step-card" id="history-card">
                <div class="step-header">
                    <div class="step-header-left">
                        <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" style="width: 20px; height: 20px; color: white;">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.183m-4.993 0H2.985" />
                            </svg>
                        </div>
                        <h2>History</h2>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" id="clear-history-btn"
                        title="Clear All History" style="display: none;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12.54 0c-.265.11-.506.227-.745.357m0 0l-1.473 1.473a.875.875 0 000 1.238l9.19 9.19a.875.875 0 001.238 0l1.473-1.473m-7.407-13.87c.19-.148.39-.287.6-.41m0 0l4.773 4.773" />
                        </svg>

                    </button>
                </div>
                <div class="card-content" id="history-list-container">
                    <!-- History items will be injected here by JS -->
                    <p class="file-meta" id="history-placeholder" style="text-align:center; padding: 1rem 0;">No history
                        saved.</p>
                    <div id="history-list"></div>
                </div>
            </div>
            <!-- END HISTORY CARD -->

            <div id="error-container"></div>
        </div>

        <div class="right-column">

            <div id="results-placeholder" style="display: block;">
                <div class="step-card placeholder-card single-placeholder">
                    <div class="step-header">
                        <div class="step-header-left">
                            <div class="step-number" style="background-color: var(--text-dark); box-shadow: none;"> <svg
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                    stroke="currentColor" style="width: 20px; height: 20px; color: white;">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                </svg> </div>
                            <h2>Results Preview</h2>
                        </div>
                    </div>
                    <div class="placeholder-content"> <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 1.085-1.085-1.085m1.085 1.085L5.25 16.5m7.5 0l-1 1.085m0 0l-1.085-1.085m1.085 1.085L18.75 16.5m-7.5 2.25h.008v.008H11.25v-.008zM12 3.75h.008v.008H12V3.75z" />
                        </svg>
                        <p>Analysis results will be displayed here after running the prediction.</p>
                    </div>
                </div>
            </div>

            <div class="skeleton-container animate-slide-up" id="skeleton-loader" style="display: none;">
                <div class="step-card loader-card">
                    <div class="loader-inner">
                        <div class="scan-loader"> <span></span><span></span><span></span><span></span> </div>
                        <p class="loader-caption">Analyzing mammogram... please wait</p>
                    </div>
                </div>
            </div>

            <div class="results-container animate-slide-up" id="results-container" style="display:none;">
                <div class="step-card">
                    <div class="step-header">
                        <div class="step-header-left">
                            <div class="step-number">3</div>
                            <h2><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg> View Results</h2>
                        </div>
                        <div class="header-buttons">
                            <button type="button" class="btn btn-print" id="print-btn" title="Print Report"> <svg
                                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="1.8" width="20" height="20" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M7 9V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v4" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M6 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-1" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 14h10v7H7z" />
                                </svg> <span>Print Results</span> </button>
                            <button type="button" class="btn btn-csv" id="csv-btn" title="Download CSV"> <svg
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg> <span>Download CSV</span> </button>
                        </div>
                    </div>

                    <div class="results-grid" id="results-grid">

                        <!-- === UPDATED: Prediction Card (Combined) === -->
                        <div class="step-card prediction-card animate-slide-up" id="prediction-card-content">
                            <div class="step-header">
                                <div class="step-header-left">
                                    <h2 style="padding-left:0;">Final Prediction <span
                                            class="pill pill-rule">Rule-based</span></h2>
                                </div>
                                <button type="button" class="maximize-card-btn" title="Maximize"> <svg
                                        xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                        viewBox="0 0 16 16">
                                        <path
                                            d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" />
                                    </svg> </button>
                            </div>
                            <!-- MODIFIED: Layout changed to flex row -->
                            <div class="card-content prediction-content-layout" style="display: flex; justify-content: space-around; align-items: center; gap: 2rem; flex-wrap: wrap;">

                                <!-- Left side: Text & Details -->
                                <div class="prediction-details-section" style="margin: 0; flex: 1 1 45%; min-width: 250px; text-align: center;">
                                    <div class="prediction-text-wrapper" style="justify-content: center;">
                                        <span class="prediction-indicator"></span>
                                        <span style="font-size:3.5rem; font-weight:800;"
                                            data-field="final_prediction">—</span>
                                    </div>
                                    <!-- REMOVED TOGGLE WRAPPER AND DECISION-DETAILS DIV -->
                                </div>

                                <div id="lesion-description-block" style="display:none; margin-top: 1rem;" class="step-card">
  <div class="step-header">
    <div class="step-header-left">
      <h2>Lesion Description</h2>
    </div>
  </div>
  <div class="card-content" id="lesion-description-content">
    <!-- Filled by JS -->
  </div>
</div>

<!--
<div id="probability-summary-root" style="flex: 1 1 45%; min-width: 300px;">
    
    <div class="feature-summary-item">
        <span class="metric-label">Lesion Type</span>
        <span class="metric-value" data-field="lesion_type">N/A</span>
    </div>

    <div class="feature-summary-item">
        <span class="metric-label">Lesion Shape</span>
        <span class="metric-value" data-field="lesion_shape">N/A</span>
    </div>

    <div class="feature-summary-item">
        <span class="metric-label">Lesion Margins</span>
        <span class="metric-value" data-field="lesion_margins">N/A</span>
    </div>

    <div class="feature-summary-item">
        <span class="metric-label">Assessment</span>
        <span class="metric-value" data-field="lesion_assessment">N/A</span>
    </div>

    <div class="feature-summary-item">
        <span class="metric-label">Subtlety</span>
        <span class="metric-value" data-field="lesion_subtlety">N/A</span>
    </div>

    <div class="feature-summary-item">
        <span class="metric-label">Suggested BI-RADS</span>
        <span class="metric-value" data-field="lesion_birads">N/A</span>
    </div>

</div>
 -->
                                
                            </div>
                        </div>
                        <!-- === END Prediction Card === -->
                        
                        <!-- === START: Wrapper for side-by-side feature cards === -->
                        <div class="wide-card-container">

                            <!-- === NEW: All Detected Features (Tables) Card === -->
                            <div class="step-card animate-slide-up" id="all-features-tables-card"
                                style="animation-delay:.40s;">
                                <div class="step-header">
                                    <div class="step-header-left">
                                        <h2>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                                            </svg>
                                            Selected Features (Tables)
                                        </h2>
                                    </div>
                                    <span class="tooltip-icon">i<span class="tooltip-content">All contributing features from your Python script's `all_detected_delta` output. Sorted from most Benign-leaning (positive) to most Malignant-leaning (negative).</span></span>
                                    <button type="button" class="maximize-card-btn" title="Maximize"><svg
                                            xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            viewBox="0 0 16 16">
                                            <path
                                                d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" />
                                        </svg></button>
                                </div>
                                <div class="card-content">
                                    <div class="all-features-table-wrapper">
                                        <div class="table-wrapper-scroll" id="all-features-table-scroll">
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>Feature</th>
                                                        <th>Contribution</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="all-features-body"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- === END: All Features (Tables) Card === -->

                            <!-- === NEW: All Detected Features (Charts) Card === -->
                            <div class="step-card animate-slide-up" id="all-features-charts-card"
                                style="animation-delay:.50s;">
                                <div class="step-header">
                                    <div class="step-header-left">
                                        <h2>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" />
                                            </svg>
                                           Selected Features (Charts)
                                        </h2>
                                    </div>
                                    <span class="tooltip-icon">i<span class="tooltip-content">All contributing features from your Python script's `all_detected_delta` output. Sorted from most Benign-leaning (positive) to most Malignant-leaning (negative).</span></span>
                                    <button type="button" class="maximize-card-btn" title="Maximize"><svg
                                            xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                            viewBox="0 0 16 16">
                                            <path
                                                d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" />
                                        </svg></button>
                                </div>
                                <div class="card-content">
                                    <div class="all-features-chart-wrapper">
                                        <canvas id="all-features-chart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <!-- === END: All Features (Charts) Card === -->

                        </div>
                        <!-- === END: Wrapper for side-by-side feature cards === -->


                    </div>
                </div>
            </div> <?php if ($result && !$isDebug): /* Keep this for raw JSON view if needed */ ?>
            <?php endif; ?>
        </div>
    </div>
    <footer>
        <p>WOA & EWOA Breast Cancer Detection Tool. For research purposes only. Not for clinical use.</p>
    </footer>

    <div id="image-modal-overlay"></div>
    <div id="card-modal-overlay">
        <div id="card-modal-content"> <button class="close-modal-btn">&times;</button>
            <h2 class="modal-title"></h2>
            <div class="modal-body"></div>
        </div>
    </div>

<script>
 document.addEventListener('DOMContentLoaded', () => {
  // === Safe DOM getters ===
  const $ = id => document.getElementById(id);
  const qs = sel => document.querySelector(sel);
  const qsa = sel => Array.from(document.querySelectorAll(sel));

  // === Element refs (guarded) ===
  const fileInput = $('file-input');
  const submitBtn = $('submit-btn');
  const clearBtn = $('clear-btn');
  const form = $('image-upload-form');
  const spinner = $('spinner');
  const btnText = $('btn-text');
  const skeletonLoader = $('skeleton-loader');
  const uploadArea = $('upload-area');
  const resultsContainer = $('results-container');
  const resultsGrid = $('results-grid');
  const imageModalOverlay = $('image-modal-overlay');
  const cardModalOverlay = $('card-modal-overlay');
  const cardModalContent = $('card-modal-content');
  const cardModalTitle = cardModalContent ? cardModalContent.querySelector('.modal-title') : null;
  const cardModalBody = cardModalContent ? cardModalContent.querySelector('.modal-body') : null;
  const closeCardModalBtn = cardModalContent ? cardModalContent.querySelector('.close-modal-btn') : null;
  const errorContainer = $('error-container');
  const previewWrapper = $('image-preview-wrapper');
  const resultsPlaceholder = $('results-placeholder');

  // History
  const historyCard = $('history-card');
  const historyList = $('history-list');
  const historyPlaceholder = $('history-placeholder');
  const clearHistoryBtn = $('clear-history-btn');

  // Sticky
  const toggleStickyBtn = $('toggle-sticky-btn');
  const leftColumn = document.querySelector('.left-column');
  const stickyBtnText = $('sticky-btn-text');

  // Other optional nodes (may be missing)
  const allFeaturesTableBodyId = 'all-features-body';
  const fsummaryMalBodyId = 'fsummary-malignant-body';
  const fsummaryBenBodyId = 'fsummary-benign-body';

  // === State ===
  let activeCharts = {};
  let currentMaximizedChartId = null;
  const PRETTY_NAMES = window.__PRETTY_NAMES__ || {};

  // === Local storage keys ===
  const STORAGE_KEY = 'woa_result_state_v3';
  const HISTORY_KEY = 'woa_history_v1';
  const STICKY_KEY = 'woa_sticky_pref';

  // === Helper utilities ===
  function safe(fn) {
    try { return fn(); } catch (e) { console.warn('safe helper caught', e); return null; }
  }

  function escapeHTML(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function showError(msg) {
    if (!errorContainer) return;
    errorContainer.innerHTML = `<div class="step-card error-card animate-slide-up"><strong>Error:</strong> ${msg}</div>`;
  }

  // LocalStorage helpers
  function loadState() {
    try {
      const r = localStorage.getItem(STORAGE_KEY);
      return r ? JSON.parse(r) : null;
    } catch (e) { return null; }
  }
  function saveState(p) {
    try {
      const pr = loadState() || {};
      let n = Object.assign({}, pr, p, { savedAt: Date.now() });
      let pl = JSON.stringify(n);
      // Avoid huge localStorage
      if (pl.length > 4500000) {
        delete n.previewDataUrl;
        pl = JSON.stringify(n);
      }
      localStorage.setItem(STORAGE_KEY, pl);
    } catch (e) {
      console.warn('State save failed:', e);
    }
  }
  function clearState() {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
  }

  // History storage
  function loadHistory() {
    try {
      const h = localStorage.getItem(HISTORY_KEY);
      return h ? JSON.parse(h) : [];
    } catch (e) { return []; }
  }
  function saveHistory(history) {
    try { localStorage.setItem(HISTORY_KEY, JSON.stringify(history)); } catch (e) { console.warn('History save failed:', e); }
  }
  function addResultToHistory(payload) {
    if (!payload || !payload.result) return;
    try {
      const history = loadHistory();
      const item = {
        id: new Date().toISOString() + '_' + Math.random().toString(36).slice(2, 9),
        savedAt: Date.now(),
        result: payload.result,
        imagePath: payload.preview || payload.image || null,
        filename: fileInput?.files?.[0]?.name || null
      };
      history.unshift(item);
      if (history.length > 20) history.pop();
      saveHistory(history);
      renderHistory();
    } catch (e) { console.error('addResultToHistory err', e); }
  }
  function renderHistory() {
    if (!historyList) return;
    const history = loadHistory();
    if (!history || history.length === 0) {
      historyList.innerHTML = '';
      if (historyPlaceholder) historyPlaceholder.style.display = 'block';
      if (clearHistoryBtn) clearHistoryBtn.style.display = 'none';
      return;
    }
    if (historyPlaceholder) historyPlaceholder.style.display = 'none';
    if (clearHistoryBtn) clearHistoryBtn.style.display = 'inline-flex';

    historyList.innerHTML = history.map(item => {
      const pred = item.result?.final_prediction || item.result?.prediction || 'N/A';
      const predClass = (String(pred || '').toLowerCase().startsWith('mal')) ? 'history-item-malignant' : 'history-item-benign';
      const date = new Date(item.savedAt).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
      return `<div class="history-item" data-history-id="${escapeHTML(item.id)}">
                <div class="history-item-left">
                  <span class="history-item-filename">${escapeHTML(item.filename || 'uploaded')}</span>
                  <span class="history-item-date">${escapeHTML(date)}</span>
                </div>
                <div class="history-item-right">
                  <span class="pill ${predClass}">${escapeHTML(String(pred))}</span>
                </div>
              </div>`;
    }).join('');
  }

  // === Canvas & preview utilities ===
  function displayCanvas(c, container) {
    if (!container) return;
    const existing = container.querySelector('canvas');
    if (existing) existing.remove();
    container.prepend(c);
    container.style.display = 'flex';
  }

  function scaleCanvasToFit(sC, mW, mH) {
    const w = sC.width, h = sC.height;
    const sc = Math.min(mW / w, mH / h, 1);
    const o = document.createElement('canvas');
    o.width = Math.round(w * sc);
    o.height = Math.round(h * sc);
    o.getContext('2d').drawImage(sC, 0, 0, o.width, o.height);
    return o;
  }

  // Render normal image file to canvas (not DICOM)
  function renderToCanvas(file) {
    return new Promise((res, rej) => {
      const isTiff = file.type === 'image/tiff' || file.name.toLowerCase().endsWith('.tif') || file.name.toLowerCase().endsWith('.tiff');
      const rdr = new FileReader();
      if (isTiff && window.Tiff) {
        rdr.onload = e => {
          try {
            Tiff.initialize({ TOTAL_MEMORY: 16777216 * 10 });
            const tiff = new Tiff({ buffer: e.target.result });
            res(tiff.toCanvas());
          } catch (err) { rej(err); }
        };
        rdr.onerror = rej;
        rdr.readAsArrayBuffer(file);
      } else {
        rdr.onload = e => {
          const img = new Image();
          img.onload = () => {
            const c = document.createElement('canvas');
            c.width = img.width; c.height = img.height;
            c.getContext('2d').drawImage(img, 0, 0);
            res(c);
          };
          img.onerror = rej;
          img.src = e.target.result;
        };
        rdr.onerror = rej;
        rdr.readAsDataURL(file);
      }
    });
  }

  // === Chart helpers (uses Chart.js if present) ===
  function destroyChartIfExists(id) {
    if (activeCharts[id]) {
      try { activeCharts[id].destroy(); } catch (e) {}
      delete activeCharts[id];
    }
  }

  function renderHorizontalBarChart(canvasId, featuresData, barColor, valueLabel = 'Value') {
    const cv = document.getElementById(canvasId);
    if (!cv || !window.Chart) return;
    destroyChartIfExists(canvasId);
    if (!featuresData || featuresData.length === 0) {
      const ctx = cv.getContext('2d'); if (!ctx) return;
      ctx.clearRect(0, 0, cv.width, cv.height);
      ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-dark') || '#333';
      ctx.textAlign = 'center';
      ctx.fillText('No features found.', cv.width / 2, cv.height / 2);
      cv.parentElement && (cv.parentElement.style.height = '100px');
      return;
    }
    const labels = featuresData.map(f => PRETTY_NAMES[f[0]] || f[0]);
    const data = featuresData.map(f => Number(f[1]));
    const bgColor = barColor || 'rgba(99, 179, 237, 0.7)';
    const borderColor = bgColor.replace(/0\.7|0\.8/, '1');
    const chartHeight = Math.max(150, featuresData.length * 20);
    cv.parentElement && (cv.parentElement.style.height = `${chartHeight}px`);

    activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
      type: 'bar',
      data: { labels, datasets: [{ label: valueLabel, data, backgroundColor: bgColor, borderColor, borderWidth: 1, borderRadius: 4 }] },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border-color') || '#eee' }, ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-dark') || '#333', callback: v => Number(v).toFixed(2) } },
          y: { grid: { display: false }, ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-dark') || '#333' } }
        },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => `${valueLabel}: ${Number(ctx.parsed.x).toFixed(6)}` } }
        }
      }
    });
  }

  function renderAllFeaturesChart(canvasId, zDataSorted) {
    const cv = document.getElementById(canvasId);
    if (!cv || !window.Chart) return;
    destroyChartIfExists(canvasId);
    if (!zDataSorted || zDataSorted.length === 0) {
      const ctx = cv.getContext('2d'); if (!ctx) return;
      ctx.clearRect(0, 0, cv.width, cv.height);
      ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-dark') || '#333';
      ctx.textAlign = 'center';
      ctx.fillText('No features found.', cv.width / 2, cv.height / 2);
      cv.parentElement && (cv.parentElement.style.height = '100px');
      return;
    }

    const labels = zDataSorted.map(d => d.label);
    const data = zDataSorted.map(d => Number(d.z));
    const colors = zDataSorted.map(d => Number(d.z) >= 0 ? (getComputedStyle(document.documentElement).getPropertyValue('--pastel-benign') || 'rgba(132,204,145,0.7)') : (getComputedStyle(document.documentElement).getPropertyValue('--pastel-malignant') || 'rgba(252,165,165,0.7)'));
    const chartHeight = Math.max(400, zDataSorted.length * 18);
    cv.parentElement && (cv.parentElement.style.height = `${chartHeight}px`);
    const tableScroll = $('all-features-table-scroll'); if (tableScroll) tableScroll.style.maxHeight = `${chartHeight}px`;

    activeCharts[canvasId] = new Chart(cv.getContext('2d'), {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Z-Score', data, backgroundColor: colors, borderWidth: 0, borderRadius: 4 }] },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: { x: { position: 'top', ticks: { callback: v => Number(v).toFixed(1) }, grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border-color') || '#eee' } }, y: { ticks: { font: { size: 9 } }, grid: { display: false } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `Contribution: ${Number(ctx.parsed.x).toFixed(3)}` } } }
      }
    });
  }

  // === Tables (color-coded) ===
  function renderColorCodedTable(tbodyId, featuresData, valueType = 'Value') {
    const tableBody = document.getElementById(tbodyId);
    // Silently return if table body is not present (avoid console warnings)
    if (!tableBody) return;
    if (!Array.isArray(featuresData) || featuresData.length === 0) {
      tableBody.innerHTML = '<tr><td colspan="2">No features found.</td></tr>';
      return;
    }
    const rows = featuresData.map(([name, value]) => {
      const prettyName = PRETTY_NAMES[name] || name;
      const val = Number(value);
      const colorClass = val < 0 ? 'row-malignant' : 'row-benign';
      const formattedValue = Number.isFinite(val) ? (valueType === 'Z-Score' ? val.toFixed(4) : val.toFixed(6)) : String(value);
      return `<tr class="${colorClass}"><td>${escapeHTML(prettyName)} <span class="subtle-name">(${escapeHTML(name)})</span></td><td class="mono"><strong>${escapeHTML(formattedValue)}</strong></td></tr>`;
    }).join('');
    tableBody.innerHTML = rows;
  }

  // === CSV helpers (keeps previous CSV behavior) ===
  function downloadCSV(rd) {
    let c = "Category,Parameter,Value\r\n";
    const e = s => { if (s == null) return ''; let r = String(s); if (r.includes(',') || r.includes('"') || r.includes('\n')) r = '"' + r.replace(/"/g, '""') + '"'; return r; };

    c += `Prediction,final_prediction,${e(rd.final_prediction ?? rd.prediction)}\r\n`;
    if (rd.probabilities) Object.entries(rd.probabilities).forEach(([k, v]) => c += `Probability,${e(k)},${e(v)}\r\n`);

    c += `Summary,lesion_subtype,${e(rd.lesion_subtype?.category ? `${rd.lesion_subtype.category} (${Object.values(rd.lesion_subtype.details || {}).join(', ')})` : rd.abnormality_type ?? '')}\r\n`;
    c += `Summary,total_features_detected,${e(rd["total number of features detected"])}\r\n`;
    c += `Summary,total_towards_malignant,${e(rd["total number of \"towards malignant\""])}\r\n`;
    c += `Summary,total_towards_benign,${e(rd["total number of \"towards benign\""])}\r\n`;
    c += `Summary,malignant_features,${e(rd["name of malignant features"])}\r\n`;
    c += `Summary,benign_features,${e(rd["name of benign features"])}\r\n`;
    c += `Summary,all_detected_features,${e(rd["name of all detected features"])}\r\n`;

    c += `Decision,distance_to_benign,${e(rd.distance_to_benign)}\r\n`;
    c += `Decision,distance_to_malignant,${e(rd.distance_to_malignant)}\r\n`;
    c += `Decision,tau,${e(rd.tau)}\r\n`;
    c += `Decision,ratio_decision_rule,${e(rd.ratio_decision)}\r\n`;
    c += `Decision,distance_ratio,${e(rd.distance_ratio ?? '')}\r\n`;
    if (rd.abnormality_scores) Object.entries(rd.abnormality_scores).forEach(([k, v]) => c += `Abnormality Score,${e(PRETTY_NAMES[k] || k)},${e(v)}\r\n`);
    if (rd.background_tissue) {
      c += `Background,code,${e(rd.background_tissue.code)}\r\n`;
      c += `Background,text,${e(rd.background_tissue.text)}\r\n`;
      c += `Background,explain,${e(rd.background_tissue.explain)}\r\n`;
    }
    if (rd.explanation?.class) c += `Explanation,class,${e(Array.isArray(rd.explanation.class) ? rd.explanation.class.join('; ') : rd.explanation.class)}\r\n`;
    if (rd.explanation?.abnormality_summary) c += `Explanation,abnormality_summary,${e(rd.explanation.abnormality_summary)}\r\n`;

    if (Array.isArray(rd.top_malignant_delta)) rd.top_malignant_delta.forEach(([n, v]) => c += `Top Malignant Delta,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`);
    if (Array.isArray(rd.top_benign_delta)) rd.top_benign_delta.forEach(([n, v]) => c += `Top Benign Delta,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`);
    if (Array.isArray(rd.all_detected_delta)) rd.all_detected_delta.forEach(([n, v]) => c += `All Detected Delta,${e(PRETTY_NAMES[n] || n)},${e(v)}\r\n`);

    const zscoresToExclude = ["crop_x1", "crop_y1", "crop_ok"];
    if (rd.zscores) Object.keys(rd.zscores || {}).sort().filter(k => !zscoresToExclude.includes(k)).forEach(k => c += `Z-Score,${e(PRETTY_NAMES[k] || k)},${e(rd.zscores[k])}\r\n`);

    const blob = new Blob([c], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    if (a.download !== undefined) {
      const url = URL.createObjectURL(blob);
      const t = new Date().toISOString().replace(/:/g, '-').slice(0, 19);
      a.setAttribute('href', url);
      a.setAttribute('download', `prediction_results_${t}.csv`);
      a.style.visibility = 'hidden';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    }
  }

  // Build CSV preview HTML (mini table)
  function buildCSVPreviewHTML(rd, l = 35) {
    const rs = [];
    rs.push(["Prediction", "final_prediction", String(rd.final_prediction ?? rd.prediction ?? "—")]);
    if (rd.probabilities) Object.entries(rd.probabilities).forEach(([k, v]) => rs.push(["Probability", k, String(v ?? "—")]));
    const lesionSubtype = rd.lesion_subtype;
    let lesionText = "N/A";
    if (lesionSubtype && lesionSubtype.category) {
      lesionText = lesionSubtype.category;
      const details = [];
      if (lesionSubtype.details?.shape) details.push(lesionSubtype.details.shape);
      if (lesionSubtype.details?.margin) details.push(lesionSubtype.details.margin);
      if (lesionSubtype.details?.type) details.push(lesionSubtype.details.type);
      if (lesionSubtype.details?.distribution) details.push(lesionSubtype.details.distribution);
      if (details.length) lesionText += ` (${details.join(', ')})`;
    } else if (rd.abnormality_type) lesionText = rd.abnormality_type;
    rs.push(["Summary", "lesion_subtype", lesionText]);
    rs.push(["Summary", "total_features_detected", String(rd["total number of features detected"] ?? "—")]);
    rs.push(["Summary", "total_towards_malignant", String(rd["total number of \"towards malignant\""] ?? "—")]);
    rs.push(["Summary", "total_towards_benign", String(rd["total number of \"towards benign\""] ?? "—")]);
    rs.push(["Summary", "malignant_features", String(rd["name of malignant features"] ?? "—")]);
    rs.push(["Summary", "benign_features", String(rd["name of benign features"] ?? "—")]);
    rs.push(["Summary", "all_detected_features", String(rd["name of all detected features"] ?? "—")]);

    rs.push(["Decision", "distance_to_benign", String(rd.distance_to_benign ?? "—")]);
    rs.push(["Decision", "distance_to_malignant", String(rd.distance_to_malignant ?? "—")]);
    rs.push(["Decision", "tau", String(rd.tau ?? "—")]);
    rs.push(["Decision", "ratio_decision_rule", String(rd.ratio_decision ?? "—")]);
    rs.push(["Decision", "distance_ratio", String(rd.distance_ratio ?? "—")]);

    if (rd.abnormality_scores) Object.entries(rd.abnormality_scores).forEach(([k, v]) => rs.push(["Abnormality Score", PRETTY_NAMES[k] || k, String(v ?? "—")]));
    if (rd.background_tissue) { rs.push(["Background", "code", String(rd.background_tissue.code ?? "—")]); rs.push(["Background", "text", String(rd.background_tissue.text ?? "—")]); rs.push(["Background", "explain", String(rd.background_tissue.explain ?? "—")]); }
    if (rd.explanation?.class) rs.push(["Explanation", "class", String(Array.isArray(rd.explanation.class) ? rd.explanation.class.join("; ") : rd.explanation.class)]);
    if (rd.explanation?.abnormality_summary) rs.push(["Explanation", "abnormality_summary", String(rd.explanation.abnormality_summary)]);

    if (Array.isArray(rd.top_malignant_delta)) rd.top_malignant_delta.forEach(([n, v]) => rs.push(["Top Malignant Delta", PRETTY_NAMES[n] || n, String(v ?? "—")]));
    if (Array.isArray(rd.top_benign_delta)) rd.top_benign_delta.forEach(([n, v]) => rs.push(["Top Benign Delta", PRETTY_NAMES[n] || n, String(v ?? "—")]));
    if (Array.isArray(rd.all_detected_delta)) rd.all_detected_delta.forEach(([n, v]) => rs.push(["All Detected Delta", PRETTY_NAMES[n] || n, String(v ?? "—")]));

    const zscoresToExclude = ["crop_x1", "crop_y1", "crop_ok"];
    if (rd.zscores) Object.keys(rd.zscores || {}).sort().filter(k => !zscoresToExclude.includes(k)).forEach(k => rs.push(["Z-Score", PRETTY_NAMES[k] || k, String(rd.zscores[k] ?? "—")]));

    const lim = rs.slice(0, l);
    let t = '<div class="table-wrapper-scroll"><table class="data-table"><thead><tr><th>Category</th><th>Parameter</th><th>Value</th></tr></thead><tbody>';
    lim.forEach(r => t += `<tr><td>${escapeHTML(r[0])}</td><td>${escapeHTML(r[1])}</td><td>${escapeHTML(r[2])}</td></tr>`);
    t += '</tbody></table></div>';
    t += `<div class="modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;"><button type="button" class="btn" id="csv-download-confirm">Download All ${rs.length} Rows</button><button type="button" class="btn btn-secondary" id="csv-preview-close">Close</button></div><p class="file-meta" style="margin-top:.5rem; text-align:right;">Showing first ${lim.length} of ${rs.length} rows.</p>`;
    return t;
  }

  function openCSVPreview(rd) {
    const html = buildCSVPreviewHTML(rd);
    showContentInModal('CSV Preview', html);
    const dl = document.getElementById('csv-download-confirm');
    const cl = document.getElementById('csv-preview-close');
    if (dl) dl.addEventListener('click', () => { downloadCSV(rd); closeCardModal(); });
    if (cl) cl.addEventListener('click', closeCardModal);
  }

  // === Modal & UI helpers ===
  function closeCardModal() {
    if (cardModalOverlay) cardModalOverlay.classList.remove('visible');
    document.body.style.overflow = '';
    if (cardModalBody) cardModalBody.innerHTML = '';
    // Destroy modal charts
    Object.keys(activeCharts).forEach(k => { if (k.startsWith('modal_')) { try { activeCharts[k].destroy(); } catch (e) {} delete activeCharts[k]; }});
  }

  function showContentInModal(title, contentHtml, cardId = null) {
    if (!cardModalOverlay || !cardModalBody || !cardModalTitle) {
      // fallback: simple alert
      const w = window.open('', '_blank', 'noopener');
      w.document.write(`<h3>${escapeHTML(title)}</h3>${contentHtml}`);
      return;
    }
    cardModalTitle.textContent = title;
    cardModalBody.innerHTML = contentHtml;
    cardModalOverlay.classList.add('visible');
    document.body.style.overflow = 'hidden';

    // small delay to ensure DOM nodes are present
    requestAnimationFrame(() => {
      // If cardId requests chart rendering, clone main chart configs where possible
      if (!window.__PREDICT__?.result) return;
      const ids = [];
      if (cardId === 'all-features-charts-card') ids.push('all-features-chart');
      if (cardId === 'feature-summary-card-content') ids.push('fsummary-malignant-chart', 'fsummary-benign-chart');
      if (cardId === 'explanation-card-content') ids.push('abnormality-chart');
      ids.forEach(id => {
        const canvas = cardModalBody.querySelector(`#${id}`);
        if (!canvas) return;
        // Attempt to clone from existing activeCharts
        const original = activeCharts[id];
        if (original && canvas.getContext) {
          try {
            // Chart.js accepts config clone
            const ctx = canvas.getContext('2d');
            const cfg = JSON.parse(JSON.stringify(original.config || original._config || {}));
            activeCharts['modal_' + id] = new Chart(ctx, cfg);
          } catch (e) {
            console.warn('Could not clone chart to modal for', id, e);
          }
        }
      });
    });
  }

  // Image modal
  function showImageInModal(dataUrl) {
    if (!imageModalOverlay) return;
    imageModalOverlay.innerHTML = '';
    const i = new Image();
    i.src = dataUrl;
    i.style.maxWidth = '90vw';
    i.style.maxHeight = '90vh';
    i.style.borderRadius = '12px';
    imageModalOverlay.appendChild(i);
    imageModalOverlay.classList.add('visible');
  }

  // === Core: displayResults ===
  function displayResults(resultData, payload) {
    if (!resultsContainer) return;
    resultsContainer.style.display = 'block';
    if (resultsPlaceholder) resultsPlaceholder.style.display = 'none';

    // Prediction label
    const predEl = qs('#prediction-card-content [data-field="final_prediction"]');
    const indEl = qs('#prediction-card-content .prediction-indicator');
    const pred = resultData.final_prediction || resultData.prediction || '—';
    const predClass = String(pred || '').toLowerCase();
    const accentSuccess = getComputedStyle(document.documentElement).getPropertyValue('--accent-success') || 'rgba(46,204,113,0.7)';
    const accentWarning = getComputedStyle(document.documentElement).getPropertyValue('--accent-warning') || 'rgba(231,76,60,0.7)';
    const predColor = pred === 'Malignant' || predClass.includes('malign') ? accentWarning : accentSuccess;

    if (predEl) { predEl.textContent = pred; predEl.style.color = predColor; const pC = predEl.closest('.prediction-card'); if (pC) pC.className = `step-card prediction-card animate-slide-up prediction-${predClass.replace(/\s+/g,'-')}`; }
    if (indEl) {
      const bSVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${accentSuccess}"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`;
      const mSVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="${accentWarning}"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>`;
      indEl.innerHTML = (String(pred || '').toLowerCase().startsWith('mal') ? mSVG : bSVG);
    }
// ------------------------------------------------------------
// PATCH: Convert top_feature_contributions → old arrays
// ------------------------------------------------------------

// Extract unified list from backend
const TFC = Array.isArray(resultData.top_feature_contributions)
  ? resultData.top_feature_contributions
  : [];

// Convert to legacy [name, value] pairs
const derived_all_detected_delta = TFC.map(row => {
  const name = row.feature || row[0];
  const val  = Number(row.contribution ?? row[1] ?? 0);
  return [name, val];
});

// Build malignant feature list
const derived_top_malignant_delta = TFC
  .filter(r => {
    const t = String(r.towards || '').toLowerCase();
    if (t.includes('mal')) return true;
    return Number(r.contribution) < 0;
  })
  .map(r => [ r.feature, Number(r.contribution) ])
  .sort((a,b) => a[1] - b[1])   // most negative first
  .slice(0, 30);

// Build benign feature list
const derived_top_benign_delta = TFC
  .filter(r => {
    const t = String(r.towards || '').toLowerCase();
    if (t.includes('ben')) return true;
    return Number(r.contribution) >= 0;
  })
  .map(r => [ r.feature, Number(r.contribution) ])
  .sort((a,b) => b[1] - a[1])   // largest positive first
  .slice(0, 30);

// Prefer backend keys if present, otherwise use derived ones
const allDetectedData = Array.isArray(resultData.all_detected_delta)
  ? resultData.all_detected_delta
  : derived_all_detected_delta;

const topMalData = Array.isArray(resultData.top_malignant_delta)
  ? resultData.top_malignant_delta
  : derived_top_malignant_delta;

const topBenData = Array.isArray(resultData.top_benign_delta)
  ? resultData.top_benign_delta
  : derived_top_benign_delta;


// ------------------------------------------------------------
// END PATCH
// ------------------------------------------------------------


    // Probabilities & distances
    const probs = resultData.probabilities || {};
    const benProb = Number(probs['Benign'] ?? 0);
    const malProb = Number(probs['Malignant'] ?? 0);
    const confVal = Math.max(benProb, malProb);

    const elDB = qs('[data-field="distance_to_benign"]');
    const elDM = qs('[data-field="distance_to_malignant"]');
    const dB = Number(resultData.distance_to_benign);
    const dM = Number(resultData.distance_to_malignant);
    if (elDB) elDB.textContent = Number.isFinite(dB) ? dB.toFixed(4) : 'N/A';
    if (elDM) elDM.textContent = Number.isFinite(dM) ? dM.toFixed(4) : 'N/A';

    // Background tissue
    const bg = resultData.background_tissue || {};
    const code = (bg.code || '').toString().toUpperCase() || '—';
    const badgeEl = qs('#background-card-content [data-field="background_tissue_code_badge"]');
    const codeEl = qs('#background-card-content [data-field="background_tissue_code"]');
    const textEl = qs('#background-card-content [data-field="background_tissue_text"]');
    const explainEl = qs('#background-card-content [data-field="background_tissue_explain"]');
    if (badgeEl) {
      badgeEl.textContent = code.slice(0, 1) || '?';
      badgeEl.className = 'birads-badge';
      if (code.startsWith('A') || code.startsWith('T1')) badgeEl.classList.add('birads-t1');
      else if (code.startsWith('B') || code.startsWith('T2')) badgeEl.classList.add('birads-t2');
      else if (code.startsWith('C') || code.startsWith('T3')) badgeEl.classList.add('birads-t3');
      else if (code.startsWith('D') || code.startsWith('T4')) badgeEl.classList.add('birads-t4');
    }
    if (codeEl) codeEl.textContent = code;
    if (textEl) textEl.textContent = bg.text ?? '—';
    if (explainEl) explainEl.textContent = bg.explain ?? '—';

    // Explanations block (safe)
    (function renderExplanations() {
      const root = $('explain-root');
      if (!root) return;
      const classExplanations = (Array.isArray(resultData.explanation?.class) ? resultData.explanation.class : []).filter(e => !(e || '').includes("Mahalanobis ratio:")).map(e => escapeHTML(e));
      const cExp = classExplanations.length ? classExplanations.join('<br>') : '—';
      const aSumm = resultData.explanation?.abnormality_summary || '—';
      function decorateMath(s) { if (!s) return ''; return `<span class="math-inline">${s.replace(/<=/g, '≤').replace(/->/g, '→').replace(/\*/g, '·')}</span>`; }
      function metricsToChips(summary) {
        if (!summary) return '';
        const c = [];
        const re = /([A-Za-z][A-Za-z_ ]+)\s*=\s*([-+]?\d*\.?\d+(?:e[-+]?\d+)?)/gi;
        let m;
        while ((m = re.exec(summary)) !== null) c.push(`<span class="metric-chip"><span class="k">${m[1].trim()}</span><span class="v">${Number(m[2]).toFixed(2)}</span></span>`);
        return c.join('');
      }
      const classHTML = (cExp && cExp !== '—') ? `<div class="explain-section"><div class="explain-body">${decorateMath(cExp || '')}</div></div>` : '';
      const metricsHTML = metricsToChips(aSumm || '');
      const badgesHTML = `<div class="badge-row"></div>`;
      const summaryHTML = `<div class="explain-section"><div class="explain-title"><span class="dot"></span>Abnormality Summary</div><div class="explain-body">${metricsHTML || ''}${badgesHTML}</div></div>`;
      root.innerHTML = classHTML + summaryHTML;
    })();

    // Abnormality scores chart
    const abnScores = resultData.abnormality_scores || {};
    const abnCanvas = $('abnormality-chart');
    if (abnCanvas && window.Chart) {
      destroyChartIfExists('abnormality-chart');
      const abnVals = Object.values(abnScores).map(v => Number(v));
      const abnLabels = Object.keys(abnScores).map(k => PRETTY_NAMES[k] || k);
      activeCharts['abnormality-chart'] = new Chart(abnCanvas.getContext('2d'), {
        type: 'bar',
        data: { labels: abnLabels, datasets: [{ label: 'Score', data: abnVals, backgroundColor: abnVals.map((_, i) => ['rgba(99,179,237,0.7)','rgba(132,204,145,0.7)','rgba(250,202,154,0.7)'][i % 3]), borderWidth: 0, borderRadius: 4 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--border-color') || '#eee' } }, y: { grid: { display: false } } }, plugins: { legend: { display: false } } }
      });
    }

    // Lesion summary fields
    const lesionSubtype = resultData.lesion_subtype;
    let lesionText = "N/A";
    if (lesionSubtype && lesionSubtype.category) {
      lesionText = lesionSubtype.category;
      const details = [];
      if (lesionSubtype.details?.shape) details.push(lesionSubtype.details.shape);
      if (lesionSubtype.details?.margin) details.push(lesionSubtype.details.margin);
      if (lesionSubtype.details?.type) details.push(lesionSubtype.details.type);
      if (lesionSubtype.details?.distribution) details.push(lesionSubtype.details.distribution);
      if (details.length) lesionText += ` (${details.join(', ')})`;
    } else if (resultData.abnormality_type) lesionText = resultData.abnormality_type;
    const elLesion = qs('[data-field="lesion_subtype"]');
    if (elLesion) elLesion.textContent = lesionText;
    // --- Render lesion_metadata and lesion_narrative into the left UI card ---
(function renderLesionDescription() {
    const descRoot = document.getElementById('lesion-description-block');
    const descContent = document.getElementById('lesion-description-content');
    if (!descContent || !descRoot) return;

    const meta = resultData.lesion_metadata || {};
    const narrative = resultData.lesion_narrative || resultData.explanation?.abnormality_summary || '';
    const birads = resultData.lesion_birads || (meta.assessment ?? meta.assessment_value ?? '');

    let html = '';
    // Narrative top-line (human readable)
    if (narrative) {
        html += `<p style="font-weight:600; margin-bottom:.5rem;">${escapeHTML(narrative)}</p>`;
    }

    // BI-RADS badge (if available)
    if (birads) {
        html += `<p style="margin-top:0.25rem;"><strong>Suggested BI-RADS:</strong> ${escapeHTML(birads)}</p>`;
    }

    // Raw metadata table
    if (meta && Object.keys(meta).length) {
        html += `<div class="table-wrapper-scroll"><table class="data-table"><tbody>`;
        Object.entries(meta).forEach(([k,v]) => {
            if (v === null || v === undefined || String(v).trim() === '') return;
            html += `<tr><td style="width:40%;"><strong>${escapeHTML(k.replace(/_/g,' '))}</strong></td><td class="mono">${escapeHTML(String(v))}</td></tr>`;
        });
        html += `</tbody></table></div>`;
    } else {
        if (!narrative) html = '<p class="file-meta">No lesion metadata available.</p>';
    }

    descContent.innerHTML = html;
    descRoot.style.display = 'block';
})();

    const elTotalFeat = qs('[data-field="total_features"]');
    if (elTotalFeat) elTotalFeat.textContent = resultData["total number of features detected"] ?? 'N/A';
    const elTotalMal = qs('[data-field="total_malignant"]');
    if (elTotalMal) elTotalMal.textContent = resultData["total number of \"towards malignant\""] ?? 'N/A';
    const elTotalBen = qs('[data-field="total_benign"]');
    if (elTotalBen) elTotalBen.textContent = resultData["total number of \"towards benign\""] ?? 'N/A';

    // ---- Feature Summary Cards (malignant / benign) ----
    // Guarded: only render if both DOM and array data exist
    if (document.getElementById(fsummaryMalBodyId)) {
    renderColorCodedTable(fsummaryMalBodyId, topMalData, 'Contribution');

      // render chart if canvas exists
      if (document.getElementById('fsummary-malignant-chart')) {
        renderHorizontalBarChart(
          'fsummary-malignant-chart',
          resultData.top_malignant_delta.map(f => [f[0], f[1]]).reverse(),
          'rgba(252,165,165,0.7)',
          'Contribution'
        );
      }
    } else {
      // If table exists but no data, ensure friendly fallback
      if (document.getElementById(fsummaryMalBodyId)) document.getElementById(fsummaryMalBodyId).innerHTML = '<tr><td colspan="2">No features found.</td></tr>';
      const cv = document.getElementById('fsummary-malignant-chart');
      if (cv && cv.getContext) {
        const ctx = cv.getContext('2d');
        ctx.clearRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#999';
        ctx.textAlign = 'center';
        ctx.fillText('No features found.', cv.width / 2, cv.height / 2);
      }
    }

    if (document.getElementById(fsummaryBenBodyId)) {
    renderColorCodedTable(fsummaryBenBodyId, topBenData, 'Contribution');
      if (document.getElementById('fsummary-benign-chart')) {
        renderHorizontalBarChart(
          'fsummary-benign-chart',
          resultData.top_benign_delta.map(f => [f[0], f[1]]).reverse(),
          'rgba(132,204,145,0.7)',
          'Contribution'
        );
      }
    } else {
      if (document.getElementById(fsummaryBenBodyId)) document.getElementById(fsummaryBenBodyId).innerHTML = '<tr><td colspan="2">No features found.</td></tr>';
      const cv2 = document.getElementById('fsummary-benign-chart');
      if (cv2 && cv2.getContext) {
        const ctx = cv2.getContext('2d');
        ctx.clearRect(0, 0, cv2.width, cv2.height);
        ctx.fillStyle = '#999';
        ctx.textAlign = 'center';
        ctx.fillText('No features found.', cv2.width / 2, cv2.height / 2);
      }
    }

    if (document.getElementById(allFeaturesTableBodyId) && allDetectedData.length > 0) {
      const allDataSorted = [...allDetectedData].sort((a,b) => b[1] - a[1]);
      renderColorCodedTable(allFeaturesTableBodyId, allDataSorted, 'Contribution');

      const allDataForChart = allDataSorted.map(([k,v]) => ({ key: k, label: PRETTY_NAMES[k] || k, z: v }));
      if (document.getElementById('all-features-chart')) renderAllFeaturesChart('all-features-chart', allDataForChart);
    } else {
      // Table fallback
      if (document.getElementById(allFeaturesTableBodyId)) {
        document.getElementById(allFeaturesTableBodyId).innerHTML = '<tr><td colspan="2">No features found.</td></tr>';
      }
      // Chart fallback
      const cv = document.getElementById('all-features-chart');
      if (cv && cv.getContext) {
        const ctx = cv.getContext('2d');
        ctx.clearRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#999';
        ctx.textAlign = 'center';
        ctx.fillText('No features found.', cv.width / 2, cv.height / 2);
        cv.parentElement && (cv.parentElement.style.height = '100px');
      }
    }

    // --- Top feature contributions table (if exists) ---
    const topContribs = Array.isArray(resultData.top_feature_contributions) ? resultData.top_feature_contributions : [];
    const tfcBody = $('top-feature-contrib-body');
    if (tfcBody) {
      tfcBody.innerHTML = topContribs.length ? topContribs.map(row => {
        const name = row.feature || row[0];
        const contrib = row.contribution ?? row[1];
        const towards = row.towards || '';
        return `<tr><td>${escapeHTML(PRETTY_NAMES[name] || name)}</td><td class="mono">${escapeHTML(Number(contrib).toFixed(6))}</td><td>${escapeHTML(towards)}</td></tr>`;
      }).join('') : `<tr><td colspan="3">No features found.</td></tr>`;
    }

    // --- CSV & print buttons ---
    const printBtn = $('print-btn');
    if (printBtn) {
      const nb = printBtn.cloneNode(true);
      printBtn.parentNode.replaceChild(nb, printBtn);
      nb.addEventListener('click', () => window.print());
    }
    const csvBtn = $('csv-btn');
    if (csvBtn) {
      const nb2 = csvBtn.cloneNode(true);
      csvBtn.parentNode.replaceChild(nb2, csvBtn);
      nb2.addEventListener('click', () => openCSVPreview(resultData));
    }

    // Rewire maximize buttons
    if (resultsGrid) {
      qsa('.maximize-card-btn').forEach(b => {
        const nb = b.cloneNode(true);
        b.parentNode.replaceChild(nb, b);
        nb.addEventListener('click', e => {
          const c = e.target.closest('.step-card[id]');
          if (c?.id) {
            const t = c.querySelector('h2')?.textContent.trim() || 'Details';
            const ce = c.querySelector('.card-content');
            if (ce) showContentInModal(t, ce.cloneNode(true).innerHTML, c.id);
          }
        });
      });
    }

    // Save state & history
    saveState({ result: resultData, imagePath: payload?.image || payload?.preview || null, filename: fileInput?.files?.[0]?.name || null });
    addResultToHistory(payload);

     // If preview image is available from server payload, show it
    if (payload?.preview) {
      // display preview image in previewWrapper
      if (previewWrapper) {
        previewWrapper.innerHTML = '';
        const img = new Image();
        img.onload = () => {
          try {
            const rC = document.createElement('canvas');
            rC.width = img.width; rC.height = img.height;
            rC.getContext('2d').drawImage(img, 0, 0);
            previewWrapper.dataset.fullImage = rC.toDataURL();
            const mW = previewWrapper.clientWidth || 900;
            const mH = 400;
            const sc = scaleCanvasToFit(rC, mW, mH);
            displayCanvas(sc, previewWrapper);
            const nE = $('image-filename');
            if (nE) { nE.textContent = (fileInput?.files?.[0]?.name || (payload.image || '').split('/').pop() || 'image'); nE.style.display = 'block'; }
            submitBtn && (submitBtn.disabled = false);
            uploadArea && (uploadArea.style.display = 'none');
            clearBtn && (clearBtn.style.display = 'inline-flex');
          } catch (e) {
            console.warn('preview show failed', e);
          }
        };
        img.onerror = () => { console.warn('Could not load preview image:', payload.preview); };
        let src = payload.preview;
        if (src && !src.startsWith('http') && window.location) src = (window.location.origin || '') + '/' + src.replace(/^\/+/, '');
        img.src = src;
      }
    } else {
      if (previewWrapper) previewWrapper.style.display = 'flex';
    }
    // === NEW — Display overlay PNG if server returned it ===
    if (payload?.overlay) {
        const overlayWrapper = document.getElementById("overlay-preview-wrapper");
        const overlayImg = document.getElementById("overlay-preview-img");

        if (overlayWrapper && overlayImg) {
            let osrc = payload.overlay;

            // Same origin fix for relative paths
            if (osrc && !osrc.startsWith('http') && window.location)
                osrc = (window.location.origin || '') + '/' + osrc.replace(/^\/+/, '');

            // Cache-buster to force browser to reload new overlay each time
            overlayImg.src = osrc + "?v=" + Date.now();

            overlayWrapper.style.display = "block";
        }
    } else {
        // Hide overlay if none was generated
        const overlayWrapper = document.getElementById("overlay-preview-wrapper");
        if (overlayWrapper) overlayWrapper.style.display = "none";
    }
}

  // === File selection handler (DICOM safe) ===
  function handleFileSelect(file) {
    if (!file) {
      if (fileInput && fileInput.files && fileInput.files.length) file = fileInput.files[0]; else return;
    }
    const ext = (file.name || '').toLowerCase().split('.').pop();
    if (ext === 'dcm') {
      // Don't try to render DICOM client-side
      if (previewWrapper) {
        previewWrapper.innerHTML = '<div class="loading-preview">DICOM detected — preview will load after server processing...</div>';
        previewWrapper.style.display = 'flex';
      }
      submitBtn && (submitBtn.disabled = false);
      clearBtn && (clearBtn.style.display = 'inline-flex');
      uploadArea && (uploadArea.style.display = 'none');
      return;
    }

    // Normal image file
    renderToCanvas(file).then(rC => {
      const mW = previewWrapper?.clientWidth || 900;
      const mH = 400;
      const sc = scaleCanvasToFit(rC, mW, mH);
      previewWrapper && (previewWrapper.dataset.fullImage = rC.toDataURL());
      previewWrapper && displayCanvas(sc, previewWrapper);
      const nE = $('image-filename'); if (nE) { nE.textContent = file.name; nE.style.display = 'block'; }
      submitBtn && (submitBtn.disabled = false);
      clearBtn && (clearBtn.style.display = 'inline-flex');
      uploadArea && (uploadArea.style.display = 'none');
    }).catch(err => {
      console.error(err);
      showError('Could not read or render image.');
    });
  }

  // === Form submit (AJAX) ===
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (submitBtn) submitBtn.disabled = true;
      if (spinner) spinner.style.display = 'block';
      if (btnText) btnText.textContent = 'Analyzing...';
      if (skeletonLoader) skeletonLoader.style.display = 'block';
      if (resultsContainer) resultsContainer.style.display = 'none';
      if (resultsPlaceholder) resultsPlaceholder.style.display = 'none';
      if (errorContainer) errorContainer.innerHTML = '';

      // clear previous charts
      Object.keys(activeCharts).forEach(k => { try { activeCharts[k].destroy(); } catch (e) {} });
      activeCharts = {};

      try {
        const formData = new FormData(form);
        formData.set('ajax', '1');
        formData.delete('mock');

        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const ct = resp.headers.get('content-type') || '';
        if (!resp.ok) {
          const text = await resp.text();
          throw new Error(`HTTP ${resp.status}\n\n${text.slice(0, 2000)}`);
        }
        if (!ct.includes('application/json')) {
          const text = await resp.text();
          if (text.includes('POST Content-Length')) throw new Error('File too large.');
          throw new Error(`Expected JSON, got HTML/text:\n\n${text.slice(0, 500)}...`);
        }
        const payload = await resp.json();
        console.log('AJAX payload:', payload);
        if (payload.ok && payload.result) {
          window.__PREDICT__ = payload;
          displayResults(payload.result, payload);
        } else {
          throw new Error(payload.error || payload.noise || 'Backend error.');
        }
      } catch (err) {
        console.error('Fetch Error:', err);
        showError(err?.message?.replace(/\n/g,'<br>') || 'Analysis error.');
      } finally {
        if (skeletonLoader) skeletonLoader.style.display = 'none';
        if (spinner) spinner.style.display = 'none';
        if (btnText) btnText.textContent = 'Run Prediction';
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // === Clear button ===
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      clearState();
      if (fileInput) fileInput.value = '';
      if (previewWrapper) { previewWrapper.style.display = 'none'; previewWrapper.removeAttribute('data-full-image'); previewWrapper.innerHTML = ''; }
      const eC = previewWrapper?.querySelector('canvas'); if (eC) eC.remove();
      const nE = $('image-filename'); if (nE) nE.style.display = 'none';
      if (resultsContainer) resultsContainer.style.display = 'none';
      if (errorContainer) errorContainer.innerHTML = '';
      if (skeletonLoader) skeletonLoader.style.display = 'none';
      if (resultsPlaceholder) resultsPlaceholder.style.display = 'block';
      if (btnText) btnText.textContent = 'Run Prediction';
      if (submitBtn) submitBtn.disabled = true;
      if (clearBtn) clearBtn.style.display = 'none';
      if (uploadArea) uploadArea.style.display = 'block';
      Object.keys(activeCharts).forEach(k => { try { activeCharts[k].destroy(); } catch (e) {} });
      activeCharts = {};
      window.__PREDICT__ = null;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // === Upload area / file input handlers ===
  if (uploadArea) uploadArea.addEventListener('click', () => fileInput && fileInput.click());
  if (fileInput) {
    fileInput.addEventListener('change', () => handleFileSelect());
  }
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
    uploadArea && uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); }, false);
  });
  ['dragenter', 'dragover'].forEach(ev => uploadArea && uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover'), false));
  ['dragleave', 'drop'].forEach(ev => uploadArea && uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover'), false));
  uploadArea && uploadArea.addEventListener('drop', e => { if (fileInput) fileInput.files = e.dataTransfer.files; handleFileSelect(); });

  // Image modal click
  document.body.addEventListener('click', e => {
    if (e.target.closest('.maximize-btn')) {
      const dU = $('image-preview-wrapper')?.dataset?.fullImage;
      if (dU) showImageInModal(dU);
    }
  });
  imageModalOverlay && imageModalOverlay.addEventListener('click', e => { if (e.target === imageModalOverlay) imageModalOverlay.classList.remove('visible'); });

  // Card modal close
  closeCardModalBtn && closeCardModalBtn.addEventListener('click', closeCardModal);
  cardModalOverlay && cardModalOverlay.addEventListener('click', e => { if (e.target === cardModalOverlay) closeCardModal(); });

  // History listeners
  clearHistoryBtn && clearHistoryBtn.addEventListener('click', () => { saveHistory([]); renderHistory(); });
  historyList && historyList.addEventListener('click', (e) => {
    const itemEl = e.target.closest('.history-item[data-history-id]');
    if (!itemEl) return;
    const id = itemEl.dataset.historyId;
    const history = loadHistory();
    const item = history.find(h => h.id === id);
    if (!item) return;
    window.__PREDICT__ = { ok: true, result: item.result, image: item.imagePath };
    displayResults(item.result, { preview: item.imagePath });
    if (item.imagePath && previewWrapper) {
      const img = new Image();
      img.onload = () => {
        const rC = document.createElement('canvas'); rC.width = img.width; rC.height = img.height; rC.getContext('2d').drawImage(img, 0, 0);
        const dU = rC.toDataURL(); previewWrapper.dataset.fullImage = dU;
        const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH);
        displayCanvas(sc, previewWrapper);
        const nE = $('image-filename'); if (nE) { nE.textContent = item.filename || 'image'; nE.style.display = 'block'; }
      };
      img.onerror = () => console.warn('Could not load history image:', item.imagePath);
      img.src = item.imagePath;
    }
    resultsContainer && resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // Sticky panel toggle
  function setStickyState(isSticky) {
    if (!leftColumn || !toggleStickyBtn || !stickyBtnText) return;
    if (isSticky) {
      leftColumn.classList.add('is-sticky');
      stickyBtnText.textContent = 'Unpin';
      toggleStickyBtn.setAttribute('aria-pressed', 'true');
    } else {
      leftColumn.classList.remove('is-sticky');
      stickyBtnText.textContent = 'Pin Panel';
      toggleStickyBtn.setAttribute('aria-pressed', 'false');
    }
  }
  if (toggleStickyBtn) {
    toggleStickyBtn.addEventListener('click', () => {
      const wants = !leftColumn.classList.contains('is-sticky');
      setStickyState(wants);
      try { localStorage.setItem(STICKY_KEY, wants); } catch (e) { console.warn('Could not save sticky pref'); }
    });
  }
  try {
    const storedSticky = localStorage.getItem(STICKY_KEY);
    if (storedSticky === 'true') setStickyState(true);
  } catch (e) {}

  // Init: render history, and load initial state if available
  renderHistory();
  const initialPredictData = window.__PREDICT__;
  const stored = loadState();
  let initialImageSrc = null;
  if (initialPredictData?.result) {
    resultsPlaceholder && (resultsPlaceholder.style.display = 'none');
    displayResults(initialPredictData.result, initialPredictData);
    clearBtn && (clearBtn.style.display = 'inline-flex');
    initialImageSrc = window.__UPLOADED_IMAGE__;
  } else if (stored?.result) {
    resultsPlaceholder && (resultsPlaceholder.style.display = 'none');
    displayResults(stored.result, { preview: stored.imagePath });
    clearBtn && (clearBtn.style.display = 'inline-flex');
    initialImageSrc = stored.imagePath || stored.previewDataUrl;
    window.__PREDICT__ = { ok: true, result: stored.result, image: stored.imagePath };
  } else {
    resultsPlaceholder && (resultsPlaceholder.style.display = 'block');
    resultsContainer && (resultsContainer.style.display = 'none');
    skeletonLoader && (skeletonLoader.style.display = 'none');
  }

  if (initialImageSrc && previewWrapper) {
    const img = new Image();
    img.onload = () => {
      const rC = document.createElement('canvas'); rC.width = img.width; rC.height = img.height; rC.getContext('2d').drawImage(img, 0, 0);
      const dU = rC.toDataURL(); previewWrapper.dataset.fullImage = dU;
      const mW = previewWrapper.clientWidth || 900; const mH = 400; const sc = scaleCanvasToFit(rC, mW, mH);
      displayCanvas(sc, previewWrapper);
      const nE = $('image-filename'); if (nE) { nE.textContent = (initialPredictData ? (window.__UPLOADED_IMAGE__?.split('/').pop()) : stored?.filename) || 'image'; nE.style.display = 'block'; }
      submitBtn && (submitBtn.disabled = false);
      uploadArea && (uploadArea.style.display = 'none');
    };
    img.onerror = () => { console.warn('Could not load initial image:', initialImageSrc); clearState(); };
    img.src = initialImageSrc;
  }

}); // DOMContentLoaded

</script>


</body>

</html>

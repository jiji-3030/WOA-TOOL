<?php
$python = "/opt/miniconda3/bin/python3";  // or your venv
$workdir = "/Volumes/JANICE/WOA-TOOL";    // or correct path

function build_predict_cmd($image) {
    global $python, $workdir;
    // Build the command to run predict
    return "PYTHONPATH=$workdir $python -m woa_tool.cli predict --model $workdir/models/model.json --image $image";
}

// Default parameters (you can expand later)
$defaults = [
    "runs" => 30,
    "iters" => 100,
    "pop" => 30,
    "dim" => 30,
    "a_strategy" => "sin",
];

// === Model paths ===
$models = [
    "woa"  => "$workdir/models/model_woa.json",
    "ewoa" => "$workdir/modelsmodel_ewoa_radiomics.json",
    "default" => "$workdir/modelsmodel_ewoa_radiomics.json"
];

// Return the config as array
return [
    "python_path" => $python,
    "workdir" => $workdir,
    "defaults" => $defaults
];

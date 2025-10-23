<?php
$python = "/Users/Jae/Documents/WOA-TOOL/.venv/bin/python3";
$workdir = "/Users/Jae/Documents/WOA-TOOL";

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
    "ewoa" => "$workdir/models/model_ewoa_finalfinal.json",
    "default" => "$workdir/models/model_ewoa_finalfinal.json"
];

// Return the config as array
return [
    "python_path" => $python,
    "workdir" => $workdir,
    "defaults" => $defaults
];

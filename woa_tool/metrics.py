from __future__ import annotations

import numpy as np
from dataclasses import dataclass, field
from typing import List, Dict


@dataclass
class RunHistory:
    # ----------------------------
    # Core per-iteration logs
    # ----------------------------
    best_fitness_per_iter: List[float] = field(default_factory=list)
    times_ms_per_iter: List[float] = field(default_factory=list)
    diversity_per_iter: List[float] = field(default_factory=list)

    # ----------------------------
    # Exploration / Exploitation tracking
    # ----------------------------
    exploration_steps: int = 0
    exploitation_steps: int = 0
    exploration_count_per_iter: List[int] = field(default_factory=list)
    exploitation_count_per_iter: List[int] = field(default_factory=list)

    # ----------------------------
    # FINAL METRICS (set by algorithms.py)
    # ----------------------------
    exploration_ratio: float | None = None
    exploitation_ratio: float | None = None
    convergence_iter: int | None = None

    # ----------------------------
    # EER tracking
    # ----------------------------
    eer_per_iter: List[float] = field(default_factory=list)
    mean_eer: float | None = None
    
    selected_feature_indices: List[int] = field(default_factory=list)



# ============================================================
# Aggregation helpers (USED BY bench.py)
# ============================================================

def summarize_runtime_seconds(histories: List[RunHistory]) -> float:
    if not histories:
        return 0.0
    totals = [np.sum(h.times_ms_per_iter) / 1000.0 for h in histories if h.times_ms_per_iter]
    return float(np.mean(totals)) if totals else 0.0


def summarize_average_eer(histories: List[RunHistory]) -> float:
    if not histories:
        return 0.0
    vals = [h.mean_eer for h in histories if h.mean_eer is not None]
    return float(np.mean(vals)) if vals else 0.0


def summarize_average_diversity(histories: List[RunHistory]) -> float:
    if not histories:
        return 0.0
    vals = [np.mean(h.diversity_per_iter) for h in histories if h.diversity_per_iter]
    return float(np.mean(vals)) if vals else 0.0


# ============================================================
# Optional analysis utilities (safe to keep)
# ============================================================

def convergence_stats_from_history(history: RunHistory) -> Dict[str, float]:
    y = np.array(history.best_fitness_per_iter, dtype=float)
    if y.size == 0:
        return {
            "iterations_to_converge": 0.0,
            "convergence_time_s": 0.0,
            "best_fitness_value": float("inf"),
        }

    final_best = float(np.min(y))
    tol = 1e-12
    hits = np.where(np.isclose(y, final_best, atol=tol))[0]
    idx = int(hits[0]) if hits.size > 0 else len(y) - 1

    iters_to_conv = float(idx + 1)
    tms = np.array(history.times_ms_per_iter, dtype=float)
    t_to_conv = float(np.sum(tms[: idx + 1]) / 1000.0) if tms.size > 0 else 0.0

    return {
        "iterations_to_converge": iters_to_conv,
        "convergence_time_s": t_to_conv,
        "best_fitness_value": final_best,
    }

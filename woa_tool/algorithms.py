from __future__ import annotations

import time
import numpy as np
from typing import Callable, Tuple, Optional
from typing import List


from .utils import ensure_bounds, initialize_population, population_diversity
from .fitness import evaluate_population
from .metrics import RunHistory
from .adaptive import (
    a_linear,
    a_sin,
    a_cos,
    a_tan,
    a_log,
    a_square,
    modulate_by_diversity,
)
from .obl import opposite, select_better


EPS = 1e-9           # numerical stability for EER
EPS_CONV = 1e-4      # convergence threshold
K_CONV = 20          # consecutive stable iterations


def _compute_a(strategy: str, t: int, T: int, diversity: float,
               diversity_aware: bool, adaptive_a: bool) -> float:
    if not adaptive_a:
        return a_linear(t, T)
    if strategy == "linear":
        a_val = a_linear(t, T)
    elif strategy == "sin":
        a_val = a_sin(t, T)
    elif strategy == "cos":
        a_val = a_cos(t, T)
    elif strategy == "tan":
        a_val = a_tan(t, T)
    elif strategy == "log":
        a_val = a_log(t, T)
    elif strategy == "square":
        a_val = a_square(t, T)
    else:
        raise ValueError(f"Unknown a(t) strategy: {strategy}")
    if diversity_aware:
        a_val = modulate_by_diversity(a_val, diversity)
    return float(np.clip(a_val, 0.0, 2.0))


# ============================================================
# STANDARD WOA
# ============================================================
def run_woa(
    objective: Callable[[np.ndarray], float],
    dim: int,
    bounds: Tuple[np.ndarray, np.ndarray],
    pop_size: int = 30,
    iters: int = 100,
    seed: Optional[int] = None,
):
    if seed is not None:
        np.random.seed(seed)

    population = initialize_population(pop_size, dim, bounds)
    fitness = evaluate_population(population, objective)

    best_idx = int(np.argmin(fitness))
    best_pos = population[best_idx].copy()
    best_fit = float(fitness[best_idx])

    history = RunHistory()

    for t in range(1, iters + 1):
        start = time.time()

        a = a_linear(t, iters)
        r = np.random.rand(pop_size, 1)
        A = 2 * a * r - a
        C = 2 * r
        p = np.random.rand(pop_size, 1)
        l = np.random.uniform(-1, 1, size=(pop_size, 1))

        new_population = population.copy()
        exp_ct, expt_ct = 0, 0

        for i in range(pop_size):
            if p[i] < 0.5:
                if abs(A[i, 0]) < 1:
                    expt_ct += 1
                    history.exploitation_steps += 1
                    D = np.abs(C[i, 0] * best_pos - population[i])
                    new_population[i] = best_pos - A[i, 0] * D
                else:
                    exp_ct += 1
                    history.exploration_steps += 1
                    rand_idx = np.random.randint(pop_size)
                    Xrand = population[rand_idx]
                    D = np.abs(C[i, 0] * Xrand - population[i])
                    new_population[i] = Xrand - A[i, 0] * D
            else:
                expt_ct += 1
                history.exploitation_steps += 1
                D = np.abs(best_pos - population[i])
                new_population[i] = (
                    D * np.exp(l[i, 0]) * np.cos(2 * np.pi * l[i, 0]) + best_pos
                )

        history.exploration_count_per_iter.append(exp_ct)
        history.exploitation_count_per_iter.append(expt_ct)

        new_population = ensure_bounds(new_population, bounds[0], bounds[1])
        population = new_population

        fitness = evaluate_population(population, objective)
        idx = int(np.argmin(fitness))
        if fitness[idx] < best_fit:
            best_fit = float(fitness[idx])
            best_pos = population[idx].copy()

        history.best_fitness_per_iter.append(best_fit)
        history.times_ms_per_iter.append((time.time() - start) * 1000.0)
        history.diversity_per_iter.append(float(population_diversity(population)))

    _finalize_history_metrics(history)
    
        # === SELECTED FEATURE INDICES (FINAL MASK) ===
    selected_feature_indices = [
        i for i, bit in enumerate(best_pos) if bit > 0.5
    ]
    history.selected_feature_indices = selected_feature_indices

    return best_pos, best_fit, history



# ============================================================
# ENHANCED WOA (EWOA)
# ============================================================
def run_ewoa(
    objective: Callable[[np.ndarray], float],
    dim: int,
    bounds: Tuple[np.ndarray, np.ndarray],
    pop_size: int = 30,
    iters: int = 100,
    a_strategy: str = "sin",
    diversity_aware: bool = True,
    adaptive_a: bool = True,
    use_obl: bool = True,
    obl_freq: int = 1,
    obl_rate: float = 1.0,
    seed: Optional[int] = None,
):
    if seed is not None:
        np.random.seed(seed)

    population = initialize_population(pop_size, dim, bounds)
    fitness = evaluate_population(population, objective)

    if use_obl:
        pop_opp = ensure_bounds(opposite(population, bounds), bounds[0], bounds[1])
        fit_opp = evaluate_population(pop_opp, objective)
        population, fitness = select_better(population, pop_opp, fitness, fit_opp)

    best_idx = int(np.argmin(fitness))
    best_pos = population[best_idx].copy()
    best_fit = float(fitness[best_idx])

    history = RunHistory()

    for t in range(1, iters + 1):
        start = time.time()

        div = population_diversity(population)
        a = _compute_a(a_strategy, t, iters, div, diversity_aware, adaptive_a)

        r = np.random.rand(pop_size, 1)
        A = 2 * a * r - a
        C = 2 * r
        p = np.random.rand(pop_size, 1)
        l = np.random.uniform(-1, 1, size=(pop_size, 1))

        new_population = population.copy()
        exp_ct, expt_ct = 0, 0

        for i in range(pop_size):
            if p[i] < 0.5:
                if abs(A[i, 0]) < 1:
                    expt_ct += 1
                    history.exploitation_steps += 1
                    D = np.abs(C[i, 0] * best_pos - population[i])
                    new_population[i] = best_pos - A[i, 0] * D
                else:
                    exp_ct += 1
                    history.exploration_steps += 1
                    rand_idx = np.random.randint(pop_size)
                    Xrand = population[rand_idx]
                    D = np.abs(C[i, 0] * Xrand - population[i])
                    new_population[i] = Xrand - A[i, 0] * D
            else:
                expt_ct += 1
                history.exploitation_steps += 1
                D = np.abs(best_pos - population[i])
                new_population[i] = (
                    D * np.exp(l[i, 0]) * np.cos(2 * np.pi * l[i, 0]) + best_pos
                )

        history.exploration_count_per_iter.append(exp_ct)
        history.exploitation_count_per_iter.append(expt_ct)

        new_population = ensure_bounds(new_population, bounds[0], bounds[1])

        if use_obl and obl_freq > 0 and t % obl_freq == 0:
            count = max(1, int(pop_size * obl_rate))
            idx = np.random.permutation(pop_size)[:count]
            opp = ensure_bounds(opposite(new_population[idx], bounds), bounds[0], bounds[1])
            fit_new = evaluate_population(new_population[idx], objective)
            fit_opp = evaluate_population(opp, objective)
            mask = fit_opp < fit_new
            new_population[idx[mask]] = opp[mask]

        population = new_population
        fitness = evaluate_population(population, objective)

        idx = int(np.argmin(fitness))
        if fitness[idx] < best_fit:
            best_fit = float(fitness[idx])
            best_pos = population[idx].copy()

        history.best_fitness_per_iter.append(best_fit)
        history.times_ms_per_iter.append((time.time() - start) * 1000.0)
        history.diversity_per_iter.append(float(population_diversity(population)))

    _finalize_history_metrics(history)
        # === SELECTED FEATURE INDICES (FINAL MASK) ===
    selected_feature_indices = [
        i for i, bit in enumerate(best_pos) if bit > 0.5
    ]
    history.selected_feature_indices = selected_feature_indices

    return best_pos, best_fit, history


# ============================================================
# FINAL METRIC AGGREGATION (SHARED)
# ============================================================
def _finalize_history_metrics(history: RunHistory):
    # --- Exploration vs Exploitation ratios ---
    total_steps = history.exploration_steps + history.exploitation_steps
    history.exploration_ratio = history.exploration_steps / total_steps if total_steps > 0 else 0.0
    history.exploitation_ratio = history.exploitation_steps / total_steps if total_steps > 0 else 0.0

    # --- Time to Converge (iteration-based) ---
    best_curve = np.array(history.best_fitness_per_iter)
    delta = np.abs(np.diff(best_curve))
    conv_iter = None
    count = 0
    for i, d in enumerate(delta):
        if d < EPS_CONV:
            count += 1
            if count >= K_CONV:
                conv_iter = i
                break
        else:
            count = 0
    history.convergence_iter = conv_iter

    # --- EER ---
    div = np.array(history.diversity_per_iter)
    conv = np.abs(np.diff(best_curve, prepend=best_curve[0]))
    eer_per_iter = div / (conv + EPS)

    history.eer_per_iter = eer_per_iter.tolist()
    history.mean_eer = float(np.mean(eer_per_iter))

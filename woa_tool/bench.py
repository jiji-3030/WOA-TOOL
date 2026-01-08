import argparse
import json
import numpy as np
from typing import List, Dict

from woa_tool.algorithms import run_woa, run_ewoa
from woa_tool.metrics import RunHistory, summarize_average_eer, summarize_runtime_seconds



# -------------------------------------------------
# Benchmark functions (standard definitions)
# -------------------------------------------------
def griewank(x: np.ndarray) -> float:
    x = np.asarray(x)
    sum_sq = np.sum(x**2) / 4000.0
    prod_cos = np.prod(np.cos(x / np.sqrt(np.arange(1, len(x) + 1))))
    return sum_sq - prod_cos + 1.0


def rosenbrock(x: np.ndarray) -> float:
    x = np.asarray(x)
    return np.sum(100.0 * (x[1:] - x[:-1]**2)**2 + (1 - x[:-1])**2)

# -------------------------------------------------
# Benchmark function + bounds
# -------------------------------------------------
FUNC_MAP = {
    "griewank": {
        "func": griewank,
        "bounds": lambda dim: (np.full(dim, -600.0), np.full(dim, 600.0)),
    },
    "rosenbrock": {
        "func": rosenbrock,
        "bounds": lambda dim: (np.full(dim, -30.0), np.full(dim, 30.0)),
    },
}


# -------------------------------------------------
# Aggregation helper
# -------------------------------------------------
def summarize(histories: List[RunHistory], fitness_vals: List[float]) -> Dict:
    exploration = [h.exploration_ratio for h in histories]
    exploitation = [h.exploitation_ratio for h in histories]
    convergence_iters = [h.convergence_iter for h in histories if h.convergence_iter is not None]

    return {
        "best_mean": float(np.mean(fitness_vals)),
        "best_std": float(np.std(fitness_vals)),

        # SOP 1
        "average_eer": summarize_average_eer(histories),

        # SOP 2
        "exploration_ratio_mean": float(np.mean(exploration)),
        "exploration_ratio_std": float(np.std(exploration)),

        "exploitation_ratio_mean": float(np.mean(exploitation)),
        "exploitation_ratio_std": float(np.std(exploitation)),

        "convergence_iter_mean": float(np.mean(convergence_iters)) if convergence_iters else None,
        "convergence_iter_std": float(np.std(convergence_iters)) if len(convergence_iters) > 1 else 0.0,

        "runtime_s": summarize_runtime_seconds(histories),

        # For traceability / Wilcoxon
        "all_fitness": fitness_vals,
    }


# -------------------------------------------------
# Main experiment runner
# -------------------------------------------------
def run_many(
    func_name: str,
    algo: str,
    runs: int,
    pop: int,
    iters: int,
    dim: int,
    seed: int,
):
    spec = FUNC_MAP[func_name]
    objective = spec["func"]
    bounds = spec["bounds"](dim)

    woa_histories, ewoa_histories = [], []
    woa_fitness, ewoa_fitness = [], []

    for r in range(runs):
        run_seed = seed + r

        if algo in ("woa", "both"):
            _, best_fit, hist = run_woa(
                objective, dim, bounds, pop, iters, seed=run_seed
            )
            woa_histories.append(hist)
            woa_fitness.append(best_fit)

        if algo in ("ewoa", "both"):
            _, best_fit, hist = run_ewoa(
                objective, dim, bounds, pop, iters, seed=run_seed
            )
            ewoa_histories.append(hist)
            ewoa_fitness.append(best_fit)

    results = {}
    if woa_histories:
        results["woa"] = summarize(woa_histories, woa_fitness)
    if ewoa_histories:
        results["ewoa"] = summarize(ewoa_histories, ewoa_fitness)

    return results


# -------------------------------------------------
# CLI
# -------------------------------------------------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--functions", nargs="+", required=True)
    parser.add_argument("--algo", choices=["woa", "ewoa", "both"], default="both")
    parser.add_argument("--pop", type=int, default=30)
    parser.add_argument("--iters", type=int, default=500)
    parser.add_argument("--dim", type=int, default=30)
    parser.add_argument("--runs", type=int, default=30)
    parser.add_argument("--seed", type=int, default=123)

    args = parser.parse_args()
    output = {}

    for fname in args.functions:
        output[fname] = run_many(
            fname,
            args.algo,
            args.runs,
            args.pop,
            args.iters,
            args.dim,
            args.seed,
        )

    print(json.dumps(output, indent=2))


if __name__ == "__main__":
    main()

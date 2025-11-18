# woa_tool/cli.py
import argparse
import sys
import json
import os

from woa_tool.train import train
from woa_tool.predict_radiomics import predict_radiomics


def main():
    parser = argparse.ArgumentParser(
        prog="woa-tool",
        description="WOA/EWOA Radiomics Training & Prediction Tool"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    # ==========================================================
    # TRAIN — Radiomics only
    # ==========================================================
    train_parser = subparsers.add_parser(
        "train",
        help="Train EWOA/WOA model using PyRadiomics features"
    )

    # We keep --processed for compatibility, but it is ignored in radiomics mode
    train_parser.add_argument(
        "--processed",
        default="data/processed",
        help="(Ignored in radiomics mode) Path for old handcrafted features"
    )

    train_parser.add_argument(
        "--data-mode",
        type=str,
        default="radiomics",
        choices=["radiomics"],
        help="Only radiomics mode is supported."
    )

    train_parser.add_argument(
        "--algo",
        choices=["woa", "ewoa"],
        default="ewoa",
        help="Optimization algorithm"
    )
    train_parser.add_argument("--iters", type=int, default=100)
    train_parser.add_argument("--pop", type=int, default=30)
    train_parser.add_argument("--a-strategy",
                              choices=["linear", "sin", "cos", "log", "tan", "square"],
                              default="linear")
    train_parser.add_argument("--obl-freq", type=int, default=0)
    train_parser.add_argument("--obl-rate", type=float, default=0.0)
    train_parser.add_argument("--folds", type=int, default=5)
    train_parser.add_argument("--out", default="models/model_radiomics.json")

    # ==========================================================
    # PREDICT — Radiomics CSV row
    # ==========================================================
    pred_parser = subparsers.add_parser(
        "predict-radiomics",
        help="Predict tumor class from a single radiomics CSV row"
    )
    pred_parser.add_argument("--model", required=True, help="Model JSON file")
    pred_parser.add_argument("--csv", required=True,
                             help="CSV file containing ONE PyRadiomics row")
    pred_parser.add_argument("--out-json", default=None,
                             help="Optional output path for prediction JSON")

    # ==========================================================
    # DISPATCH
    # ==========================================================
    args = parser.parse_args()

    # ---------- TRAIN ----------
    if args.command == "train":
        train(
            algo=args.algo,
            iters=args.iters,
            pop=args.pop,
            a_strategy=args.a_strategy,
            obl_freq=args.obl_freq,
            obl_rate=args.obl_rate,
            out=args.out,
            folds=args.folds,
            data_mode=args.data_mode
        )
        return 0

    # ---------- PREDICT ----------
    if args.command == "predict-radiomics":

        if not os.path.isfile(args.model):
            print(f"❌ Model not found: {args.model}")
            return 2
        if not os.path.isfile(args.csv):
            print(f"❌ Radiomics CSV not found: {args.csv}")
            return 2

        result = predict_radiomics(args.model, args.csv)

        if args.out_json:
            with open(args.out_json, "w") as f:
                json.dump(result, f, indent=2)
            print(f"✅ Saved prediction to {args.out_json}")
        else:
            print(json.dumps(result, indent=2))

        return 0

    return 0


if __name__ == "__main__":
    sys.exit(main())

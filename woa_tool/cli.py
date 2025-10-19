# woa_tool/cli.py
import argparse
import sys

import woa_tool.preprocess as preprocess
import woa_tool.train as train
import woa_tool.predict as predict


def main():
    parser = argparse.ArgumentParser(prog="woa-tool", description="WOA vs EWOA tool (with OBL and adaptive parameters)")

    subparsers = parser.add_subparsers(dest="command", required=True)

    # --------------------------
    # preprocess
    # --------------------------
    prep_parser = subparsers.add_parser("preprocess", help="Extract image features and save processed numpy arrays")

    # --------------------------
    # train
    # --------------------------
    train_parser = subparsers.add_parser("train", help="Train model on processed features")
    train_parser.add_argument("--processed", required=True, help="Path to processed directory (e.g., data/processed)")
    train_parser.add_argument("--algo", choices=["woa", "ewoa"], default="ewoa", help="Algorithm to use")
    train_parser.add_argument("--iters", type=int, default=100, help="Number of iterations")
    train_parser.add_argument("--pop", type=int, default=30, help="Population size")
    train_parser.add_argument("--out", default="models/model.json", help="Output model path")
    train_parser.add_argument("--folds", type=int, default=5, help="Cross-validation folds")
    train_parser.add_argument("--a-strategy", choices=["linear", "sin", "cos", "log", "tan", "square"], default="linear")
    train_parser.add_argument("--obl-freq", type=int, default=0, help="OBL frequency (0 = disabled)")
    train_parser.add_argument("--obl-rate", type=float, default=0.0, help="OBL rate (0.0 = disabled)")

    # --------------------------
    # predict
    # --------------------------
    pred_parser = subparsers.add_parser("predict", help="Predict class for a new image")
    pred_parser.add_argument("--model", required=True, help="Path to trained model JSON")
    pred_parser.add_argument("--image", required=True, help="Path to image file")

    args = parser.parse_args()

    # --------------------------
    # Dispatch
    # --------------------------
    if args.command == "preprocess":
        preprocess.run()

    elif args.command == "train":
        train.train(
            processed_dir=args.processed,
            algo=args.algo,
            iters=args.iters,
            pop=args.pop,
            out=args.out,
            folds=args.folds,
            a_strategy=args.a_strategy,
            obl_freq=args.obl_freq,
            obl_rate=args.obl_rate,
        )

    elif args.command == "predict":
        result = predict.predict(args.model, args.image)
        print(result)


if __name__ == "__main__":
    sys.exit(main())

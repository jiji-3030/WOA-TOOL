# woa_tool/cli.py
import argparse
import sys
import json
import woa_tool.preprocess as preprocess
import woa_tool.train as train
import woa_tool.predict as predict


def main():
    parser = argparse.ArgumentParser(prog="woa-tool", description="WOA/EWOA Tool CLI")
    subparsers = parser.add_subparsers(dest="command", required=True)

    # --- Preprocess command ---
    subparsers.add_parser("preprocess", help="Preprocess dataset (TIFF images -> features/npy)")

    # --- Train command ---
    train_parser = subparsers.add_parser("train", help="Train model using WOA/EWOA feature selection")
    train_parser.add_argument("--data", required=True, help="CSV file (train.csv)")
    train_parser.add_argument("--images", required=True, help="Path to images directory")
    train_parser.add_argument("--algo", choices=["woa", "ewoa"], default="ewoa", help="Algorithm")
    train_parser.add_argument("--iters", type=int, default=50, help="Iterations")
    train_parser.add_argument("--pop", type=int, default=20, help="Population size")
    train_parser.add_argument("--out", default="models/model.json", help="Output model file")
    train_parser.add_argument("--folds", type=int, default=5, help="Cross-validation folds")

    # --- Predict command ---
    predict_parser = subparsers.add_parser("predict", help="Predict on a single image using trained model")
    predict_parser.add_argument("--model", required=True, help="Path to model.json")
    predict_parser.add_argument("--image", required=True, help="Image ID (e.g., IMG001 or path)")

    args = parser.parse_args()

    if args.command == "preprocess":
        preprocess.run()
    elif args.command == "train":
        train.train(args.data, args.images, args.algo, args.iters, args.pop, args.out, args.folds)
    elif args.command == "predict":
        result = predict.predict(args.model, args.image)
        print(json.dumps(result, indent=2))

if __name__ == "__main__":
    main()

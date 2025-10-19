
# 📘 README.md

```markdown
# WOA-TOOL: Whale Optimization Algorithm vs Enhanced WOA

This project implements the **Whale Optimization Algorithm (WOA)** and an **Enhanced WOA (EWOA)** that integrates:
- Adaptive parameter tuning (dynamic adjustment of the `a` parameter)
- Opposition-Based Learning (OBL) to avoid local minima

The tool supports an **image-based classification pipeline** for breast cancer detection. It includes:
1. Preparing metadata from raw datasets
2. Preprocessing
3. Training a model (WOA or EWOA)
4. Predicting on new images

A **PHP-based frontend** is also included to run predictions via a web dashboard.

---

## 📂 Project Structure

```

WOA-TOOL/
├── .gitignore
├── README.md
├── requirements.txt
│
├── woa_tool/                  # Core Python backend
│   ├── __init__.py
│   ├── adaptive.py
│   ├── algorithms.py
│   ├── cli.py                 # CLI entrypoint (preprocess, train, predict, etc.)
│   ├── feature_extraction.py  # (if still used)
│   ├── fitness.py
│   ├── metrics.py
│   ├── obl.py
│   ├── predict.py             # prediction logic
│   ├── prepare_metadata.py    # prepares CSV metadata from raw images
│   ├── preprocess.py          # cleans/prepares datasets
│   ├── train.py               # training logic (woa/ewoa)
│   └── utils.py
│
├── php/                       # Frontend dashboard
│   ├── index.php              # main UI for running predictions
│   ├── config.php             # config (Python path, workdir, defaults)
│   └── test_uploads/          # temporary uploads (ignored in Git)
│
├── data/                      # (ignored) place datasets here
│   ├── images/                # raw images (e.g., IMG001.tif)
│   ├── train.csv              # generated metadata (after prepare_metadata)
│   ├── test.csv               # optional test metadata
│   └── processed/             # cleaned CSVs & NumPy arrays (after preprocess)
│
├── models/                    # (ignored) trained models stored here
│   └── model.json
│
└── backups/                   # (optional) local backups (ignored)

---

## ⚙️ Installation

### 1. Clone the repository
```bash
git clone https://github.com/<your-username>/WOA-TOOL.git
cd WOA-TOOL
````

### 2. Create a virtual environment

```bash
python3 -m venv .venv
source .venv/bin/activate   # On macOS/Linux
# OR
.venv\Scripts\activate      # On Windows
```

### 3. Install dependencies

```bash
pip install -r requirements.txt
```

---

## 📊 Dataset Preparation

1. Place your dataset images inside `data/images/`.
   Example:

   ```
   data/images/
   ├── IMG001.tif
   ├── IMG002.tif
   └── ...
   ```

2. Run the **metadata preparation script**:

   ```bash
   python3 woa_tool/prepare_metadata.py
   ```

   This generates `data/train.csv` and/or `data/test.csv` with the required format.

3. Run preprocessing:

   ```bash
   python3 -m woa_tool.cli preprocess
   ```

---

## ▶️ Training a Model

Train with WOA or EWOA. Example with EWOA:

```bash
python3 -m woa_tool.cli train \
  --data data/train.csv \
  --images data/images \
  --algo ewoa \
  --iters 100 \
  --pop 30 \
  --out models/model.json \
  --folds 5
```

Arguments:

* `--data` → path to CSV file
* `--images` → image folder
* `--algo` → algorithm (`woa` or `ewoa`)
* `--iters` → iterations
* `--pop` → population size
* `--out` → output path for trained model
* `--folds` → cross-validation folds

---

## 🔍 Predicting on a New Image

```bash
python3 -m woa_tool.cli predict \
  --model models/model.json \
  --image data/images/IMG012.tif
```

---

## 🌐 PHP Frontend Dashboard

1. Start PHP server:

   ```bash
   cd php
   php -S localhost:8000
   ```
2. Open `http://localhost:8000`

### Files of Interest

* `php/index.php` – dashboard UI
* `php/config.php` – config for Python path, workdir, defaults
* `php/test_uploads/` – temp uploads (ignored)

---

## 📊 Data & Models

The following are excluded from GitHub but must exist locally:

* `data/` → raw images + generated CSV metadata
* `models/` → trained model files
* `php/test_uploads/` → temp uploads

---

## 👩‍💻 Authors

* **Backend & Research:** Python implementation of WOA/EWOA for preprocessing, training & prediction
* **Frontend:** PHP/Chart.js dashboard

---

## 📌 Notes

* Always run `prepare_metadata` before `preprocess`.
* Datasets, models, and uploads are ignored in GitHub.
* This repo contains only the **code**, not the data/models.

```

---
- Step 1 → run `prepare_metadata` (creates CSVs).  
- Step 2 → `preprocess`.  
- Step 3 → `train`.  
- Step 4 → `predict` or use PHP frontend.  



## 🔬 How Images Are Processed into Numerical Features

The WOA-TOOL backend works with medical biopsy images (e.g., `.tif` files) and transforms them into numerical representations that can be optimized using **WOA/EWOA**.  
This ensures the algorithm can “see” the image in a form suitable for mathematical optimization.

### 1. Raw Data
- Input images are stored in `data/images/` (e.g., `IMG001.tif`, `IMG002.tif`).
- A metadata CSV (`train.csv`) links each image to its **label** (e.g., `benign`, `malignant`).

Example `train.csv`:
```csv
id,label
IMG001,benign
IMG002,malignant
IMG003,benign
````

---

### 2. Metadata Preparation

Run:

```bash
python3 -m woa_tool.cli prepare_metadata
```

This script:

* Scans `data/images/`
* Generates or validates `train.csv` and `test.csv`
* Ensures every image has a matching row in the metadata file

---

### 3. Preprocessing

Run:

```bash
python3 -m woa_tool.cli preprocess
```

This step:

* Converts each image into **numerical feature vectors** using image processing techniques.
* Typical calculations include:

  * **Shape features** → area, perimeter, compactness
  * **Texture features** → contrast, smoothness, entropy
  * **Intensity features** → mean pixel value, variance
* Missing values are imputed (filled) where necessary.
* Normalization is applied so features are on a comparable scale.

Outputs:

* Cleaned CSVs (e.g., `processed_train.csv`)
* NumPy arrays (e.g., `X_train.npy`, `y_train.npy`) inside `data/processed/`

---

### 4. Labels

* The `label` column in the metadata file is mapped to numerical targets:

  * `benign` → `0`
  * `malignant` → `1`
* This allows optimization + training algorithms to work with numerical classification.

---

### 5. Training

Run:

```bash
python3 -m woa_tool.cli train \
  --data data/train.csv \
  --images data/images \
  --algo ewoa \
  --iters 100 \
  --pop 30 \
  --out models/model.json \
  --folds 5
```

Here:

* The extracted feature matrix (`X`) and label vector (`y`) are fed into the WOA/EWOA optimizer.
* WOA/EWOA searches for the **best feature subset and model parameters** that maximize classification accuracy.
* Output is saved as a trained model (`models/model.json`).

---

### 6. Prediction

Run:

```bash
python3 -m woa_tool.cli predict \
  --model models/model.json \
  --image data/images/IMG012.tif
```

* The image is processed into numerical features.
* Features are passed into the trained model.
* Output is a **predicted label** (e.g., “malignant”) with supporting features that led to the decision.

---



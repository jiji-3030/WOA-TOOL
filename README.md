Ahhh perfect 👌 thanks for pointing that out. That means instead of manually telling users how to prepare `train.csv`, we should explain that they must run your **`prepare_metadata.py`** step before `preprocess`.

Let me rewrite the README so it matches your actual CLI workflow:

---

# 📘 README.md (with `prepare_metadata.py`)

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
├── woa_tool/
│   ├── prepare_metadata.py # Creates train/test CSVs from raw dataset
│   ├── preprocess.py       # Cleans & preprocesses data
│   ├── train.py            # Training logic
│   ├── predict.py          # Prediction logic
│   └── ...
├── php/
│   ├── index.php           # Main dashboard
│   ├── config.php          # Config for paths & defaults
│   └── test_uploads/       # Temp uploads (ignored)
├── data/                   # (Ignored in Git) place raw images/datasets here
├── models/                 # (Ignored in Git) trained model output
├── requirements.txt
└── README.md

````

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
   python3 -m woa_tool.cli prepare_metadata
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

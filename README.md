# Advanced WooCommerce CSV Product Importer

[![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-Compatible-purple.svg)](https://woocommerce.com/)
[![HPOS Ready](https://img.shields.io/badge/HPOS-Ready-green.svg)](https://woocommerce.com/)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-blue.svg)](https://php.net/)

A professional-grade, high-performance WordPress WooCommerce plugin designed to import large CSV product files (30k+ products) seamlessly on standard hosting environments. Engineered with a scalable, queue-based background processing architecture, strict schema validation, robust retry systems, and optimized database transactions.

---

## 🌟 Key Features

### ⚡ Performance & Scalability
- **Memory-Safe Stream Parsing**: Stream-reads CSV files dynamically line-by-line using `fopen()` and `fgetcsv()`. Never loads full files into memory, keeping RAM flat ($<5\text{MB}$) for 30,000+ items.
- ** sequential Background Processing**: Integrates with the **WooCommerce Action Scheduler** to run asynchronous sequential batches (e.g. 100, 250, 500 rows). Eliminates server timeouts, browser freezes, and database locking.
- **Hook & Caching Deferrals**: Temporarily suspends term counts, indexing, and transient queries during import runs. Restores hooks and cleans up caches automatically after a batch concludes.

### 🛡️ Strict Validation & Deduplication
- **Pre-Import Schema Scanner**: Performs instant validation runs for duplicates, invalid SKUs, empty name variables, and malformed prices, outputting an invalid row CSV for fast audits.
- **MD5 Image Deduplication**: Downloads remote images securely via sideloading, maintaining an MD5 file-hash database to prevent double downloads and media storage bloat.
- **Custom Table Job Audits**: Custom indexing tables (`wp_wc_csv_import_jobs` & `wp_wc_csv_import_logs`) record run status, elapsed parameters, and full failure stack traces.

### 🎨 Premium Setup Wizard Admin UI
- **Step 1: Secure Upload**: Drag-and-drop secure AJAX uploader supporting 100MB+ large CSV files.
- **Step 2: Flexible Mapping**: Auto-match headers to WooCommerce product attributes, customize duplicate SKU strategy (Skip, Update, Replace, Draft), and specify batch bounds.
- **Step 3: Interactive Progress**: Real-time percentage meters, processed/failed numbers, and actual time calculations with pause/resume/cancellation endpoints.
- **Step 4: Audit Reports**: View total logs summaries, download error CSV archives, and run automated retries for failures.

### 💻 WP-CLI CLI Command
- Run lightning-fast product database imports directly from system terminal with progress indicators:
  ```bash
  wp wc-import path/to/products.csv --batch-size=250 --mode=live --duplicate=update
  ```

---

## 📂 Codebase Directory Layout

```
advanced-woocommerce-csv-importer/
├── advanced-woocommerce-csv-importer.php  # Main bootstrapping & WooCommerce active notice
├── README.md                              # Repository Documentation
├── includes/
│   ├── Autoloader.php                    # PSR-4 dynamic class autoloader
│   ├── Database.php                      # Creates wp_wc_csv_import_jobs & logs custom schemas
│   ├── Plugin.php                        # Singleton bootstrapper initializing services & controllers
│   ├── Repository/
│   │   ├── JobRepository.php             # Core queries for Job states
│   │   └── LogRepository.php             # Core queries for Log indices
│   └── Services/
│       ├── CsvParser.php                 # Memory-safe file stream reader
│       ├── ImportValidator.php           # Scans and validates layouts before runs
│       ├── ProductImporter.php           # Core WooCommerce HPOS Product CRUD Mapper
│       ├── ImageImporter.php             # MD5-hash verified media sideload downloader
│       ├── LoggerService.php             # Execution logging & exports CSV generator
│       ├── QueueManager.php              # Action Scheduler async sequentially batcher
│       └── RetryService.php              # Audits and re-schedules failed rows
├── admin/
│   ├── Controller.php                    # Submenu page additions & scripts/styles loading
│   ├── AjaxHandler.php                   # Nonce-secured AJAX endpoints
│   ├── assets/
│   │   ├── css/admin-style.css           # Modern, premium glassmorphism layouts styling
│   │   └── js/admin-script.js            # Front-end AJAX wizard controller
│   └── templates/
│       └── import-wizard.php             # Step panels HTML dashboard structures
└── cli/
    └── CliController.php                 # WP-CLI command integration
```

---

## 🛠️ Installation & Setup

### Requirements
- PHP 7.4 or higher
- WordPress 5.6 or higher
- WooCommerce 5.0 or higher (WooCommerce must be active)

### Installation Steps
1. Clone or download this repository directly:
   ```bash
   git clone https://github.com/your-username/advanced-woocommerce-csv-importer.git
   ```
2. Move the directory inside your WordPress site's plugins folder:
   `wp-content/plugins/`
3. Go to **WordPress Dashboard -> Plugins** and click **Activate** on *Advanced WooCommerce CSV Product Importer*.
4. Navigate to **WooCommerce -> CSV Importer** to open the wizard dashboard.

---

## 🔒 Security Best Practices
- **Strict Role Permissions**: AJAX endpoints locked to the `manage_woocommerce` capability, ensuring only authorized administrators can upload and import products.
- **CSRF Protection**: All form and wizard communications utilize secure WordPress Nonces (`wp_create_nonce`).
- **Data Sanitization**: CSV parser sanitizes headers, files utilize native WordPress sanitization, and SQL interfaces leverage safe `wpdb->prepare()` to eliminate SQL injection vulnerabilities.

---

## 📄 License
This project is licensed under the GPL v2 or later License.

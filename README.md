# BuildMart POS — Construction Materials Store Management System

A complete point-of-sale and store management system built with PHP + MySQL,
designed specifically for construction materials / hardware stores.

---

## Stack

- **Backend:** PHP 8.1+ (monolithic, server-side rendering)
- **Database:** MySQL 5.7+ / MariaDB 10.3+
- **Frontend:** Vanilla JS + Feather Icons CDN, IBM Plex Sans font
- **Theme:** Dark industrial (amber accent)
- **Languages:** English 🇬🇧 + Russian 🇷🇺

---

## Quick Setup

### 1. Requirements

- PHP 8.1+, with extensions: `pdo_mysql`, `mbstring`, `gd`, `fileinfo`
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` (or Nginx with equivalent config)

### 2. Database

```sql
-- Create DB and import schema
mysql -u root -p < database.sql
```

Or via phpMyAdmin: create database `buildmart_pos`, then import `database.sql`.

### 3. Configure

Edit `config/config.php` (or use environment variables):

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'buildmart_pos');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

Or set environment variables:
```
DB_HOST=localhost
DB_NAME=buildmart_pos
DB_USER=root
DB_PASS=secret
```

### 4. Permissions

```bash
chmod 755 uploads/products/
```

### 5. Web server

**Apache** — the included `.htaccess` handles everything.
Point your virtual host document root to the project folder.

**Nginx** example:
```nginx
server {
    root /var/www/buildmart;
    index index.php;
    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.1-fpm.sock; include fastcgi_params; }
    location ~* ^/(core|lang)/ { deny all; }
}
```

---

## Initial Access

No default application users are seeded in `database.sql`.
Create the first administrator manually after installation, using a strong password and, if enabled, a non-trivial PIN.

---

## Project Structure

```
buildmart/
├── index.php               # Dashboard (entry point)
├── database.sql            # Full schema + seed data
├── .htaccess               # Apache config
├── config/
│   └── config.php          # DB config, paths, constants
├── core/
│   ├── bootstrap.php       # Session, autoload, lang init
│   ├── Database.php        # PDO singleton
│   ├── Auth.php            # Authentication & RBAC
│   ├── Lang.php            # Multilingual system
│   └── helpers.php         # Global helper functions
├── lang/
│   ├── en.php              # English strings
│   └── ru.php              # Russian strings
├── assets/
│   ├── css/app.css         # Main stylesheet (dark theme)
│   └── js/app.js           # POS JS, cart, modals
├── uploads/products/       # Product images (auto-created)
├── views/
│   ├── layouts/
│   │   ├── header.php      # HTML layout header + sidebar
│   │   └── footer.php      # HTML layout footer
│   └── partials/
│       ├── icons.php       # Feather icon helper
│       └── 403.php         # Access denied page
└── modules/
    ├── auth/               # Login / Logout
    ├── pos/                # POS cashier interface
    ├── products/           # Product management
    ├── categories/         # Categories
    ├── inventory/          # Stock management
    ├── customers/          # Customer profiles
    ├── shifts/             # Cashier shifts
    ├── sales/              # Sales history
    ├── reports/            # Reports & analytics
    ├── settings/           # Store settings
    └── users/              # User management (Admin)
```

---

## Features

### POS / Cash Register
- Product search by name, SKU, or barcode
- Category tabs for quick browsing
- Cart with quantity editing and per-sale discounts
- Cash / Card / Mixed payments
- Automatic change calculation
- Receipt printing (80mm thermal printer optimised)
- Shift management

### Products
- Full product catalog with EN + RU names
- SKU, barcode, brand, category, unit of measure
- Sale price, purchase cost, VAT rate
- Stock quantity + low-stock alert threshold
- Product images
- 15 units of measure: pcs, kg, g, t, l, ml, m, m², m³, pack, roll, bag, box, pair, set

### Inventory
- Stock receiving (with unit cost)
- Stock adjustment (inventory count)
- Write-off with reason
- Complete movement history with audit trail
- Low-stock / out-of-stock alerts

### Customers
- Customer profiles with phone, email, company, INN
- Loyalty discount % per customer
- Purchase history
- Lifetime spend tracking

### Reports
- Daily / weekly / monthly / custom periods
- Revenue, profit, average receipt
- Best-selling products
- Revenue by category
- Cashier performance
- Low-stock report

### Multilingual
- English + Russian, easily extensible
- Language stored per-user in DB
- Language switcher in sidebar
- All UI elements, units, statuses translated

---

## Adding a New Language

1. Copy `lang/en.php` → `lang/xx.php` (replace `xx` with ISO code)
2. Translate the strings
3. Add to `SUPPORTED_LANGS` in `config/config.php`:
   ```php
   define('SUPPORTED_LANGS', ['en'=>'English','ru'=>'Русский','de'=>'Deutsch']);
   ```

---

## Security Notes

- All SQL via prepared statements (no string interpolation in queries)
- CSRF tokens on all forms
- Password hashing with `password_hash(BCRYPT)`
- Session regeneration on login
- `htmlspecialchars()` on all output via `e()` helper
- File uploads: extension whitelist + random filenames
- `.htaccess` blocks direct access to `core/` and `lang/` dirs
- HttpOnly + SameSite=Lax session cookies
- No default admin credentials are shipped in the database seed

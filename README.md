## Placement Pulse (PlaceHub) — Local setup (Windows / XAMPP)

### Requirements
- **PHP 8.2+** (XAMPP or standalone PHP)
- **Composer**
- **MariaDB / MySQL** (local or remote — cPanel includes MariaDB)
- **PHP extensions**: `pdo`, `pdo_mysql`

### 1) Database

Create an empty database (example):

```sql
CREATE DATABASE pms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Or run:

```powershell
php backend/scripts/create-db.php
```

(Update `DB_USERNAME` / `DB_PASSWORD` in `.env` first if your MariaDB root user has a password.)

### 2) Install PHP dependencies

```powershell
cd C:\Users\HP\v1\placement-pulse
composer install
```

### 3) Configure environment

Copy `.env.example` to `.env` and set MariaDB credentials:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pms_db
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4) Setup DB (tables + default admin)

```powershell
php backend/scripts/setup.php
```

This creates tables and a default admin:
- **Email**: `admin@college.edu`
- **Password**: `Admin@123456`

### 5) Run locally

```powershell
php -S localhost:8080 router.php
```

Open: http://localhost:8080

### cPanel / production

1. Create a MariaDB database and user in cPanel → **MySQL Databases**
2. Copy `.env.production.example` → `.env` on the server with your DB credentials
3. Deploy code and run once: `php backend/scripts/setup.php`

**Update the live site after pushing to GitHub** (SSH or cPanel Terminal, from the site root):

```bash
git pull origin main
```

If the sidebar or other UI still looks old after `git pull`, hard-refresh the browser (`Ctrl+Shift+R`). Frontend scripts use `?v=` cache keys and `.htaccess` sends `no-cache` for `.js` / `.css` files.

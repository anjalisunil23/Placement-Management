## Placement Pulse (PlaceHub) — Local setup (Windows / XAMPP)

### Requirements
- **PHP 8.2+ (XAMPP)** (this repo currently uses `C:\xampp\php\php.exe`)
- **Composer**
- **MongoDB server** (local or remote)
- **PHP MongoDB extension** (**ext-mongodb**) enabled for your PHP

### 1) Enable `ext-mongodb` for XAMPP PHP 8.2 (Windows)
Composer and the app require the PHP MongoDB extension.

1. Check your PHP build:

```powershell
php -v
php -i | findstr /I "Thread Safety Architecture"
```

You typically need **Thread Safety: enabled (TS)** and **x64** for XAMPP.

2. Download the matching `php_mongodb.dll` from PECL.
- Search for: **"PECL mongodb php 8.2 windows dll"**
- Pick the build that matches your PHP:
  - PHP **8.2.x**
  - **TS**
  - **x64**

3. Copy the DLL to:
- `C:\xampp\php\ext\`

4. Enable it in:
- `C:\xampp\php\php.ini`

Add this line (or uncomment if present):

```ini
extension=mongodb
```

5. Restart Apache (XAMPP Control Panel) and re-open your terminal.

6. Verify:

```powershell
php -m | findstr /I mongodb
```

It should print `mongodb`.

### 2) Install PHP dependencies

```powershell
cd C:\Users\HP\v1\placement-pulse
composer install
```

### 3) Configure environment
Copy `.env.example` to `.env` (if present) and set your MongoDB connection string.

If you don’t have an env file yet, check `backend/config/app.php` for defaults.

### 4) Setup DB (indexes + default admin)

```powershell
php backend/scripts/setup.php
```

This creates indexes and a default admin:
- **Email**: `admin@college.edu`
- **Password**: `Admin@123456`

### 5) Start the app (PHP built-in server)

```powershell
php -S localhost:8080 router.php
```

Then open:
- `http://localhost:8080/public-stats.html`


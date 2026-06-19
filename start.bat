@echo off
echo PlaceHub — starting PHP server at http://localhost:8080
echo Use http://localhost:8080 (not 127.0.0.1) in your browser.
echo Press Ctrl+C to stop.
php -S 0.0.0.0:8080 router.php

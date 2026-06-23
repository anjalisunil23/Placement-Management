<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
print_r($_POST);

$checksum = $_POST['checksum'] ?? $_REQUEST['checksum'] ?? null;

if ($checksum) {
    try {
        $_encKey   = substr($checksum, -40);
        $_encData  = substr($checksum, 0, -40);
        $_encIV    = substr(SHA1($_encKey), -16);
        $decrypted_json = openssl_decrypt(base64_decode($_encData), "AES-256-CBC", $_encKey, 0, $_encIV);
        $userData = json_decode($decrypted_json, true);
        if (!$userData) {
            ?><script>window.location.href = "/";</script><?php
            exit;
        }
        print_r($userData);
    } catch (Exception $e) {
        ?><script>window.location.href = "/";</script><?php
        exit;
    }
} else {
    ?><script>window.location.href = "/";</script><?php
    exit;
}

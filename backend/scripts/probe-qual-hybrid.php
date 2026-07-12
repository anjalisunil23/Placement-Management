<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$ref = 'placements.amaljyothi.ac.in';
$headers = [
    'Content-Type: application/x-www-form-urlencoded',
    'Origin: https://www.aesajce.in',
    'Referer: https://www.aesajce.in/',
];

function hit(string $label, string $url, ?string $body): void
{
    global $headers;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo str_pad($label, 28) . " HTTP {$code} len=" . str_pad((string) strlen($raw), 3, ' ', STR_PAD_LEFT);
    if (trim($raw) !== '') {
        echo '  ' . substr(str_replace("\n", ' ', $raw), 0, 100);
    }
    echo "\n";
}

$bodyFull = http_build_query(['admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref]);
$bodyAdmno = http_build_query(['admno' => $admno]);
$qBase = 'method=getStudQual4Placement&authkey=' . rawurlencode($authKey) . '&refurl=' . rawurlencode($ref);

hit('POST url+body full', "https://api.aesajce.in/?{$qBase}", $bodyFull);
hit('POST url+body admno', "https://api.aesajce.in/?{$qBase}", $bodyAdmno);
hit('POST url admno in q', "https://api.aesajce.in/?{$qBase}&admno={$admno}", '');
hit('POST url admno q+body', "https://api.aesajce.in/?{$qBase}&admno={$admno}", $bodyAdmno);
hit('GET url all', "https://api.aesajce.in/?{$qBase}&admno={$admno}", null);
hit('POST only admno in q', 'https://api.aesajce.in/?method=getStudQual4Placement&admno=' . $admno, http_build_query(['authkey' => $authKey, 'refurl' => $ref]));

// Raw string body (no http_build_query encoding)
hit('raw admno body', 'https://api.aesajce.in/?method=getStudQual4Placement', "admno={$admno}&authkey={$authKey}&refurl={$ref}");

// index.php variants
hit('index.php url+body', "https://api.aesajce.in/index.php?{$qBase}&admno={$admno}", $bodyAdmno);

// Compare info with admno only in query
$iq = 'method=getStudInfo4Placement&authkey=' . rawurlencode($authKey) . '&refurl=' . rawurlencode($ref) . '&admno=' . $admno;
hit('info GET all query', "https://api.aesajce.in/?{$iq}", null);
hit('info POST q empty', "https://api.aesajce.in/?{$iq}", '');

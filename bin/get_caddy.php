<?php

// Function to get the latest Caddy release version from GitHub
function getLatestCaddyRelease() {
    $url = "https://api.github.com/repos/caddyserver/caddy/releases/latest";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PHP Script');
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['tag_name'] ?? null;
}

// Function to determine the correct download URL based on the system's architecture
function getCaddyDownloadUrl($version) {
    $os = strtolower(PHP_OS);
    $arch = php_uname('m');

    switch ($arch) {
        case 'x86_64':
            $arch = 'amd64';
            break;
        case 'arm64':
            $arch = 'arm64';
            break;
        default:
            $arch = 'amd64'; // default to amd64 if unknown
            break;
    }

    // Adjusting the OS and architecture in the URL as per Caddy's naming convention
    if (strpos($os, 'win') !== false) {
        $os = 'windows';
    } elseif (strpos($os, 'linux') !== false) {
        $os = 'linux';
    } else {
        $os = 'linux'; // default to Linux if unknown
    }

    return "https://github.com/caddyserver/caddy/releases/download/{$version}/caddy_" . ltrim($version, "v") . "_{$os}_{$arch}.tar.gz";
}

// Main logic to ask user, fetch the latest version, and prepare download URL
echo "Do you want to download and install Caddy? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if(trim(strtolower($line)) != 'yes'){
    echo "Installation aborted.\n";
    exit;
}

$latestVersion = getLatestCaddyRelease();
if (!$latestVersion) {
    echo "Failed to fetch the latest Caddy version.\n";
    exit;
}

$downloadUrl = getCaddyDownloadUrl($latestVersion);

$caddy = file_get_contents($downloadUrl);
file_put_contents(__DIR__ . '/../caddy.tar.gz', $caddy);

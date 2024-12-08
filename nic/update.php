<?php
// Load credentials array (subdomain:passwordHash) from a separate file
$users = include 'accounts.php';

// Check for HTTP Basic Authentication
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    header('WWW-Authenticate: Basic realm="Restricted Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required';
    exit;
}

// Get the Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'];
if (strpos($authHeader, 'Basic ') === 0) {
    $encodedCredentials = substr($authHeader, 6);
    $decodedCredentials = base64_decode($encodedCredentials);
    list($subdomain, $password) = explode(':', $decodedCredentials, 2);


    if (!isset($users[$subdomain])) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Invalid credentials';
        exit;
    }

    if (!password_verify($password, $users[$subdomain])) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Invalid credentials';
        exit;
    }

    // Get IP addresses from 'myip' parameter
    if (isset($_GET['myip'])) {
        $ips = explode(',', $_GET['myip']);
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // Handle IPv4 address
                echo "$ip is an IPv4 address\n";
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                // Handle IPv6 address
                echo "$ip is an IPv6 address\n";
            } else {
                echo "$ip is not a valid IP address\n";
            }
        }
    } else {
        echo 'No IP address provided';
    }
} else {
    header('WWW-Authenticate: Basic realm="Restricted Area"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required';
    exit;
}
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'on'); // Turn off error display for security
ini_set('log_errors', 'on');
ini_set('error_log', __DIR__ . '/error.log'); // Define error log path

require_once __DIR__ . '/libs/OvhDomainAPI.php';

// Load configuration
$config = include 'dynconfig.php';
if (!$config) {
    http_response_code(500);
    echo '911 Internal Server Configurationfile Missing Error'; // Internal Server Error
    exit;
}

// Validate required configuration
$required = ['app_key', 'app_secret', 'consumer_key', 'zone_name', 'endpoint', 'ttl'];
foreach ($required as $param) {
    if (empty($config[$param])) {
        http_response_code(500);
        echo '911 Internal Server Configurationfile Consistency Error'; // Internal Server Error
        exit;
    }
}

// Check for HTTP Basic Authentication
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    header('WWW-Authenticate: Basic realm="Restricted Area"');
    http_response_code(401); // Unauthorized
    echo 'badauth';
    exit;
}

// Load credentials array (remoteSystemName => passwordHash) from a separate file
$users = include 'accounts.php';
if (!$users) {
    http_response_code(500);
    echo '911 Internal Server Accountconfigurationfile Error'; // Internal Server Error
    exit;
}

// Get the Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'];
if (strpos($authHeader, 'Basic ') === 0) {
    $encodedCredentials = substr($authHeader, 6);
    $decodedCredentials = base64_decode($encodedCredentials);
    if (strpos($decodedCredentials, ':') === false) {
        http_response_code(403); // Forbidden
        echo 'badauth';
        exit;
    }
    list($remoteSystemName, $password) = explode(':', $decodedCredentials, 2);

    if (!isset($users[$remoteSystemName])) {
        http_response_code(403); // Forbidden
        echo 'badauth';
        exit;
    }

    if (!password_verify($password, $users[$remoteSystemName])) {
        http_response_code(403); // Forbidden
        echo 'badauth';
        exit;
    }

    // Initialize OVH client with config
    try {
        $ovh = new OvhDomainAPI(
            $config['app_key'],
            $config['app_secret'],
            $config['endpoint'],
            $config['consumer_key']
        );
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo '911 Server Inialization error';
        exit;
    }

    // Define TTL variable
    $ttl = 60; // Set TTL to 60 seconds

    // Initialize variables to hold the first valid IPv4 and IPv6 addresses
    $ipv4 = null;
    $ipv6 = null;

    // Function to extract the first valid IPv4 address from a list
    function extract_first_ipv4($ipList) {
        foreach ($ipList as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        return null;
    }

    // Function to extract the first valid IPv6 address from a list
    function extract_first_ipv6($ipList) {
        foreach ($ipList as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $ip;
            }
        }
        return null;
    }

    // Process 'myip' parameter
    if (isset($_GET['myip']) && !empty($_GET['myip'])) {
        $myip_params = explode(',', $_GET['myip']);
        // Extract IPv4 and IPv6 from myip
        if (is_null($ipv4)) {
            $ipv4 = extract_first_ipv4($myip_params);
        }
        if (is_null($ipv6)) {
            $ipv6 = extract_first_ipv6($myip_params);
        }
    }

    // Process 'myip6' parameter
    if (isset($_GET['myip6']) && !empty($_GET['myip6'])) {
        $myip6_params = explode(',', $_GET['myip6']);
        // Extract IPv4 and IPv6 from myip6
        if (is_null($ipv4)) {
            $ipv4 = extract_first_ipv4($myip6_params);
        }
        if (is_null($ipv6)) {
            $ipv6 = extract_first_ipv6($myip6_params);
        }
    }

    // If no IP is provided, attempt to use the caller's IP address (not fully protocol compliant)
    if (is_null($ipv4) && is_null($ipv6)) {
        $remoteIp = $_SERVER['REMOTE_ADDR'];
        if (filter_var($remoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = $remoteIp;
        } elseif (filter_var($remoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipv6 = $remoteIp;
        } else {
            http_response_code(400); // Bad Request
            echo 'notfqdn'; // Not a Fully Qualified Domain Name or no valid IP provided
            exit;
        }
    }

    // Construct the full subdomain, handling empty domain prefix
    if (!empty($config['domain_prefix'])) {
        $subdomain = "$remoteSystemName.{$config['domain_prefix']}";
    } else {
        $subdomain = $remoteSystemName;
    }

    // Validate escaped subdomain in case someone forgot about it
    $subdomain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $subdomain);

    try {
        // Array to hold response messages
        $responses = [];


        // Query existing DNS records for the subdomain
        $aRecords = $ovh->getDnsRecords($config['zone_name'], $subdomain, 'A');
        if (count($aRecords) > 1) {
            // Multiple records found, respond with error
            http_response_code(500); // Internal Server Error
            echo '911 multiple existing A records in DNS server found instead of one';
            exit;
        }

        $aaaaRecords = $ovh->getDnsRecords($config['zone_name'], $subdomain, 'AAAA');
        if (count($aaaaRecords) > 1) {
            // Multiple records found, respond with error
            http_response_code(500); // Internal Server Error
            echo '911 multiple existing AAAA records in DNS server found instead of one';
            exit;
        }

        // Try Update IPv4 address
        if (!is_null($ipv4)) {

            if (empty($aRecords)) {
                // No records found, create new one with TTL=60
                $ovh->createDnsRecord($config['zone_name'], $subdomain, 'A', $ipv4, $ttl);
                $responses[] = "good $ipv4";
            } else {
                // Exactly one record found, retrieve current IP
                $recordDetails = $ovh->getDnsRecordDetails($config['zone_name'], $aRecords[0]);
                $currentRecordIp = $recordDetails['target']; // Adjust this based on actual API response structure

                if ($currentRecordIp === $ipv4) {
                    // IP matches, no change needed
                    $responses[] = "nochg $ipv4";
                } else {
                    // IP does not match, update the record
                    $ovh->updateDnsRecord($config['zone_name'], $aRecords[0], $ipv4, $ttl);
                    $responses[] = "good $ipv4";
                }
            } 
        } else {
            // If no IPv4 address is provided, delete the existing A record if it exists
            if (!empty($aRecords)) {
                $ovh->deleteDnsRecord($config['zone_name'], $aRecords[0]);
            }
        }


        // Update IPv6 address
        if (!is_null($ipv6)) {

            if (empty($aaaaRecords)) {
                // No records found, create new one with TTL=60
                $ovh->createDnsRecord($config['zone_name'], $subdomain, 'AAAA', $ipv6, $ttl);
                $responses[] = "good $ipv6";
            } else {
                // Exactly one record found, retrieve current IP
                $recordDetails = $ovh->getDnsRecordDetails($config['zone_name'], $aaaaRecords[0]);
                $currentRecordIp = $recordDetails['target']; // Adjust this based on actual API response structure

                if ($currentRecordIp === $ipv6) {
                    // IP matches, no change needed
                    $responses[] = "nochg $ipv6";
                } else {
                    // IP does not match, update the record
                    $ovh->updateDnsRecord($config['zone_name'], $aaaaRecords[0], $ipv6, $ttl);
                    $responses[] = "good $ipv6";
                }
            } 
        } else {
            // If no IPv6 address is provided, delete the existing AAAA record if it exists
            if (!empty($aaaaRecords)) {
                $ovh->deleteDnsRecord($config['zone_name'], $aaaaRecords[0]);
            }
        }

        // Refresh the zone to apply changes
        $ovh->refreshZone($config['zone_name']);

        // Output all response messages separated by newlines
        header('Content-Type: text/plain');
        echo implode("\n", $responses) . "\n";

        http_response_code(200); // OK
        exit;
    } catch (Exception $e) {
        error_log($e->getMessage());
        http_response_code(500); // Internal Server Error
        echo '911 Update Failed Complex Error';
        exit;
    }
} else {
    header('WWW-Authenticate: Basic realm="Restricted Area"');
    http_response_code(401); // Unauthorized
    echo 'badauth';
    exit;
}
?>
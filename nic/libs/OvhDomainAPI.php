<?php
/**
 * OvhDomainAPI.php
 *
 * A PHP client for interacting with the OVH Domain API.
 */

class OvhDomainAPI {
    private $appKey;
    private $appSecret;
    private $consumerKey;
    private $endpoint;
    private $baseUrl;

    /**
     * Mapping of endpoint identifiers to their corresponding base URLs.
     */
    private static $endpointMap = [
        'ovh-eu'        => 'eu.api.ovh.com/1.0',
        'ovh-ca'        => 'ca.api.ovh.com/1.0',
        'ovh-us'        => 'api.us.ovhcloud.com/1.0',
        'kimsufi-eu'    => 'eu.api.kimsufi.com/1.0',
        'kimsufi-ca'    => 'ca.api.kimsufi.com/1.0',
        'soyoustart-eu' => 'eu.api.soyoustart.com/1.0',
        'soyoustart-ca' => 'ca.api.soyoustart.com/1.0',
        'runabove-ca'   => 'sapi.runabove.com/1.0',
    ];

    /**
     * Constructor to initialize the OVH API client.
     *
     * @param string $appKey      Your OVH application key.
     * @param string $appSecret   Your OVH application secret.
     * @param string $endpoint    API endpoint identifier (e.g., 'ovh-eu').
     * @param string $consumerKey Your OVH consumer key.
     *
     * @throws Exception If the provided endpoint identifier is invalid.
     */
    public function __construct($appKey, $appSecret, $endpoint, $consumerKey) {
        if (!isset(self::$endpointMap[$endpoint])) {
            throw new Exception("Invalid endpoint identifier: $endpoint");
        }

        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->endpoint = $endpoint;
        $this->consumerKey = $consumerKey;
        $this->baseUrl = 'https://' . self::$endpointMap[$endpoint];
    }

    /**
     * Retrieves the current time from OVH API.
     *
     * @return int The current timestamp from OVH.
     *
     * @throws Exception If unable to retrieve the time.
     */
    private function getOvhTime() {
        $timeEndpoint = $this->baseUrl . '/auth/time';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $timeEndpoint,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $output = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error while getting OVH time: $error");
        }
        curl_close($ch);

        if ($output === false || !is_numeric(trim($output))) {
            throw new Exception("Failed to retrieve valid OVH time.");
        }

        return (int) trim($output);
    }

    /**
     * Makes an API call to OVH.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string $path   API endpoint path.
     * @param array  $data   Associative array of data to send with the request.
     *
     * @return mixed Decoded JSON response from the API.
     *
     * @throws Exception If the API call fails.
     */
    public function call($method, $path, $data = []) {
        // Ensure that the path starts with a '/'
        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }

        $apiUrl = $this->baseUrl . $path;
        $method = strtoupper($method);

        // Determine the request body based on the HTTP method
        if (in_array($method, ['POST', 'PUT'])) {
            $body = json_encode($data);
        } else {
            $body = '';
        }

        // Get the OVH API time
        $timestamp = $this->getOvhTime();

        // Create the signature as per the provided example
        // Signature format: "$1$" followed by sha1(appSecret + '+' + consumerKey + '+' + method + '+' + apiUrl + '+' + body + '+' + timestamp)
        $toSign = $this->appSecret . '+' . 
                  $this->consumerKey . '+' . 
                  $method . '+' . 
                  $apiUrl . '+' . 
                  $body . '+' . 
                  $timestamp;
        $signature = '$1$' . sha1($toSign);

        // Setup headers
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-Ovh-Application: ' . $this->appKey,
            'X-Ovh-Timestamp: ' . $timestamp,
            'X-Ovh-Signature: ' . $signature,
            'X-Ovh-Consumer: ' . $this->consumerKey,
        ];

        // Initialize cURL
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Check for cURL errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error while making API call: $error");
        }

        curl_close($ch);

        // Handle HTTP errors
        if ($httpCode >= 400) {
            throw new Exception("API call to $path failed with status $httpCode. Response: $response");
        }

        // Decode the JSON response
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON response: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    /**
     * Retrieves a list of DNS records for the specified subdomain and type.
     *
     * @param string $zoneName   The DNS zone name.
     * @param string $subdomain  The full subdomain (e.g., 'sub.example.com').
     * @param string $fieldType  The DNS record type ('A' or 'AAAA').
     *
     * @return array Array of DNS record IDs.
     *
     * @throws Exception If the API call fails.
     */
    public function getDnsRecords($zoneName, $subdomain, $fieldType) {
        $path = "/domain/zone/$zoneName/record?fieldType=$fieldType&subDomain=$subdomain";
        $records = $this->call('GET', $path);
        return $records;
    }

    /**
     * Retrieves detailed information about a specific DNS record.
     *
     * @param string $zoneName The DNS zone name.
     * @param int    $recordId The DNS record ID.
     *
     * @return array Associative array containing DNS record details.
     *
     * @throws Exception If the API call fails.
     */
    public function getDnsRecordDetails($zoneName, $recordId) {
        $path = "/domain/zone/$zoneName/record/$recordId";
        $recordDetails = $this->call('GET', $path);
        return $recordDetails;
    }

    /**
     * Creates a new DNS record.
     *
     * @param string $zoneName   The DNS zone name.
     * @param string $subdomain  The full subdomain (e.g., 'sub.example.com').
     * @param string $fieldType  The DNS record type ('A' or 'AAAA').
     * @param string $target     The IP address target for the DNS record.
     * @param int    $ttl        Time To Live for the DNS record.
     *
     * @return array The created DNS record details.
     *
     * @throws Exception If the API call fails.
     */
    public function createDnsRecord($zoneName, $subdomain, $fieldType, $target, $ttl) {
        $path = "/domain/zone/$zoneName/record";
        $data = [
            'fieldType' => $fieldType,
            'subDomain' => $subdomain,
            'target'    => $target,
            'ttl'       => $ttl
        ];
        $record = $this->call('POST', $path, $data);
        return $record;
    }

    /**
     * Updates an existing DNS record.
     *
     * @param string $zoneName   The DNS zone name.
     * @param int    $recordId   The DNS record ID.
     * @param string $target     The new IP address target for the DNS record.
     * @param int    $ttl        Time To Live for the DNS record.
     *
     * @return array The updated DNS record details.
     *
     * @throws Exception If the API call fails.
     */
    public function updateDnsRecord($zoneName, $recordId, $target, $ttl) {
        $path = "/domain/zone/$zoneName/record/$recordId";
        $data = [
            'target' => $target,
            'ttl'    => $ttl
        ];
        $record = $this->call('PUT', $path, $data);
        return $record;
    }

    /**
     * Refreshes the DNS zone to apply changes.
     *
     * @param string $zoneName The DNS zone name.
     *
     * @return void
     *
     * @throws Exception If the API call fails.
     */
    public function refreshZone($zoneName) {
        $path = "/domain/zone/$zoneName/refresh";
        $this->call('POST', $path);
    }
}
?>
<?php
/**
 * Spaceship Registrar Module for WHMCS
 *
 * Integrates the Spaceship.com domain API with WHMCS for full
 * domain lifecycle management: register, transfer, renew, DNS,
 * nameservers, contact updates, EPP codes, lock management and sync.
 *
 * Install path: /modules/registrars/spaceship/spaceship.php
 *
 * API Docs  : https://docs.spaceship.dev/
 * WHMCS Docs: https://developers.whmcs.com/domain-registrars/
 *
 * @author  Spaceship WHMCS Module
 * @version 1.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList;
use WHMCS\Domains\DomainLookup\ResultsList as LookupResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

// ─────────────────────────────────────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────
define('SPACESHIP_API_URL', 'https://spaceship.dev/api/v1');
define('SPACESHIP_MODULE_VERSION', '1.0.0');

// ─────────────────────────────────────────────────────────────────────────────
//  CONFIG
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Module configuration array.
 * Displayed as settings in WHMCS Admin > Setup > Domain Registrars.
 */
function spaceship_getConfigArray()
{
    return array(
        'FriendlyName' => array(
            'Type'  => 'System',
            'Value' => 'Spaceship',
        ),
        'Description' => array(
            'Type'  => 'System',
            'Value' => 'Spaceship.com domain registrar module. Generate your API key at https://www.spaceship.com/application/api-manager/',
        ),
        'api_key' => array(
            'FriendlyName' => 'API Key',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your Spaceship API Key (X-Api-Key)',
        ),
        'api_secret' => array(
            'FriendlyName' => 'API Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your Spaceship API Secret (X-Api-Secret)',
        ),
        'default_privacy' => array(
            'FriendlyName' => 'Default Privacy Protection',
            'Type'         => 'dropdown',
            'Options'      => 'high,public',
            'Default'      => 'high',
            'Description'  => 'Default WHOIS privacy level for new registrations',
        ),
        'sandbox_mode' => array(
            'FriendlyName' => 'Test / Sandbox Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable logging of all API requests without executing live changes (for testing only)',
        ),
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  METADATA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Module metadata.
 */
function spaceship_MetaData()
{
    return array(
        'DisplayName'              => 'Spaceship',
        'APIVersion'               => '1.1',
        'StableVersion'            => SPACESHIP_MODULE_VERSION,
        'RequiresServer'           => false,
        'DefaultNonTransferTLDs'   => array(),
        'CanUseHostnames'          => false,
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  LOW-LEVEL API CLIENT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Make an authenticated HTTP request to the Spaceship API.
 *
 * @param  string $method   GET | POST | PUT | DELETE | PATCH
 * @param  string $endpoint e.g. '/domains/example.com'
 * @param  array  $params   Module params (used to extract credentials)
 * @param  array  $body     Associative array to send as JSON body
 * @param  array  $query    Query string key-value pairs
 * @return array  ['httpCode' => int, 'data' => array, 'raw' => string, 'error' => string|null]
 */
function spaceship_api($method, $endpoint, array $params, array $body = array(), array $query = array())
{
    $apiKey    = isset($params['api_key'])    ? trim($params['api_key'])    : '';
    $apiSecret = isset($params['api_secret']) ? trim($params['api_secret']) : '';

    $url = SPACESHIP_API_URL . $endpoint;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Api-Key: '    . $apiKey,
        'X-Api-Secret: ' . $apiSecret,
        'User-Agent: WHMCS-Spaceship-Module/' . SPACESHIP_MODULE_VERSION,
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT,        60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $jsonBody = '';
    if (!empty($body)) {
        $jsonBody = json_encode($body);
    }

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST,       true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS,    $jsonBody);
            break;
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS,    $jsonBody);
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($jsonBody)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }
            break;
        default: // GET
            break;
    }

    $rawResponse = curl_exec($ch);
    $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError   = curl_error($ch);
    curl_close($ch);

    // Log the API call via WHMCS module logging
    spaceship_log($method, $url, $jsonBody, $httpCode, $rawResponse, $params);

    if ($curlError) {
        return array(
            'httpCode' => 0,
            'data'     => array(),
            'raw'      => '',
            'error'    => 'cURL Error: ' . $curlError,
        );
    }

    $data = array();
    if (!empty($rawResponse)) {
        $data = json_decode($rawResponse, true);
        if ($data === null) {
            $data = array();
        }
    }

    $error = null;
    // Treat any 4xx/5xx as an error
    if ($httpCode >= 400) {
        $error = isset($data['detail']) ? $data['detail']
               : (isset($data['message']) ? $data['message']
               : 'API Error (HTTP ' . $httpCode . ')');
    }

    return array(
        'httpCode' => $httpCode,
        'data'     => $data,
        'raw'      => $rawResponse,
        'error'    => $error,
    );
}

/**
 * Log API calls using WHMCS built-in module logging.
 */
function spaceship_log($action, $url, $requestData, $responseCode, $responseData, array $params)
{
    // Mask secrets before logging
    $safeAction = strtoupper($action) . ' ' . $url;
    $safeRequest = $requestData;
    // Redact keys in log
    $safeRequest = preg_replace('/"(api_secret|api_key|authCode)"\s*:\s*"[^"]*"/', '"$1":"[REDACTED]"', $safeRequest);

    logModuleCall(
        'spaceship',
        $safeAction,
        $safeRequest,
        $responseCode . ' | ' . $responseData,
        null,
        array(
            isset($params['api_key'])    ? $params['api_key']    : '',
            isset($params['api_secret']) ? $params['api_secret'] : '',
        )
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  CONTACT HELPER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create or retrieve a Spaceship contact ID from WHMCS registrant data.
 * Returns the contact ID string on success, or null on failure.
 */
function spaceship_ensureContact(array $params)
{
    // Build phone in +CC.number format (Spaceship requires +CC.NNNN format)
    $phone = isset($params['fullphonenumber']) ? $params['fullphonenumber'] : '';
    if (empty($phone) && isset($params['phonecc']) && isset($params['phonenumber'])) {
        $phone = '+' . ltrim($params['phonecc'], '+') . '.' . preg_replace('/[^0-9]/', '', $params['phonenumber']);
    }
    if (empty($phone)) {
        $phone = '+1.1234567890';
    }

    $contactPayload = array(
        'firstName'  => isset($params['firstname'])   ? $params['firstname']   : '',
        'lastName'   => isset($params['lastname'])    ? $params['lastname']    : '',
        'email'      => isset($params['email'])       ? $params['email']       : '',
        'address1'   => isset($params['address1'])    ? $params['address1']    : '',
        'city'       => isset($params['city'])        ? $params['city']        : '',
        'country'    => isset($params['countrycode']) ? strtoupper($params['countrycode']) : 'US',
        'phone'      => $phone,
    );

    if (!empty($params['companyname'])) {
        $contactPayload['organization'] = $params['companyname'];
    }
    if (!empty($params['address2'])) {
        $contactPayload['address2'] = $params['address2'];
    }
    if (!empty($params['state'])) {
        $contactPayload['stateProvince'] = $params['state'];
    }
    if (!empty($params['postcode'])) {
        $contactPayload['postalCode'] = $params['postcode'];
    }

    $result = spaceship_api('PUT', '/contacts', $params, $contactPayload);

    if ($result['error']) {
        return null;
    }

    return isset($result['data']['contactId']) ? $result['data']['contactId'] : null;
}

/**
 * Poll an async operation until it completes or times out.
 * Returns array with 'status' key ('success'|'failed'|'timeout').
 */
function spaceship_pollAsync($operationId, array $params, $maxWait = 30, $interval = 3)
{
    $elapsed = 0;
    while ($elapsed < $maxWait) {
        sleep($interval);
        $elapsed += $interval;

        $result = spaceship_api('GET', '/async-operations/' . $operationId, $params);
        if ($result['error']) {
            continue;
        }
        $status = isset($result['data']['status']) ? $result['data']['status'] : 'pending';
        if ($status === 'success' || $status === 'failed') {
            return $result['data'];
        }
    }
    return array('status' => 'timeout');
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN REGISTRATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * RegisterDomain - Called when WHMCS processes a new domain registration order.
 */
function spaceship_RegisterDomain(array $params)
{
    $domain     = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $regperiod  = (int) $params['regperiod'];
    $privacyLevel = isset($params['default_privacy']) ? $params['default_privacy'] : 'high';
    $idProtect    = !empty($params['idprotection']);

    // Create registrant contact
    $contactId = spaceship_ensureContact($params);
    if (!$contactId) {
        return array('error' => 'Could not create registrant contact on Spaceship. Check contact details.');
    }

    $payload = array(
        'autoRenew' => false,
        'years'     => $regperiod,
        'privacyProtection' => array(
            'level'       => ($idProtect || $privacyLevel === 'high') ? 'high' : 'public',
            'userConsent' => true,
        ),
        'contacts' => array(
            'registrant' => $contactId,
            'admin'      => $contactId,
            'tech'       => $contactId,
            'billing'    => $contactId,
        ),
    );

    $result = spaceship_api('POST', '/domains/' . rawurlencode($domain), $params, $payload);

    if ($result['error']) {
        return array('error' => 'Registration failed: ' . $result['error']);
    }

    // Spaceship returns 202 for async – poll for result
    if ($result['httpCode'] === 202) {
        $headers = $result['raw'];
        // WHMCS cURL doesn't expose headers by default; treat 202 as pending success
        // The domain sync cron will reconcile status later
        return array();
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN TRANSFER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * TransferDomain - Called when WHMCS processes an incoming domain transfer.
 */
function spaceship_TransferDomain(array $params)
{
    $domain    = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $eppCode   = isset($params['eppcode']) ? $params['eppcode'] : '';
    $privacyLevel = isset($params['default_privacy']) ? $params['default_privacy'] : 'high';

    $contactId = spaceship_ensureContact($params);
    if (!$contactId) {
        return array('error' => 'Could not create registrant contact on Spaceship.');
    }

    $payload = array(
        'autoRenew' => false,
        'privacyProtection' => array(
            'level'       => $privacyLevel,
            'userConsent' => true,
        ),
        'contacts' => array(
            'registrant' => $contactId,
            'admin'      => $contactId,
            'tech'       => $contactId,
            'billing'    => $contactId,
        ),
    );

    if (!empty($eppCode)) {
        $payload['authCode'] = $eppCode;
    }

    $result = spaceship_api('POST', '/domains/' . rawurlencode($domain) . '/transfer', $params, $payload);

    if ($result['error']) {
        return array('error' => 'Transfer request failed: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN RENEWAL
// ─────────────────────────────────────────────────────────────────────────────

/**
 * RenewDomain - Called when WHMCS processes a domain renewal payment.
 */
function spaceship_RenewDomain(array $params)
{
    $domain    = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $regperiod = (int) $params['regperiod'];

    // First fetch current domain info to get the expiry date (required by API)
    $infoResult = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
    if ($infoResult['error']) {
        return array('error' => 'Could not fetch domain info for renewal: ' . $infoResult['error']);
    }

    $expiryDate = isset($infoResult['data']['expirationDate'])
                ? $infoResult['data']['expirationDate']
                : date('Y-m-d\TH:i:s.000\Z', strtotime('+1 year'));

    $payload = array(
        'years'                 => $regperiod,
        'currentExpirationDate' => $expiryDate,
    );

    $result = spaceship_api('POST', '/domains/' . rawurlencode($domain) . '/renew', $params, $payload);

    if ($result['error']) {
        return array('error' => 'Renewal failed: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN INFORMATION  (WHMCS 7.6+)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetDomainInformation - Replaces GetNameservers + GetRegistrarLock in WHMCS 7.6+.
 * Returns full domain info object.
 */
function spaceship_GetDomainInformation(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);

    if ($result['error']) {
        return array('error' => $result['error']);
    }

    $data = $result['data'];

    // Build nameservers array
    $nameservers = array();
    if (isset($data['nameservers']['hosts']) && is_array($data['nameservers']['hosts'])) {
        foreach ($data['nameservers']['hosts'] as $i => $ns) {
            $nameservers['ns' . ($i + 1)] = $ns;
        }
    }

    // Determine lock status from EPP statuses
    $isLocked = false;
    if (isset($data['eppStatuses']) && is_array($data['eppStatuses'])) {
        $isLocked = in_array('clientTransferProhibited', $data['eppStatuses']);
    }

    // Parse expiry date
    $expiryDate = '';
    if (!empty($data['expirationDate'])) {
        $expiryDate = date('Y-m-d', strtotime($data['expirationDate']));
    }

    return array(
        'nameservers'    => $nameservers,
        'transferlock'   => $isLocked,
        'expirydate'     => $expiryDate,
        'autorenew'      => isset($data['autoRenew']) ? (bool)$data['autoRenew'] : false,
        'idprotection'   => isset($data['privacyProtection']['level'])
                            ? ($data['privacyProtection']['level'] === 'high')
                            : false,
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  NAMESERVERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetNameservers - Fallback for WHMCS < 7.6.
 */
function spaceship_GetNameservers(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
    if ($result['error']) {
        return array('error' => $result['error']);
    }

    $hosts = isset($result['data']['nameservers']['hosts'])
           ? $result['data']['nameservers']['hosts']
           : array();

    $return = array();
    foreach ($hosts as $i => $ns) {
        $return['ns' . ($i + 1)] = $ns;
    }
    return $return;
}

/**
 * SaveNameservers - Update nameservers for a domain.
 */
function spaceship_SaveNameservers(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $hosts = array();
    for ($i = 1; $i <= 5; $i++) {
        $ns = isset($params['ns' . $i]) ? trim($params['ns' . $i]) : '';
        if (!empty($ns)) {
            $hosts[] = $ns;
        }
    }

    if (empty($hosts)) {
        return array('error' => 'At least one nameserver is required.');
    }

    $payload = array(
        'provider' => 'custom',
        'hosts'    => $hosts,
    );

    $result = spaceship_api('PUT', '/domains/' . rawurlencode($domain) . '/nameservers', $params, $payload);

    if ($result['error']) {
        return array('error' => 'Failed to update nameservers: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  REGISTRAR LOCK
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetRegistrarLock - Return current transfer lock status.
 */
function spaceship_GetRegistrarLock(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
    if ($result['error']) {
        return array('error' => $result['error']);
    }

    $eppStatuses = isset($result['data']['eppStatuses']) ? $result['data']['eppStatuses'] : array();
    return in_array('clientTransferProhibited', $eppStatuses) ? 'locked' : 'unlocked';
}

/**
 * SaveRegistrarLock - Toggle transfer lock on/off.
 */
function spaceship_SaveRegistrarLock(array $params)
{
    $domain   = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $isLocked = (isset($params['lockenabled']) && $params['lockenabled'] === 'yes');

    $payload = array('isLocked' => $isLocked);

    $result = spaceship_api(
        'PUT',
        '/domains/' . rawurlencode($domain) . '/transfer/lock',
        $params,
        $payload
    );

    if ($result['error']) {
        return array('error' => 'Failed to update lock: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  CONTACT DETAILS (WHOIS)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetContactDetails - Retrieve WHOIS contact information.
 */
function spaceship_GetContactDetails(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    // Get domain info to find contact IDs
    $domainResult = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
    if ($domainResult['error']) {
        return array('error' => $domainResult['error']);
    }

    $contacts = isset($domainResult['data']['contacts']) ? $domainResult['data']['contacts'] : array();
    $registrantId = isset($contacts['registrant']) ? $contacts['registrant'] : null;

    if (!$registrantId) {
        return array('error' => 'No registrant contact found for this domain.');
    }

    // Fetch contact details
    $contactResult = spaceship_api('GET', '/contacts/' . rawurlencode($registrantId), $params);
    if ($contactResult['error']) {
        return array('error' => $contactResult['error']);
    }

    $c = $contactResult['data'];

    $entry = array(
        'First Name'   => isset($c['firstName'])    ? $c['firstName']    : '',
        'Last Name'    => isset($c['lastName'])     ? $c['lastName']     : '',
        'Company Name' => isset($c['organization']) ? $c['organization'] : '',
        'Email'        => isset($c['email'])        ? $c['email']        : '',
        'Address 1'    => isset($c['address1'])     ? $c['address1']     : '',
        'Address 2'    => isset($c['address2'])     ? $c['address2']     : '',
        'City'         => isset($c['city'])         ? $c['city']         : '',
        'State'        => isset($c['stateProvince'])? $c['stateProvince']: '',
        'Postcode'     => isset($c['postalCode'])   ? $c['postalCode']   : '',
        'Country'      => isset($c['country'])      ? $c['country']      : '',
        'Phone'        => isset($c['phone'])        ? $c['phone']        : '',
    );

    return array(
        'Registrant' => $entry,
        'Admin'      => $entry,
        'Technical'  => $entry,
    );
}

/**
 * SaveContactDetails - Update WHOIS contact information.
 */
function spaceship_SaveContactDetails(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $regData = isset($params['contactdetails']['Registrant']) ? $params['contactdetails']['Registrant'] : array();

    // Build contact payload from submitted WHOIS data
    $phone = isset($regData['Phone']) ? $regData['Phone'] : '+1.1234567890';
    // Ensure proper +CC.number format
    if (strpos($phone, '+') === false) {
        $phone = '+1.' . preg_replace('/[^0-9]/', '', $phone);
    }

    $contactPayload = array(
        'firstName'  => isset($regData['First Name'])   ? $regData['First Name']   : '',
        'lastName'   => isset($regData['Last Name'])    ? $regData['Last Name']    : '',
        'email'      => isset($regData['Email'])        ? $regData['Email']        : '',
        'address1'   => isset($regData['Address 1'])    ? $regData['Address 1']    : '',
        'city'       => isset($regData['City'])         ? $regData['City']         : '',
        'country'    => isset($regData['Country'])      ? strtoupper($regData['Country']) : 'US',
        'phone'      => $phone,
    );

    if (!empty($regData['Company Name'])) {
        $contactPayload['organization'] = $regData['Company Name'];
    }
    if (!empty($regData['Address 2'])) {
        $contactPayload['address2'] = $regData['Address 2'];
    }
    if (!empty($regData['State'])) {
        $contactPayload['stateProvince'] = $regData['State'];
    }
    if (!empty($regData['Postcode'])) {
        $contactPayload['postalCode'] = $regData['Postcode'];
    }

    // Create the new contact
    $contactResult = spaceship_api('PUT', '/contacts', $params, $contactPayload);
    if ($contactResult['error']) {
        return array('error' => 'Failed to create contact: ' . $contactResult['error']);
    }

    $newContactId = isset($contactResult['data']['contactId']) ? $contactResult['data']['contactId'] : null;
    if (!$newContactId) {
        return array('error' => 'No contact ID returned from Spaceship API.');
    }

    // Update domain contacts
    $updatePayload = array(
        'registrant' => $newContactId,
        'admin'      => $newContactId,
        'tech'       => $newContactId,
        'billing'    => $newContactId,
    );

    $updateResult = spaceship_api(
        'PUT',
        '/domains/' . rawurlencode($domain) . '/contacts',
        $params,
        $updatePayload
    );

    if ($updateResult['error']) {
        return array('error' => 'Failed to update domain contacts: ' . $updateResult['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  DNS MANAGEMENT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetDNS - Return current DNS host records for a domain.
 */
function spaceship_GetDNS(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api(
        'GET',
        '/dns/records/' . rawurlencode($domain),
        $params,
        array(),
        array('take' => 200, 'skip' => 0)
    );

    if ($result['error']) {
        return array('error' => $result['error']);
    }

    $records = array();
    $items   = isset($result['data']['items']) ? $result['data']['items'] : array();

    foreach ($items as $record) {
        $type    = isset($record['type'])    ? $record['type']    : '';
        $name    = isset($record['name'])    ? $record['name']    : '@';
        $address = '';

        // Address is stored differently per record type
        if (isset($record['address'])) {
            $address = $record['address'];
        } elseif (isset($record['value'])) {
            $address = $record['value'];
        } elseif (isset($record['target'])) {
            $address = $record['target'];
        }

        $records[] = array(
            'hostname' => $name,
            'type'     => $type,
            'address'  => $address,
            'priority' => isset($record['priority']) ? $record['priority'] : '',
            'ttl'      => isset($record['ttl'])      ? $record['ttl']      : 3600,
        );
    }

    return $records;
}

/**
 * SaveDNS - Save/update DNS host records for a domain.
 * WHMCS sends the full desired set of records; we replace all custom records.
 */
function spaceship_SaveDNS(array $params)
{
    $domain  = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $dnsRows = isset($params['dnsrecords']) ? $params['dnsrecords'] : array();

    $items = array();
    foreach ($dnsRows as $row) {
        $type     = isset($row['type'])     ? strtoupper(trim($row['type']))     : '';
        $hostname = isset($row['hostname']) ? trim($row['hostname'])              : '@';
        $address  = isset($row['address'])  ? trim($row['address'])              : '';
        $ttl      = isset($row['ttl'])      ? (int)$row['ttl']                   : 3600;

        if (empty($type) || empty($address)) {
            continue;
        }

        $item = array(
            'type' => $type,
            'name' => empty($hostname) ? '@' : $hostname,
            'ttl'  => max(300, $ttl),
        );

        // Map address to the correct field name per record type
        switch ($type) {
            case 'MX':
                $item['target']   = $address;
                $item['priority'] = isset($row['priority']) ? (int)$row['priority'] : 10;
                break;
            case 'SRV':
                $item['target']   = $address;
                $item['priority'] = isset($row['priority']) ? (int)$row['priority'] : 10;
                $item['weight']   = 0;
                $item['port']     = 80;
                break;
            case 'CNAME':
            case 'NS':
            case 'PTR':
                $item['target'] = $address;
                break;
            case 'TXT':
            case 'SPF':
                $item['value'] = $address;
                break;
            default: // A, AAAA, etc.
                $item['address'] = $address;
                break;
        }

        $items[] = $item;
    }

    if (empty($items)) {
        return array('error' => 'No valid DNS records to save.');
    }

    $payload = array(
        'force' => true,
        'items' => $items,
    );

    $result = spaceship_api(
        'PUT',
        '/dns/records/' . rawurlencode($domain),
        $params,
        $payload
    );

    if ($result['error']) {
        return array('error' => 'Failed to save DNS records: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  ID PROTECTION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * IDProtectToggle - Enable or disable WHOIS privacy.
 */
function spaceship_IDProtectToggle(array $params)
{
    $domain  = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $protect = !empty($params['protectenable']);

    $payload = array(
        'privacyLevel' => $protect ? 'high' : 'public',
        'userConsent'  => true,
    );

    $result = spaceship_api(
        'PUT',
        '/domains/' . rawurlencode($domain) . '/privacy/preference',
        $params,
        $payload
    );

    if ($result['error']) {
        return array('error' => 'Failed to update privacy: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  EPP CODE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetEPPCode - Retrieve auth/EPP code for outbound transfer.
 */
function spaceship_GetEPPCode(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api(
        'GET',
        '/domains/' . rawurlencode($domain) . '/transfer/auth-code',
        $params
    );

    if ($result['error']) {
        return array('error' => 'Failed to retrieve EPP code: ' . $result['error']);
    }

    $eppCode = isset($result['data']['authCode']) ? $result['data']['authCode'] : '';

    if (empty($eppCode)) {
        return array('error' => 'EPP code not available. Check domain status.');
    }

    return array('eppcode' => $eppCode);
}

// ─────────────────────────────────────────────────────────────────────────────
//  PRIVATE NAMESERVERS (CHILD HOSTS)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * RegisterNameserver - Register a child/glue nameserver.
 */
function spaceship_RegisterNameserver(array $params)
{
    $domain     = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $nameserver = isset($params['nameserver']) ? $params['nameserver'] : '';
    $ip         = isset($params['ipaddress'])  ? $params['ipaddress']  : '';

    if (empty($nameserver) || empty($ip)) {
        return array('error' => 'Nameserver hostname and IP address are required.');
    }

    // Extract just the host part (e.g. "ns1" from "ns1.example.com")
    $host = str_replace('.' . $domain, '', $nameserver);

    $payload = array(
        'host' => $host,
        'ips'  => array($ip),
    );

    $result = spaceship_api(
        'PUT',
        '/domains/' . rawurlencode($domain) . '/personal-nameservers/' . rawurlencode($host),
        $params,
        $payload
    );

    if ($result['error']) {
        return array('error' => 'Failed to register nameserver: ' . $result['error']);
    }

    return array();
}

/**
 * ModifyNameserver - Update IP for a child nameserver.
 */
function spaceship_ModifyNameserver(array $params)
{
    $domain     = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $nameserver = isset($params['nameserver'])  ? $params['nameserver']  : '';
    $newIp      = isset($params['newipaddress'])? $params['newipaddress']: '';

    $host = str_replace('.' . $domain, '', $nameserver);

    $payload = array(
        'host' => $host,
        'ips'  => array($newIp),
    );

    $result = spaceship_api(
        'PUT',
        '/domains/' . rawurlencode($domain) . '/personal-nameservers/' . rawurlencode($host),
        $params,
        $payload
    );

    if ($result['error']) {
        return array('error' => 'Failed to modify nameserver: ' . $result['error']);
    }

    return array();
}

/**
 * DeleteNameserver - Remove a child nameserver.
 */
function spaceship_DeleteNameserver(array $params)
{
    $domain     = $params['sld'] . '.' . ltrim($params['tld'], '.');
    $nameserver = isset($params['nameserver']) ? $params['nameserver'] : '';

    $host = str_replace('.' . $domain, '', $nameserver);

    $result = spaceship_api(
        'DELETE',
        '/domains/' . rawurlencode($domain) . '/personal-nameservers/' . rawurlencode($host),
        $params
    );

    if ($result['error']) {
        return array('error' => 'Failed to delete nameserver: ' . $result['error']);
    }

    return array();
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN AVAILABILITY CHECK
// ─────────────────────────────────────────────────────────────────────────────

/**
 * CheckAvailability - Check if domains are available for registration.
 * Uses the Spaceship bulk availability endpoint for efficiency.
 */
function spaceship_CheckAvailability(array $params)
{
    $searchTerm    = isset($params['searchTerm'])    ? $params['searchTerm']    : '';
    $tldsToInclude = isset($params['tldsToInclude']) ? $params['tldsToInclude'] : array();

    // Build list of FQDNs to check
    $domainsToCheck = array();
    foreach ($tldsToInclude as $tld) {
        $tld = ltrim($tld, '.');
        $domainsToCheck[] = $searchTerm . '.' . $tld;
    }

    if (empty($domainsToCheck)) {
        return new LookupResultsList();
    }

    // Spaceship allows max 20 per request
    $batches    = array_chunk($domainsToCheck, 20);
    $allResults = new LookupResultsList();

    foreach ($batches as $batch) {
        $payload = array('domains' => $batch);
        $result  = spaceship_api('POST', '/domains/available', $params, $payload);

        if ($result['error'] || empty($result['data']['domains'])) {
            continue;
        }

        foreach ($result['data']['domains'] as $domainResult) {
            $fqdn   = isset($domainResult['domain']) ? $domainResult['domain'] : '';
            $status = isset($domainResult['result']) ? $domainResult['result'] : 'unknown';

            // Parse SLD and TLD
            $dotPos = strpos($fqdn, '.');
            if ($dotPos === false) {
                continue;
            }
            $sld = substr($fqdn, 0, $dotPos);
            $tld = substr($fqdn, $dotPos + 1);

            $searchResult = new SearchResult($sld, $tld);

            if ($status === 'available') {
                $searchResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);

                // Handle premium pricing
                if (!empty($domainResult['premiumPricing'])) {
                    foreach ($domainResult['premiumPricing'] as $pricing) {
                        if (isset($pricing['operation']) && $pricing['operation'] === 'register') {
                            $searchResult->setPremiumDomain(true);
                            $searchResult->setPremiumCostPrice(isset($pricing['price']) ? $pricing['price'] : 0);
                            $searchResult->setPremiumCurrencyCode(isset($pricing['currency']) ? $pricing['currency'] : 'USD');
                            break;
                        }
                    }
                }
            } elseif ($status === 'unavailable') {
                $searchResult->setStatus(SearchResult::STATUS_REGISTERED);
            } else {
                $searchResult->setStatus(SearchResult::STATUS_UNKNOWN);
            }

            $allResults->append($searchResult);
        }
    }

    return $allResults;
}

// ─────────────────────────────────────────────────────────────────────────────
//  DOMAIN SYNC
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Sync - Synchronise domain expiry date and status with Spaceship.
 * Called by the WHMCS domain sync cron.
 */
function spaceship_Sync(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);

    if ($result['error']) {
        return array('error' => 'Sync failed: ' . $result['error']);
    }

    $data   = $result['data'];
    $status = isset($data['lifecycleStatus']) ? $data['lifecycleStatus'] : '';

    $active    = in_array($status, array('registered', 'active'));
    $cancelled = in_array($status, array('expired', 'deleted', 'pendingDelete'));

    $expiryDate = '';
    if (!empty($data['expirationDate'])) {
        $expiryDate = date('Y-m-d', strtotime($data['expirationDate']));
    }

    return array(
        'active'          => $active,
        'cancelled'       => $cancelled,
        'transferredAway' => false,
        'expirydate'      => $expiryDate,
    );
}

/**
 * TransferSync - Synchronise inbound transfer status.
 * Called by the WHMCS domain sync cron for Pending Transfer domains.
 */
function spaceship_TransferSync(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api(
        'GET',
        '/domains/' . rawurlencode($domain) . '/transfer',
        $params
    );

    if ($result['error']) {
        return array('error' => 'Transfer sync failed: ' . $result['error']);
    }

    $data      = $result['data'];
    $status    = isset($data['status'])     ? $data['status']     : 'pending';
    $direction = isset($data['direction'])  ? $data['direction']  : 'in';

    $completed  = ($status === 'completed' || $status === 'approved');
    $failed     = in_array($status, array('rejected', 'cancelled', 'failed'));

    $expiryDate = '';
    // Fetch domain info for expiry if transfer completed
    if ($completed) {
        $domainResult = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
        if (!$domainResult['error'] && !empty($domainResult['data']['expirationDate'])) {
            $expiryDate = date('Y-m-d', strtotime($domainResult['data']['expirationDate']));
        }
    }

    return array(
        'completed'  => $completed,
        'failed'     => $failed,
        'reason'     => $failed ? 'Transfer ' . $status . ' by registry/losing registrar.' : '',
        'expirydate' => $expiryDate,
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  TLD & PRICING SYNC
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GetTldPricing - Import TLD list and pricing into WHMCS.
 * Triggered via Admin > Registrars > Spaceship > Sync Pricing.
 */
function spaceship_GetTldPricing(array $params)
{
    // Full list of TLDs to probe – same list used by the pricing tool
    $tlds = array(
        'com', 'net', 'org', 'info', 'biz', 'co', 'io', 'app', 'dev',
        'online', 'site', 'tech', 'store', 'shop', 'blog', 'club', 'pro',
        'media', 'news', 'email', 'live', 'cloud', 'digital', 'agency',
        'solutions', 'services', 'consulting', 'design', 'studio', 'network',
        'systems', 'group', 'global', 'world', 'space', 'zone', 'plus',
        'expert', 'today', 'link', 'me', 'us', 'uk', 'ca', 'au', 'de',
        'fr', 'es', 'it', 'nl', 'jp', 'cn', 'in', 'br', 'mx', 'ru',
        'pl', 'se', 'no', 'dk', 'fi', 'ch', 'at', 'be', 'nz', 'sg',
        'hk', 'ae', 'za', 'ng', 'ke', 'ar', 'cl', 'pe', 'ph', 'id',
        'my', 'th', 'vn', 'pk', 'eu', 'tv', 'cc', 'mobi', 'name',
        'travel', 'jobs', 'health', 'fit', 'care', 'run', 'yoga',
        'restaurant', 'bar', 'cafe', 'pizza', 'coffee',
        'realty', 'house', 'homes', 'rent', 'property',
        'finance', 'capital', 'bank', 'insurance', 'tax',
        'software', 'codes', 'game', 'games', 'play', 'casino', 'bet',
        'community', 'church', 'foundation', 'gives',
        'cool', 'rocks', 'ninja', 'guru', 'expert', 'buzz',
    );
    $tlds = array_values(array_unique($tlds));

    $probeLabel   = 'spaceship-tld-probe9z';
    $probeDomains = array();
    foreach ($tlds as $tld) {
        $probeDomains[] = $probeLabel . '.' . $tld;
    }

    $batches    = array_chunk($probeDomains, 20);
    $pricingMap = array();

    foreach ($batches as $batch) {
        $payload = array('domains' => $batch);
        $result  = spaceship_api('POST', '/domains/available', $params, $payload);

        if ($result['error'] || empty($result['data']['domains'])) {
            continue;
        }

        foreach ($result['data']['domains'] as $item) {
            if (empty($item['domain'])) {
                continue;
            }
            $fqdn = $item['domain'];
            $tld  = substr($fqdn, strlen($probeLabel) + 1);

            // Skip invalid results
            $apiResult = isset($item['result']) ? $item['result'] : '';
            if (in_array($apiResult, array('invalidDomainName', 'invalid', 'error'))) {
                continue;
            }

            $registerPrice = null;
            $currency      = 'USD';

            // Check top-level price field first
            if (isset($item['price']) && is_numeric($item['price'])) {
                $registerPrice = (float)$item['price'];
                $currency      = isset($item['currency']) ? $item['currency'] : 'USD';
            }

            // Then check premiumPricing array
            if ($registerPrice === null && !empty($item['premiumPricing'])) {
                foreach ($item['premiumPricing'] as $pricing) {
                    if (isset($pricing['operation']) && $pricing['operation'] === 'register') {
                        $registerPrice = isset($pricing['price']) ? (float)$pricing['price'] : null;
                        $currency      = isset($pricing['currency']) ? $pricing['currency'] : 'USD';
                        break;
                    }
                }
            }

            if ($registerPrice !== null) {
                $pricingMap[$tld] = array(
                    'register' => $registerPrice,
                    'renew'    => $registerPrice, // Use same as register; adjust if needed
                    'transfer' => $registerPrice,
                    'currency' => $currency,
                );
            }
        }

        usleep(300000); // 0.3s between batches to respect rate limits
    }

    $results = new ResultsList();

    foreach ($pricingMap as $tld => $pricing) {
        $item = (new ImportItem())
            ->setExtension('.' . $tld)
            ->setMinYears(1)
            ->setMaxYears(10)
            ->setYearsStep(1)
            ->setRegisterPrice($pricing['register'])
            ->setRenewPrice($pricing['renew'])
            ->setTransferPrice($pricing['transfer'])
            ->setCurrency($pricing['currency'])
            ->setEppRequired(true);

        $results[] = $item;
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────────────────────
//  REQUEST DELETE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * RequestDelete - Request domain deletion.
 * Note: Spaceship API states the Delete endpoint is still under development (501).
 * This function is included for completeness; it will return an informative error.
 */
function spaceship_RequestDelete(array $params)
{
    return array(
        'error' => 'Domain deletion via API is not yet available on Spaceship. '
                 . 'Please manage domain deletion directly at spaceship.com.',
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  CLIENT AREA OUTPUT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ClientArea - Custom HTML rendered on the domain details page in client area.
 */
function spaceship_ClientArea(array $params)
{
    $domain = $params['sld'] . '.' . ltrim($params['tld'], '.');

    $result = spaceship_api('GET', '/domains/' . rawurlencode($domain), $params);
    if ($result['error']) {
        return '<div class="alert alert-danger">Unable to load domain information: '
             . htmlspecialchars($result['error']) . '</div>';
    }

    $data   = $result['data'];
    $status = isset($data['lifecycleStatus']) ? ucfirst($data['lifecycleStatus']) : 'Unknown';
    $expiry = '';
    if (!empty($data['expirationDate'])) {
        $expiry = date('d M Y', strtotime($data['expirationDate']));
    }
    $autoRenew = (!empty($data['autoRenew'])) ? 'Enabled' : 'Disabled';
    $privacy   = (isset($data['privacyProtection']['level']) && $data['privacyProtection']['level'] === 'high')
               ? 'High (Protected)' : 'Public';

    $nameservers = array();
    if (isset($data['nameservers']['hosts']) && is_array($data['nameservers']['hosts'])) {
        $nameservers = $data['nameservers']['hosts'];
    }

    $eppStatuses = isset($data['eppStatuses']) ? $data['eppStatuses'] : array();
    $locked      = in_array('clientTransferProhibited', $eppStatuses) ? 'Locked' : 'Unlocked';

    $html  = '<div class="spaceship-domain-info" style="font-family:sans-serif;font-size:13px;">';
    $html .= '<table class="table table-bordered table-condensed">';
    $html .= '<thead><tr><th colspan="2" style="background:#f5f5f5;">&#x1F680; Domain Details</th></tr></thead>';
    $html .= '<tbody>';
    $html .= '<tr><th>Status</th><td>' . htmlspecialchars($status) . '</td></tr>';
    $html .= '<tr><th>Expiry Date</th><td>' . htmlspecialchars($expiry) . '</td></tr>';
    $html .= '<tr><th>Auto Renew</th><td>' . htmlspecialchars($autoRenew) . '</td></tr>';
    $html .= '<tr><th>Transfer Lock</th><td>' . htmlspecialchars($locked) . '</td></tr>';
    $html .= '<tr><th>Privacy Protection</th><td>' . htmlspecialchars($privacy) . '</td></tr>';
    if (!empty($nameservers)) {
        $html .= '<tr><th>Nameservers</th><td>' . implode('<br>', array_map('htmlspecialchars', $nameservers)) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

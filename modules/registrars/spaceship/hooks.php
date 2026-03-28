<?php
/**
 * Spaceship Registrar Module – Hooks
 *
 * Additional WHMCS hooks that extend Spaceship module functionality:
 *  - Auto-renew toggle sync after WHMCS order is completed
 *  - Email protection preference sync
 *  - Admin notification on async operation issues
 *
 * Install path: /modules/registrars/spaceship/hooks.php
 *
 * WHMCS loads this file automatically when the Spaceship registrar
 * module is active.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

/**
 * After a domain is registered or renewed, push the auto-renew preference
 * to Spaceship matching whatever the client chose in WHMCS.
 */
add_hook('AfterRegistrarRegistration', 1, function ($vars) {
    spaceship_syncAutoRenew($vars);
});

add_hook('AfterRegistrarRenewal', 1, function ($vars) {
    spaceship_syncAutoRenew($vars);
});

add_hook('AfterRegistrarTransfer', 1, function ($vars) {
    spaceship_syncAutoRenew($vars);
});

/**
 * Push the WHMCS auto-renew setting to Spaceship after domain actions.
 *
 * @param array $vars  Hook variables from WHMCS
 */
function spaceship_syncAutoRenew(array $vars)
{
    $domainId  = isset($vars['domainid']) ? (int)$vars['domainid'] : 0;
    $registrar = isset($vars['registrar']) ? $vars['registrar'] : '';

    if ($registrar !== 'spaceship' || $domainId === 0) {
        return;
    }

    try {
        $domain = Capsule::table('tbldomains')->where('id', $domainId)->first();
        if (!$domain) {
            return;
        }

        $registrarConfig = getRegistrarConfigOptions('spaceship');
        $params = array(
            'api_key'    => isset($registrarConfig['api_key'])    ? $registrarConfig['api_key']    : '',
            'api_secret' => isset($registrarConfig['api_secret']) ? $registrarConfig['api_secret'] : '',
        );

        $fqdn      = $domain->domain;
        $autoRenew = ((int)$domain->donotrenew === 0); // donotrenew=0 means auto-renew ON

        spaceship_api(
            'PUT',
            '/domains/' . rawurlencode($fqdn) . '/autorenew',
            $params,
            array('isEnabled' => $autoRenew)
        );

    } catch (Exception $e) {
        logModuleCall('spaceship', 'syncAutoRenew_hook', $domainId, $e->getMessage());
    }
}

/**
 * When a client toggles ID Protection for a Spaceship domain,
 * also sync the email protection (contact form) preference.
 */
add_hook('DomainIDProtectToggle', 1, function ($vars) {
    $domainId  = isset($vars['domainid']) ? (int)$vars['domainid'] : 0;
    $registrar = isset($vars['registrar']) ? $vars['registrar'] : '';
    $enabled   = isset($vars['idprotection']) ? (bool)$vars['idprotection'] : false;

    if ($registrar !== 'spaceship' || $domainId === 0) {
        return;
    }

    try {
        $domain = Capsule::table('tbldomains')->where('id', $domainId)->first();
        if (!$domain) {
            return;
        }

        $registrarConfig = getRegistrarConfigOptions('spaceship');
        $params = array(
            'api_key'    => isset($registrarConfig['api_key'])    ? $registrarConfig['api_key']    : '',
            'api_secret' => isset($registrarConfig['api_secret']) ? $registrarConfig['api_secret'] : '',
        );

        // Sync email protection preference (contact form visibility)
        spaceship_api(
            'PUT',
            '/domains/' . rawurlencode($domain->domain) . '/privacy/email-protection-preference',
            $params,
            array('contactForm' => $enabled)
        );

    } catch (Exception $e) {
        logModuleCall('spaceship', 'emailProtection_hook', $domainId, $e->getMessage());
    }
});

<?php
define('CLI_SCRIPT', true);

// Load Moodle config
require_once(__DIR__ . '/config.php');

// Load Xero SDK
require_once($CFG->dirroot . '/local/iomad_xero/vendor/autoload.php');

// Get stored OAuth credentials
$token  = get_config('local_iomad_xero', 'XERO_TOKEN');
$tenant = get_config('local_iomad_xero', 'XERO_TENANT_ID');

$config = XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()
    ->setAccessToken($token);

$apiInstance = new XeroAPI\XeroPHP\Api\AccountingApi(
    new GuzzleHttp\Client(),
    $config
);

// Fetch org details
$result = $apiInstance->getOrganisations($tenant);

echo "Base Currency: " . $result->getOrganisations()[0]->getBaseCurrency() . PHP_EOL;

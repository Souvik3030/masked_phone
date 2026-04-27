<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php'); // <-- Ensure this is linked

$leadPhoneFieldId = 'UF_CRM_1768897823888';
$dealPhoneFieldId = 'UF_CRM_1768897823888';

$leadPhoneMaskFieldId = 'UF_CRM_1777275227928';
$dealPhoneMaskFieldId = 'UF_CRM_1777278234424';

date_default_timezone_set('Asia/Dubai');

$data = $_POST;

logEvent("REQUEST DEBUG", [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'event' => $data['event'] ?? '',
    'entity_id' => $data['data']['FIELDS']['ID'] ?? '',
    'post_keys' => array_keys($data)
]);

function getFirstPhone($fields)
{
    return isset($fields['PHONE']) && is_array($fields['PHONE']) ? $fields['PHONE'][0]['VALUE'] : '';
}

function getContactPhone($contactId)
{
    if (empty($contactId) || $contactId <= 0) {
        return '';
    }

    $contactDataFetch = CRest::call('crm.contact.get', ['id' => $contactId]);
    $contactFields = $contactDataFetch['result'] ?? [];

    return getFirstPhone($contactFields);
}

function getDealContactPhone($dealId)
{
    $dealContactsFetch = CRest::call('crm.deal.contact.items.get', ['id' => $dealId]);
    $dealContacts = $dealContactsFetch['result'] ?? [];

    logEvent("FETCHED DEAL CONTACTS", [
        'Deal_ID' => $dealId,
        'Contacts' => $dealContacts
    ]);

    foreach ($dealContacts as $dealContact) {
        $contactId = $dealContact['CONTACT_ID'] ?? null;
        $phone = getContactPhone($contactId);

        if (!empty($phone)) {
            return $phone;
        }
    }

    return '';
}

function maskPhone($rawPhone)
{
    if (empty($rawPhone)) {
        return '';
    }

    $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
    $len = strlen($cleanPhone);

    if ($len > 4) {
        $visiblePart = substr($cleanPhone, 0, -4);
        return $visiblePart . "xxxx";
    }

    return str_repeat("x", $len);
}

function processPhoneMask($entityType, $entityId, $phoneFieldId, $phoneMaskFieldId, $eventName)
{
    $entityTitle = strtoupper($entityType);
    $getMethod = "crm.$entityType.get";
    $updateMethod = "crm.$entityType.update";

    logEvent("EVENT RECEIVED", "$eventName detected for $entityTitle ID " . ($entityId ?? 'Unknown'));

    if (!$entityId) {
        return;
    }

    $entityDataFetch = CRest::call($getMethod, ['id' => $entityId]);
    $entityFields = $entityDataFetch['result'] ?? [];

    logEvent("FETCHED $entityTitle DATA", "$entityTitle ID: $entityId successfully fetched.");

    $rawPhone = $entityFields[$phoneFieldId] ?? '';

    if (empty($rawPhone) && $entityType === 'lead') {
        $rawPhone = getFirstPhone($entityFields);
    }

    $contactId = $entityFields['CONTACT_ID'] ?? null;

    if (empty($rawPhone) && !empty($contactId) && $contactId > 0) {
        logEvent("FETCHING CONTACT DATA", "$entityTitle is missing phone. Fetching Contact ID: $contactId");

        $rawPhone = getContactPhone($contactId);
    }

    if (empty($rawPhone) && $entityType === 'deal') {
        logEvent("FETCHING DEAL CONTACTS", "$entityTitle is missing phone. Fetching linked contacts for Deal ID: $entityId");

        $rawPhone = getDealContactPhone($entityId);
    }

    $companyId = $entityFields['COMPANY_ID'] ?? null;

    if (empty($rawPhone) && $entityType === 'deal' && !empty($companyId) && $companyId > 0) {
        logEvent("FETCHING COMPANY DATA", "$entityTitle is missing phone. Fetching Company ID: $companyId");

        $companyDataFetch = CRest::call('crm.company.get', ['id' => $companyId]);
        $companyFields = $companyDataFetch['result'] ?? [];
        $rawPhone = getFirstPhone($companyFields);
    }

    $maskedPhone = maskPhone($rawPhone);

    logEvent("MASKING CALCULATION", [
        'Entity_Type' => $entityTitle,
        'Entity_ID' => $entityId,
        'Original_Phone' => $rawPhone,
        'Target_Phone' => $maskedPhone
    ]);

    if (empty($maskedPhone)) {
        logEvent("SKIP UPDATE", "No phone found on the $entityTitle or linked record to mask.");
        return;
    }

    if (($entityFields[$phoneMaskFieldId] ?? '') === $maskedPhone) {
        logEvent("SKIP UPDATE", "$entityTitle phone mask field already has target value.");
        return;
    }

    $fieldsToUpdate = [
        $phoneMaskFieldId => $maskedPhone
    ];

    logEvent("FIELDS TO UPDATE", $fieldsToUpdate);

    $updateResult = CRest::call($updateMethod, [
        'id' => $entityId,
        'fields' => $fieldsToUpdate
    ]);

    logEvent("UPDATE API RESPONSE", $updateResult);
}

$eventName = strtoupper(trim($data['event'] ?? ''));
$entityId = $data['data']['FIELDS']['ID'] ?? null;

if ($eventName === 'ONCRMLEADADD' || $eventName === 'ONCRMLEADUPDATE') {
    processPhoneMask('lead', $entityId, $leadPhoneFieldId, $leadPhoneMaskFieldId, $eventName);
} elseif ($eventName === 'ONCRMDEALADD' || $eventName === 'ONCRMDEALUPDATE') {
    processPhoneMask('deal', $entityId, $dealPhoneFieldId, $dealPhoneMaskFieldId, $eventName);
} else {
    // Optional: Log pings that aren't the correct event
    if (!empty($data)) {
        logEvent("IGNORED EVENT", $data['event'] ?? 'No event name');
    }
}

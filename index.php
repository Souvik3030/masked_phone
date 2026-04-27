<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php'); // <-- Ensure this is linked

$leadPhoneFieldId = 'UF_CRM_1768897823888';
$dealPhoneFieldId = 'UF_CRM_1777279628958';

$leadPhoneMaskFieldId = 'UF_CRM_1777275227928';
$dealPhoneMaskFieldId = 'UF_CRM_1777278234424';

date_default_timezone_set('Asia/Dubai');

$data = $_REQUEST;
$codeVersion = 'masked-phone-router-2026-04-27-01';

logEvent("CODE VERSION", [
    'version' => $codeVersion,
    'file' => __FILE__
]);

logEvent("STEP 01 REQUEST RECEIVED", [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'event' => $data['event'] ?? '',
    'entity_id' => $data['data']['FIELDS']['ID'] ?? '',
    'request_keys' => array_keys($data)
]);

function getFirstPhone($fields)
{
    return isset($fields['PHONE']) && is_array($fields['PHONE']) ? $fields['PHONE'][0]['VALUE'] : '';
}

function getContactPhone($contactId)
{
    logEvent("CONTACT STEP 01 FETCH START", [
        'contact_id' => $contactId
    ]);

    if (empty($contactId) || $contactId <= 0) {
        logEvent("CONTACT STEP 02 FETCH SKIPPED", "Contact ID is empty.");
        return '';
    }

    $contactDataFetch = CRest::call('crm.contact.get', ['id' => $contactId]);
    $contactFields = $contactDataFetch['result'] ?? [];
    $phone = getFirstPhone($contactFields);

    logEvent("CONTACT STEP 02 FETCH RESULT", [
        'contact_id' => $contactId,
        'api_response' => $contactDataFetch,
        'phone' => $phone
    ]);

    return $phone;
}

function getDealContactPhone($dealId)
{
    logEvent("DEAL STEP 05 CONTACT LIST FETCH START", [
        'deal_id' => $dealId
    ]);

    $dealContactsFetch = CRest::call('crm.deal.contact.items.get', ['id' => $dealId]);
    $dealContacts = $dealContactsFetch['result'] ?? [];

    logEvent("DEAL STEP 06 CONTACT LIST FETCH RESULT", [
        'deal_id' => $dealId,
        'api_response' => $dealContactsFetch,
        'contacts' => $dealContacts
    ]);

    foreach ($dealContacts as $dealContact) {
        $contactId = $dealContact['CONTACT_ID'] ?? null;
        logEvent("DEAL STEP 07 CONTACT PHONE CHECK", [
            'deal_id' => $dealId,
            'contact_id' => $contactId
        ]);

        $phone = getContactPhone($contactId);

        if (!empty($phone)) {
            logEvent("DEAL STEP 08 CONTACT PHONE FOUND", [
                'deal_id' => $dealId,
                'contact_id' => $contactId,
                'phone' => $phone
            ]);
            return $phone;
        }
    }

    logEvent("DEAL STEP 08 CONTACT PHONE NOT FOUND", [
        'deal_id' => $dealId
    ]);

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
    $logPrefix = "$entityTitle STEP";

    logEvent("$logPrefix 01 EVENT RECEIVED", [
        'event' => $eventName,
        'entity_type' => $entityTitle,
        'entity_id' => $entityId
    ]);

    if (!$entityId) {
        logEvent("$logPrefix 02 STOPPED", "Entity ID is empty.");
        return;
    }

    logEvent("$logPrefix 02 ENTITY FETCH START", [
        'method' => $getMethod,
        'entity_id' => $entityId
    ]);

    $entityDataFetch = CRest::call($getMethod, ['id' => $entityId]);
    $entityFields = $entityDataFetch['result'] ?? [];

    logEvent("$logPrefix 03 ENTITY FETCH RESULT", [
        'method' => $getMethod,
        'entity_id' => $entityId,
        'api_response' => $entityDataFetch
    ]);

    $rawPhone = $entityFields[$phoneFieldId] ?? '';
    $phoneSource = "$entityTitle custom field $phoneFieldId";

    logEvent("$logPrefix 04 PHONE FIELD CHECK", [
        'phone_field_id' => $phoneFieldId,
        'phone_value' => $rawPhone
    ]);

    if ($entityType === 'deal') {
        logEvent("$logPrefix 05 CUSTOM NUMBER FIELD RESULT", [
            'number_field_id' => $phoneFieldId,
            'number_value' => $rawPhone
        ]);

        $maskedPhone = maskPhone($rawPhone);

        logEvent("$logPrefix 06 MASKING CALCULATION", [
            'entity_type' => $entityTitle,
            'entity_id' => $entityId,
            'phone_source' => $phoneSource,
            'original_phone' => $rawPhone,
            'target_phone' => $maskedPhone
        ]);

        if (empty($maskedPhone)) {
            logEvent("$logPrefix 07 UPDATE SKIPPED", "Deal custom number field is empty.");
            return;
        }

        if (($entityFields[$phoneMaskFieldId] ?? '') === $maskedPhone) {
            logEvent("$logPrefix 07 UPDATE SKIPPED", "$entityTitle phone mask field already has target value.");
            return;
        }

        $fieldsToUpdate = [
            $phoneMaskFieldId => $maskedPhone
        ];

        logEvent("$logPrefix 07 UPDATE PAYLOAD", [
            'method' => $updateMethod,
            'entity_id' => $entityId,
            'fields' => $fieldsToUpdate
        ]);

        $updateResult = CRest::call($updateMethod, [
            'id' => $entityId,
            'fields' => $fieldsToUpdate
        ]);

        logEvent("$logPrefix 08 UPDATE API RESPONSE", $updateResult);
        return;
    }

    if (empty($rawPhone) && $entityType === 'lead') {
        $rawPhone = getFirstPhone($entityFields);
        $phoneSource = "Lead standard PHONE field";
        logEvent("$logPrefix 05 STANDARD PHONE CHECK", [
            'phone_value' => $rawPhone
        ]);
    }

    $contactId = $entityFields['CONTACT_ID'] ?? null;

    if (empty($rawPhone) && !empty($contactId) && $contactId > 0) {
        logEvent("$logPrefix 05 CONTACT PHONE FETCH START", [
            'contact_id' => $contactId
        ]);

        $rawPhone = getContactPhone($contactId);
        $phoneSource = "Linked contact $contactId";
    }

    if (empty($rawPhone) && $entityType === 'deal') {
        logEvent("$logPrefix 05 DEAL CONTACT PHONE FETCH START", [
            'deal_id' => $entityId
        ]);

        $rawPhone = getDealContactPhone($entityId);
        $phoneSource = "Deal linked contact list";
    }

    $companyId = $entityFields['COMPANY_ID'] ?? null;

    if (empty($rawPhone) && $entityType === 'deal' && !empty($companyId) && $companyId > 0) {
        logEvent("$logPrefix 09 COMPANY PHONE FETCH START", [
            'company_id' => $companyId
        ]);

        $companyDataFetch = CRest::call('crm.company.get', ['id' => $companyId]);
        $companyFields = $companyDataFetch['result'] ?? [];
        $rawPhone = getFirstPhone($companyFields);
        $phoneSource = "Linked company $companyId";

        logEvent("$logPrefix 10 COMPANY PHONE FETCH RESULT", [
            'company_id' => $companyId,
            'api_response' => $companyDataFetch,
            'phone' => $rawPhone
        ]);
    }

    $maskedPhone = maskPhone($rawPhone);

    logEvent("$logPrefix 11 MASKING CALCULATION", [
        'entity_type' => $entityTitle,
        'entity_id' => $entityId,
        'phone_source' => $phoneSource,
        'original_phone' => $rawPhone,
        'target_phone' => $maskedPhone
    ]);

    if (empty($maskedPhone)) {
        logEvent("$logPrefix 12 UPDATE SKIPPED", "No phone found on the $entityTitle or linked record to mask.");
        return;
    }

    if (($entityFields[$phoneMaskFieldId] ?? '') === $maskedPhone) {
        logEvent("$logPrefix 12 UPDATE SKIPPED", "$entityTitle phone mask field already has target value.");
        return;
    }

    $fieldsToUpdate = [
        $phoneMaskFieldId => $maskedPhone
    ];

    logEvent("$logPrefix 12 UPDATE PAYLOAD", [
        'method' => $updateMethod,
        'entity_id' => $entityId,
        'fields' => $fieldsToUpdate
    ]);

    $updateResult = CRest::call($updateMethod, [
        'id' => $entityId,
        'fields' => $fieldsToUpdate
    ]);

    logEvent("$logPrefix 13 UPDATE API RESPONSE", $updateResult);
}

$eventName = preg_replace('/[^A-Z]/', '', strtoupper(trim($data['event'] ?? '')));
$entityId = $data['data']['FIELDS']['ID'] ?? null;

logEvent("STEP 02 ROUTER DEBUG", [
    'normalized_event' => $eventName,
    'entity_id' => $entityId
]);

if (strpos($eventName, 'CRMLEAD') !== false) {
    logEvent("STEP 03 ROUTED TO LEAD", [
        'event' => $eventName,
        'entity_id' => $entityId
    ]);
    processPhoneMask('lead', $entityId, $leadPhoneFieldId, $leadPhoneMaskFieldId, $eventName);
} elseif (strpos($eventName, 'CRMDEAL') !== false) {
    logEvent("STEP 03 ROUTED TO DEAL", [
        'event' => $eventName,
        'entity_id' => $entityId
    ]);
    processPhoneMask('deal', $entityId, $dealPhoneFieldId, $dealPhoneMaskFieldId, $eventName);
} elseif (strpos($eventName, 'CRM') !== false) {
    logEvent("CRM EVENT NOT ROUTED", [
        'raw_event' => $data['event'] ?? '',
        'normalized_event' => $eventName,
        'entity_id' => $entityId,
        'version' => $codeVersion
    ]);
} else {
    // Optional: Log pings that aren't the correct event
    if (!empty($data)) {
        logEvent("IGNORED EVENT", $data['event'] ?? 'No event name');
    }
}

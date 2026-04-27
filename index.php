<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php'); // <-- Ensure this is linked

$leadPhoneFieldId = 'UF_CRM_1768897823888';
$dealPhoneFieldId = 'UF_CRM_1777279628958';

$leadPhoneMaskFieldId = 'UF_CRM_1777275227928';
$dealPhoneMaskFieldId = 'UF_CRM_1777278234424';
$spaEntityTypeId = getenv('SPA_ENTITY_TYPE_ID') ?: 1062;
$spaPhoneMaskFieldId = 'ufCrm10PhoneMasked';

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

function getSpaContactIds($itemFields)
{
    $contactIds = [];

    if (!empty($itemFields['contactId'])) {
        $contactIds[] = $itemFields['contactId'];
    }

    if (!empty($itemFields['contactIds']) && is_array($itemFields['contactIds'])) {
        foreach ($itemFields['contactIds'] as $contactId) {
            if (!empty($contactId)) {
                $contactIds[] = $contactId;
            }
        }
    }

    if (!empty($itemFields['contacts']) && is_array($itemFields['contacts'])) {
        foreach ($itemFields['contacts'] as $contact) {
            if (is_array($contact)) {
                $contactId = $contact['id'] ?? $contact['ID'] ?? $contact['contactId'] ?? null;
            } else {
                $contactId = $contact;
            }

            if (!empty($contactId)) {
                $contactIds[] = $contactId;
            }
        }
    }

    return array_values(array_unique($contactIds));
}

function getSpaContactPhone($itemFields, $spaItemId)
{
    $contactIds = getSpaContactIds($itemFields);

    logEvent("SPA STEP 05 CONTACT IDS RESULT", [
        'spa_item_id' => $spaItemId,
        'contact_ids' => $contactIds
    ]);

    foreach ($contactIds as $contactId) {
        logEvent("SPA STEP 06 CONTACT PHONE CHECK", [
            'spa_item_id' => $spaItemId,
            'contact_id' => $contactId
        ]);

        $phone = getContactPhone($contactId);

        if (!empty($phone)) {
            logEvent("SPA STEP 07 CONTACT PHONE FOUND", [
                'spa_item_id' => $spaItemId,
                'contact_id' => $contactId,
                'phone' => $phone
            ]);
            return $phone;
        }
    }

    logEvent("SPA STEP 07 CONTACT PHONE NOT FOUND", [
        'spa_item_id' => $spaItemId
    ]);

    return '';
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

        if (empty($rawPhone)) {
            logEvent("$logPrefix 06 CUSTOM NUMBER EMPTY", "Falling back to linked contact/company phone.");
        }
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

function updateSpaItemMask($spaEntityTypeId, $spaItemId, $spaPhoneMaskFieldId, $maskedPhone)
{
    logEvent("CONTACT STEP LINKED SPA FETCH START", [
        'method' => 'crm.item.get',
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId
    ]);

    $spaItemFetch = CRest::call('crm.item.get', [
        'entityTypeId' => $spaEntityTypeId,
        'id' => $spaItemId
    ]);
    $spaItem = $spaItemFetch['result']['item'] ?? [];

    logEvent("CONTACT STEP LINKED SPA FETCH RESULT", [
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId,
        'api_response' => $spaItemFetch
    ]);

    if (($spaItem[$spaPhoneMaskFieldId] ?? '') === $maskedPhone) {
        logEvent("CONTACT STEP LINKED SPA UPDATE SKIPPED", [
            'item_id' => $spaItemId,
            'reason' => 'SPA phone mask field already has target value.',
            'target_phone' => $maskedPhone
        ]);
        return;
    }

    $fieldsToUpdate = [
        $spaPhoneMaskFieldId => $maskedPhone
    ];

    logEvent("CONTACT STEP LINKED SPA UPDATE PAYLOAD", [
        'method' => 'crm.item.update',
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId,
        'fields' => $fieldsToUpdate
    ]);

    $updateResult = CRest::call('crm.item.update', [
        'entityTypeId' => $spaEntityTypeId,
        'id' => $spaItemId,
        'fields' => $fieldsToUpdate
    ]);

    logEvent("CONTACT STEP LINKED SPA UPDATE RESPONSE", [
        'item_id' => $spaItemId,
        'api_response' => $updateResult
    ]);
}

function processSpaPhoneMask($spaEntityTypeId, $spaItemId, $spaPhoneMaskFieldId, $eventName)
{
    logEvent("SPA STEP 01 EVENT RECEIVED", [
        'event' => $eventName,
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId
    ]);

    if (!$spaItemId) {
        logEvent("SPA STEP 02 STOPPED", "SPA item ID is empty.");
        return;
    }

    logEvent("SPA STEP 02 ITEM FETCH START", [
        'method' => 'crm.item.get',
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId
    ]);

    $spaItemFetch = CRest::call('crm.item.get', [
        'entityTypeId' => $spaEntityTypeId,
        'id' => $spaItemId
    ]);
    $spaItem = $spaItemFetch['result']['item'] ?? [];

    logEvent("SPA STEP 03 ITEM FETCH RESULT", [
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId,
        'api_response' => $spaItemFetch
    ]);

    $rawPhone = getSpaContactPhone($spaItem, $spaItemId);
    $maskedPhone = maskPhone($rawPhone);

    logEvent("SPA STEP 08 MASKING CALCULATION", [
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId,
        'original_phone' => $rawPhone,
        'target_phone' => $maskedPhone
    ]);

    if (empty($maskedPhone)) {
        logEvent("SPA STEP 09 UPDATE SKIPPED", "No contact phone found for SPA item.");
        return;
    }

    if (($spaItem[$spaPhoneMaskFieldId] ?? '') === $maskedPhone) {
        logEvent("SPA STEP 09 UPDATE SKIPPED", "SPA phone mask field already has target value.");
        return;
    }

    $fieldsToUpdate = [
        $spaPhoneMaskFieldId => $maskedPhone
    ];

    logEvent("SPA STEP 09 UPDATE PAYLOAD", [
        'method' => 'crm.item.update',
        'entityTypeId' => $spaEntityTypeId,
        'item_id' => $spaItemId,
        'fields' => $fieldsToUpdate
    ]);

    $updateResult = CRest::call('crm.item.update', [
        'entityTypeId' => $spaEntityTypeId,
        'id' => $spaItemId,
        'fields' => $fieldsToUpdate
    ]);

    logEvent("SPA STEP 10 UPDATE API RESPONSE", $updateResult);
}

function validateSpaPhoneMasks($spaEntityTypeId, $spaPhoneMaskFieldId, $limit, $start)
{
    $limit = max(1, min((int)$limit, 50));
    $start = max(0, (int)$start);

    logEvent("SPA VALIDATION STEP 01 START", [
        'entityTypeId' => $spaEntityTypeId,
        'mask_field' => $spaPhoneMaskFieldId,
        'limit' => $limit,
        'start' => $start
    ]);

    $spaListFetch = CRest::call('crm.item.list', [
        'entityTypeId' => $spaEntityTypeId,
        'order' => ['id' => 'ASC'],
        'select' => ['id', 'title', $spaPhoneMaskFieldId, 'contactId', 'contactIds', 'contacts'],
        'start' => $start
    ]);

    $spaItems = array_slice($spaListFetch['result']['items'] ?? [], 0, $limit);

    logEvent("SPA VALIDATION STEP 02 LIST RESULT", [
        'entityTypeId' => $spaEntityTypeId,
        'api_response' => $spaListFetch,
        'received_count' => count($spaItems),
        'next' => $spaListFetch['next'] ?? null
    ]);

    $checked = 0;
    $updated = 0;
    $skippedFilled = 0;
    $skippedNoPhone = 0;

    foreach ($spaItems as $spaItem) {
        $checked++;
        $spaItemId = $spaItem['id'] ?? null;
        $currentMask = $spaItem[$spaPhoneMaskFieldId] ?? '';

        logEvent("SPA VALIDATION STEP 03 ITEM CHECK", [
            'item_id' => $spaItemId,
            'current_mask' => $currentMask,
            'contactId' => $spaItem['contactId'] ?? null,
            'contactIds' => $spaItem['contactIds'] ?? null,
            'contacts' => $spaItem['contacts'] ?? null
        ]);

        if (empty($spaItemId)) {
            continue;
        }

        if (!empty($currentMask)) {
            $skippedFilled++;
            logEvent("SPA VALIDATION STEP 04 ITEM SKIPPED", [
                'item_id' => $spaItemId,
                'reason' => 'Phone mask already filled.'
            ]);
            continue;
        }

        $rawPhone = getSpaContactPhone($spaItem, $spaItemId);
        $maskedPhone = maskPhone($rawPhone);

        logEvent("SPA VALIDATION STEP 05 MASK RESULT", [
            'item_id' => $spaItemId,
            'original_phone' => $rawPhone,
            'target_phone' => $maskedPhone
        ]);

        if (empty($maskedPhone)) {
            $skippedNoPhone++;
            logEvent("SPA VALIDATION STEP 06 ITEM SKIPPED", [
                'item_id' => $spaItemId,
                'reason' => 'No contact phone found.'
            ]);
            continue;
        }

        $updateResult = CRest::call('crm.item.update', [
            'entityTypeId' => $spaEntityTypeId,
            'id' => $spaItemId,
            'fields' => [
                $spaPhoneMaskFieldId => $maskedPhone
            ]
        ]);

        $updated++;
        logEvent("SPA VALIDATION STEP 07 UPDATE RESPONSE", [
            'item_id' => $spaItemId,
            'target_phone' => $maskedPhone,
            'api_response' => $updateResult
        ]);
    }

    logEvent("SPA VALIDATION STEP 08 COMPLETED", [
        'checked' => $checked,
        'updated' => $updated,
        'skipped_filled' => $skippedFilled,
        'skipped_no_phone' => $skippedNoPhone,
        'next' => $spaListFetch['next'] ?? null
    ]);
}

function updateLinkedEntityMaskFromContact($entityType, $entityId, $phoneMaskFieldId, $maskedPhone)
{
    $entityTitle = strtoupper($entityType);
    $getMethod = "crm.$entityType.get";
    $updateMethod = "crm.$entityType.update";

    logEvent("CONTACT STEP LINKED $entityTitle FETCH START", [
        'method' => $getMethod,
        'entity_id' => $entityId
    ]);

    $entityDataFetch = CRest::call($getMethod, ['id' => $entityId]);
    $entityFields = $entityDataFetch['result'] ?? [];

    logEvent("CONTACT STEP LINKED $entityTitle FETCH RESULT", [
        'method' => $getMethod,
        'entity_id' => $entityId,
        'api_response' => $entityDataFetch
    ]);

    if (($entityFields[$phoneMaskFieldId] ?? '') === $maskedPhone) {
        logEvent("CONTACT STEP LINKED $entityTitle UPDATE SKIPPED", [
            'entity_id' => $entityId,
            'reason' => 'Phone mask field already has target value.',
            'target_phone' => $maskedPhone
        ]);
        return;
    }

    $fieldsToUpdate = [
        $phoneMaskFieldId => $maskedPhone
    ];

    logEvent("CONTACT STEP LINKED $entityTitle UPDATE PAYLOAD", [
        'method' => $updateMethod,
        'entity_id' => $entityId,
        'fields' => $fieldsToUpdate
    ]);

    $updateResult = CRest::call($updateMethod, [
        'id' => $entityId,
        'fields' => $fieldsToUpdate
    ]);

    logEvent("CONTACT STEP LINKED $entityTitle UPDATE RESPONSE", [
        'entity_id' => $entityId,
        'api_response' => $updateResult
    ]);
}

function processContactPhoneMask($contactId, $leadPhoneMaskFieldId, $dealPhoneMaskFieldId, $spaEntityTypeId, $spaPhoneMaskFieldId, $eventName)
{
    logEvent("CONTACT STEP 01 EVENT RECEIVED", [
        'event' => $eventName,
        'contact_id' => $contactId
    ]);

    if (!$contactId) {
        logEvent("CONTACT STEP 02 STOPPED", "Contact ID is empty.");
        return;
    }

    logEvent("CONTACT STEP 02 CONTACT FETCH START", [
        'method' => 'crm.contact.get',
        'contact_id' => $contactId
    ]);

    $contactDataFetch = CRest::call('crm.contact.get', ['id' => $contactId]);
    $contactFields = $contactDataFetch['result'] ?? [];
    $rawPhone = getFirstPhone($contactFields);

    logEvent("CONTACT STEP 03 CONTACT FETCH RESULT", [
        'contact_id' => $contactId,
        'api_response' => $contactDataFetch,
        'phone' => $rawPhone
    ]);

    $maskedPhone = maskPhone($rawPhone);

    logEvent("CONTACT STEP 04 MASKING CALCULATION", [
        'contact_id' => $contactId,
        'original_phone' => $rawPhone,
        'target_phone' => $maskedPhone
    ]);

    if (empty($maskedPhone)) {
        logEvent("CONTACT STEP 05 UPDATE SKIPPED", "Contact phone is empty.");
        return;
    }

    logEvent("CONTACT STEP 05 LINKED LEADS FETCH START", [
        'method' => 'crm.lead.list',
        'contact_id' => $contactId
    ]);

    $leadListFetch = CRest::call('crm.lead.list', [
        'filter' => ['CONTACT_ID' => $contactId],
        'select' => ['ID', $leadPhoneMaskFieldId]
    ]);
    $leads = $leadListFetch['result'] ?? [];

    logEvent("CONTACT STEP 06 LINKED LEADS FETCH RESULT", [
        'contact_id' => $contactId,
        'api_response' => $leadListFetch,
        'count' => count($leads)
    ]);

    foreach ($leads as $lead) {
        $leadId = $lead['ID'] ?? null;

        if (!empty($leadId)) {
            updateLinkedEntityMaskFromContact('lead', $leadId, $leadPhoneMaskFieldId, $maskedPhone);
        }
    }

    logEvent("CONTACT STEP 07 LINKED DEALS FETCH START", [
        'method' => 'crm.deal.list',
        'contact_id' => $contactId
    ]);

    $dealListFetch = CRest::call('crm.deal.list', [
        'filter' => ['CONTACT_ID' => $contactId],
        'select' => ['ID', $dealPhoneMaskFieldId]
    ]);
    $deals = $dealListFetch['result'] ?? [];

    logEvent("CONTACT STEP 08 LINKED DEALS FETCH RESULT", [
        'contact_id' => $contactId,
        'api_response' => $dealListFetch,
        'count' => count($deals)
    ]);

    foreach ($deals as $deal) {
        $dealId = $deal['ID'] ?? null;

        if (!empty($dealId)) {
            updateLinkedEntityMaskFromContact('deal', $dealId, $dealPhoneMaskFieldId, $maskedPhone);
        }
    }

    logEvent("CONTACT STEP 09 LINKED SPA FETCH START", [
        'method' => 'crm.item.list',
        'entityTypeId' => $spaEntityTypeId,
        'contact_id' => $contactId
    ]);

    $spaListFetch = CRest::call('crm.item.list', [
        'entityTypeId' => $spaEntityTypeId,
        'filter' => ['contactId' => $contactId],
        'select' => ['id', $spaPhoneMaskFieldId, 'contactId', 'contactIds', 'contacts']
    ]);
    $spaItems = $spaListFetch['result']['items'] ?? [];

    logEvent("CONTACT STEP 10 LINKED SPA FETCH RESULT", [
        'contact_id' => $contactId,
        'entityTypeId' => $spaEntityTypeId,
        'api_response' => $spaListFetch,
        'count' => count($spaItems)
    ]);

    foreach ($spaItems as $spaItem) {
        $spaItemId = $spaItem['id'] ?? null;

        if (!empty($spaItemId)) {
            updateSpaItemMask($spaEntityTypeId, $spaItemId, $spaPhoneMaskFieldId, $maskedPhone);
        }
    }

    logEvent("CONTACT STEP 09 COMPLETED", [
        'contact_id' => $contactId,
        'masked_phone' => $maskedPhone,
        'lead_count' => count($leads),
        'deal_count' => count($deals),
        'spa_count' => count($spaItems)
    ]);
}

$eventName = preg_replace('/[^A-Z]/', '', strtoupper(trim($data['event'] ?? '')));
$entityId = $data['data']['FIELDS']['ID'] ?? null;
$requestEntityTypeId = $data['data']['FIELDS']['ENTITY_TYPE_ID'] ?? $data['data']['FIELDS']['entityTypeId'] ?? $data['entityTypeId'] ?? null;
$manualSpaRun = ($data['run_spa_mask'] ?? '') === '1';
$manualSpaItemId = $data['spa_item_id'] ?? null;
$validateSpaRun = ($data['run_spa_mask_validate'] ?? '') === '1';
$validateSpaLimit = $data['limit'] ?? 50;
$validateSpaStart = $data['start'] ?? 0;

logEvent("STEP 02 ROUTER DEBUG", [
    'normalized_event' => $eventName,
    'entity_id' => $entityId,
    'entityTypeId' => $requestEntityTypeId,
    'manual_spa_run' => $manualSpaRun,
    'manual_spa_item_id' => $manualSpaItemId,
    'validate_spa_run' => $validateSpaRun,
    'validate_spa_limit' => $validateSpaLimit,
    'validate_spa_start' => $validateSpaStart
]);

if ($validateSpaRun) {
    logEvent("STEP 03 ROUTED TO SPA VALIDATION", [
        'entityTypeId' => $spaEntityTypeId,
        'limit' => $validateSpaLimit,
        'start' => $validateSpaStart
    ]);
    validateSpaPhoneMasks($spaEntityTypeId, $spaPhoneMaskFieldId, $validateSpaLimit, $validateSpaStart);
} elseif ($manualSpaRun && !empty($manualSpaItemId)) {
    logEvent("STEP 03 ROUTED TO MANUAL SPA", [
        'spa_item_id' => $manualSpaItemId,
        'entityTypeId' => $spaEntityTypeId
    ]);
    processSpaPhoneMask($spaEntityTypeId, $manualSpaItemId, $spaPhoneMaskFieldId, 'MANUAL_SPA_MASK_RUN');
} elseif ($manualSpaRun) {
    logEvent("MANUAL SPA RUN SKIPPED", "spa_item_id is required.");
} elseif (strpos($eventName, 'CRMLEAD') !== false) {
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
} elseif (strpos($eventName, 'CRMCONTACT') !== false) {
    logEvent("STEP 03 ROUTED TO CONTACT", [
        'event' => $eventName,
        'entity_id' => $entityId
    ]);
    processContactPhoneMask($entityId, $leadPhoneMaskFieldId, $dealPhoneMaskFieldId, $spaEntityTypeId, $spaPhoneMaskFieldId, $eventName);
} elseif (strpos($eventName, 'CRMDYNAMIC') !== false || strpos($eventName, 'CRMITEM') !== false || (string)$requestEntityTypeId === (string)$spaEntityTypeId) {
    logEvent("STEP 03 ROUTED TO SPA", [
        'event' => $eventName,
        'entity_id' => $entityId,
        'entityTypeId' => $requestEntityTypeId ?: $spaEntityTypeId
    ]);
    processSpaPhoneMask($spaEntityTypeId, $entityId, $spaPhoneMaskFieldId, $eventName);
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

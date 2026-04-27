<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php'); // <-- Ensure this is linked

$leadPhoneFieldId = 'UF_CRM_1768897823888';
$leadPhoneMaskFieldId = 'UF_CRM_1777275227928';

date_default_timezone_set('Asia/Dubai');

// logEvent("TEST NAME", "test name");

$data = $_POST;

logEvent("REQUEST DEBUG", [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'event' => $data['event'] ?? '',
    'lead_id' => $data['data']['FIELDS']['ID'] ?? '',
    'post_keys' => array_keys($data)
]);

if (isset($data['event']) && $data['event'] === 'ONCRMLEADADD') {

    $leadId = $data['data']['FIELDS']['ID'] ?? null;
    
    // Log the start of the event
    logEvent("EVENT RECEIVED", "New Lead Detected: ID " . ($leadId ?? 'Unknown'));

    if ($leadId) {
        // STEP 1: Fetch lead details
        $leadDataFetch = CRest::call('crm.lead.get', ['id' => $leadId]);
        $leadFields = $leadDataFetch['result'] ?? [];

        logEvent("FETCHED LEAD DATA", "Lead ID: $leadId successfully fetched.");

        // STEP 2: Extract Phone from Lead
        // First, check the Custom Fields (based on your previous payload)
        $rawPhone = $leadFields[$leadPhoneFieldId] ?? '';

        // If Custom Field is empty, check standard Lead field safely
        if (empty($rawPhone)) {
            $rawPhone = isset($leadFields['PHONE']) && is_array($leadFields['PHONE']) ? $leadFields['PHONE'][0]['VALUE'] : '';
        }

        // STEP 2.5: Fallback to Linked Contact
        // If the phone is STILL empty, and the lead has a linked contact, fetch the contact data
        $contactId = $leadFields['CONTACT_ID'] ?? null;
        
        if (empty($rawPhone) && !empty($contactId) && $contactId > 0) {
            logEvent("FETCHING CONTACT DATA", "Lead is missing phone. Fetching Contact ID: $contactId");
            
            $contactDataFetch = CRest::call('crm.contact.get', ['id' => $contactId]);
            $contactFields = $contactDataFetch['result'] ?? [];
            
            // Grab Contact Phone if Lead Phone is empty
            if (empty($rawPhone)) {
                $rawPhone = isset($contactFields['PHONE']) && is_array($contactFields['PHONE']) ? $contactFields['PHONE'][0]['VALUE'] : '';
            }
        }

        $maskedPhone = '';

        // STEP 3: Masking Logic
        // Mask Phone
        if (!empty($rawPhone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
            $len = strlen($cleanPhone);

            if ($len > 4) {
                $visiblePart = substr($cleanPhone, 0, -4);
                $maskedPhone = $visiblePart . "xxxx";
            } else {
                $maskedPhone = str_repeat("x", $len);
            }
        }

        // Log the exact calculation so you can check it per event
        logEvent("MASKING CALCULATION", [
            'Original_Phone' => $rawPhone,
            'Target_Phone'   => $maskedPhone
        ]);

        // STEP 4: Update the Lead Dynamically
        $fieldsToUpdate = [];
        if (!empty($maskedPhone)) {
            $fieldsToUpdate[$leadPhoneMaskFieldId] = $maskedPhone;
        }

        if (!empty($fieldsToUpdate)) {
            logEvent("FIELDS TO UPDATE", $fieldsToUpdate);

            $updateResult = CRest::call('crm.lead.update', [
                'id' => $leadId,
                'fields' => $fieldsToUpdate
            ]);

            logEvent("UPDATE API RESPONSE", $updateResult);
        } else {
            logEvent("SKIP UPDATE", "No phone found on the Lead or the Linked Contact to mask.");
        }
    }
} else {
    // Optional: Log pings that aren't the correct event
    if (!empty($data)) {
        logEvent("IGNORED EVENT", $data['event'] ?? 'No event name');
    }
}

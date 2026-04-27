<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php'); // <-- Ensure this is linked

$leadEmailFieldId = 'UF_CRM_1768897839376';
$leadPhoneFieldId = 'UF_CRM_1768897823888';

date_default_timezone_set('Asia/Dubai');

$data = $_POST;

if (isset($data['event']) && $data['event'] === 'ONCRMLEADADD') {

    $leadId = $data['data']['FIELDS']['ID'] ?? null;
    
    // Log the start of the event
    logEvent("EVENT RECEIVED", "New Lead Detected: ID " . ($leadId ?? 'Unknown'));

    if ($leadId) {
        // STEP 1: Fetch lead details
        $leadDataFetch = CRest::call('crm.lead.get', ['id' => $leadId]);
        $leadFields = $leadDataFetch['result'] ?? [];

        logEvent("FETCHED LEAD DATA", "Lead ID: $leadId successfully fetched.");

        // STEP 2: Extract Email and Phone from Lead
        // First, check the Custom Fields (based on your previous payload)
        $rawEmail = $leadFields[$leadEmailFieldId] ?? '';
        $rawPhone = $leadFields[$leadPhoneFieldId] ?? '';

        // If Custom Fields are empty, check standard Lead fields safely
        if (empty($rawEmail)) {
            $rawEmail = isset($leadFields['EMAIL']) && is_array($leadFields['EMAIL']) ? $leadFields['EMAIL'][0]['VALUE'] : '';
        }
        if (empty($rawPhone)) {
            $rawPhone = isset($leadFields['PHONE']) && is_array($leadFields['PHONE']) ? $leadFields['PHONE'][0]['VALUE'] : '';
        }

        // STEP 2.5: Fallback to Linked Contact
        // If the email or phone is STILL empty, and the lead has a linked contact, fetch the contact data
        $contactId = $leadFields['CONTACT_ID'] ?? null;
        
        if ((empty($rawEmail) || empty($rawPhone)) && !empty($contactId) && $contactId > 0) {
            logEvent("FETCHING CONTACT DATA", "Lead is missing email/phone. Fetching Contact ID: $contactId");
            
            $contactDataFetch = CRest::call('crm.contact.get', ['id' => $contactId]);
            $contactFields = $contactDataFetch['result'] ?? [];

            // Grab Contact Email if Lead Email is empty
            if (empty($rawEmail)) {
                $rawEmail = isset($contactFields['EMAIL']) && is_array($contactFields['EMAIL']) ? $contactFields['EMAIL'][0]['VALUE'] : '';
            }
            
            // Grab Contact Phone if Lead Phone is empty
            if (empty($rawPhone)) {
                $rawPhone = isset($contactFields['PHONE']) && is_array($contactFields['PHONE']) ? $contactFields['PHONE'][0]['VALUE'] : '';
            }
        }

        $maskedEmail = '';
        $maskedPhone = '';

        // STEP 3: Masking Logic
        // 1. Mask Email
        if (!empty($rawEmail)) {
            $parts = explode("@", $rawEmail);
            $namePart = $parts[0];
            $domainPart = $parts[1] ?? '';

            if (strlen($namePart) > 4) {
                $maskedEmail = "xxxx" . substr($namePart, 4) . "@" . $domainPart;
            } else {
                $maskedEmail = str_repeat("x", strlen($namePart)) . "@" . $domainPart;
            }
        }

        // 2. Mask Phone
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
            'Original_Email' => $rawEmail,
            'Target_Email'   => $maskedEmail,
            'Original_Phone' => $rawPhone,
            'Target_Phone'   => $maskedPhone
        ]);

        // STEP 4: Update the Lead Dynamically
        $fieldsToUpdate = [];
        if (!empty($maskedEmail)) {
            $fieldsToUpdate[$leadEmailFieldId] = $maskedEmail;
        }
        if (!empty($maskedPhone)) {
            $fieldsToUpdate[$leadPhoneFieldId] = $maskedPhone;
        }

        if (!empty($fieldsToUpdate)) {
            $updateResult = CRest::call('crm.lead.update', [
                'id' => $leadId,
                'fields' => $fieldsToUpdate
            ]);

            logEvent("UPDATE API RESPONSE", $updateResult);
        } else {
            logEvent("SKIP UPDATE", "No email or phone found on the Lead or the Linked Contact to mask.");
        }
    }
} else {
    // Optional: Log pings that aren't the correct event
    if (!empty($data)) {
        logEvent("IGNORED EVENT", $data['event'] ?? 'No event name');
    }
}

<?php

function formatDate($dateString)
{
    try {
        $date = new DateTime($dateString, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Dubai'));
        return $date->format('d M Y, h:i A');
    } catch (Exception $e) {
        return "Invalid date format";
    }
}

function logEvent($message, $data = [])
{
    $baseLogDir = __DIR__ . '/logs';
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $dirPath = "$baseLogDir/$year/$month/$day";

    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0777, true);
    }

    $logFile = "$dirPath/requests.log";
    $timestamp = date('Y-m-d H:i:s');

    $logEntry = [
        'timestamp' => $timestamp,
        'message' => $message,
        'data' => $data
    ];

    $entry = json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND);
}
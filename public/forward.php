<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Twilio\TwiML\VoiceResponse;

// Create response
$response = new VoiceResponse();

// Get caller info for logging
$from = $_POST['From'] ?? '';
$to = $_POST['To'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

// Hardcode the forward number and Growably URL
$forwardNumber = '+12145500953'; // Your phone number
$growablyUrl = 'https://services.leadconnectorhq.com/phone-system/voice-call/inbound';

// Log the call
error_log("Call from $from to $to (SID: $callSid)");

// Get Add-ons data if available
$addOns = json_decode($_POST['AddOns'] ?? '{}', true);

// Check for obvious spam
$spamScore = 0;

// Check Nomorobo
if (!empty($addOns['results']['nomorobo_spamscore']['result']['score'])) {
    if ($addOns['results']['nomorobo_spamscore']['result']['score'] > 0.8) {
        $spamScore++;
        error_log("Nomorobo flagged as spam");
    }
}

// Check Marchex
if (!empty($addOns['results']['marchex_cleancall']['result']['result']['recommendation'])) {
    if ($addOns['results']['marchex_cleancall']['result']['result']['recommendation'] === 'BLOCK') {
        $spamScore++;
        error_log("Marchex recommends block");
    }
}

// Only block if very confident it's spam
if ($spamScore >= 2) {
    error_log("Blocking spam call from $from");
    $response->say('This call has been blocked.');
    $response->hangup();
} else {
    // Just dial the number directly
    error_log("Forwarding to $forwardNumber");
    $response->dial($forwardNumber, [
        'callerId' => $to
    ]);
}

// Return TwiML
header('Content-Type: text/xml');
echo $response;

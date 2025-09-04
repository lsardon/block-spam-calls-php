<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Twilio\TwiML\VoiceResponse;

// Create response
$response = new VoiceResponse();

// Get caller info
$from = $_POST['From'] ?? '';
$to = $_POST['To'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

// Log the call
error_log("Call from $from to $to (SID: $callSid)");

// Get Add-ons data
$addOns = json_decode($_POST['AddOns'] ?? '{}', true);

// Check for spam
$spamScore = 0;

// Check Nomorobo
if (!empty($addOns['results']['nomorobo_spamscore']['result']['score'])) {
    $score = $addOns['results']['nomorobo_spamscore']['result']['score'];
    if ($score > 0.5) $spamScore++;
    error_log("Nomorobo score: $score");
}

// Check Marchex
if (!empty($addOns['results']['marchex_cleancall']['result']['result']['recommendation'])) {
    $rec = $addOns['results']['marchex_cleancall']['result']['result']['recommendation'];
    if ($rec === 'BLOCK') $spamScore++;
    error_log("Marchex recommendation: $rec");
}

// Check Whitepages
if (!empty($addOns['results']['whitepages_pro_phone_reputation']['result']['reputation_level'])) {
    $level = $addOns['results']['whitepages_pro_phone_reputation']['result']['reputation_level'];
    if ($level >= 3) $spamScore++;
    error_log("Whitepages level: $level");
}

// Make decision
error_log("Total spam score: $spamScore");

if ($spamScore >= 2) {
    // Block spam
    $response->say('This number has been flagged as spam.');
    $response->hangup();
} elseif ($spamScore === 1) {
    // Screen suspicious
    $gather = $response->gather(['numDigits' => 1, 'timeout' => 5]);
    $gather->say('Press 1 to continue your call.');
    $response->say('Goodbye.');
    $response->hangup();
} else {
    // Forward legitimate calls
    $response->dial('+12145500953'); // Replace with your number
}

// Return TwiML
header('Content-Type: text/xml');
echo $response;

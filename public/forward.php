<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Twilio\TwiML\VoiceResponse;

// Create response
$response = new VoiceResponse();

// Get caller info for logging
$from = $_POST['From'] ?? '';
$to = $_POST['To'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

// Get the forward number from environment variable
$forwardNumber = $_ENV['TWILIO_PHONE_NUMBER'] ?? $_SERVER['TWILIO_PHONE_NUMBER'] ?? '+12145500953';

// Log the call
error_log("Call from $from to $to (forwarding to: $forwardNumber)");

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
    // Try redirect to Growably first
    $growablyUrl = $_ENV['GROWABLY_WEBHOOK_URL'] ?? $_SERVER['GROWABLY_WEBHOOK_URL'] ?? null;
    
    if ($growablyUrl) {
        error_log("Redirecting to Growably: $growablyUrl");
        $response->redirect($growablyUrl, ['method' => 'POST']);
    } else {
        // Fallback to dialing the number
        error_log("Dialing number: $forwardNumber");
        $response->say('Connecting your call.', ['voice' => 'alice']);
        $response->dial($forwardNumber);
    }
}

// Return TwiML
header('Content-Type: text/xml');
echo $response;

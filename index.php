<?php
require_once __DIR__ . '/vendor/autoload.php';
use Twilio\TwiML\VoiceResponse;

// Simple routing
$response = new VoiceResponse();

// Get POST data
$from = $_POST['From'] ?? '';
$addOns = json_decode($_POST['AddOns'] ?? '{}', true);

// Count spam indicators
$spamCount = 0;

// Check Nomorobo
if (isset($addOns['results']['nomorobo_spamscore']['result']['score'])) {
    if ($addOns['results']['nomorobo_spamscore']['result']['score'] > 0.5) {
        $spamCount++;
    }
}

// Check Marchex
if (isset($addOns['results']['marchex_cleancall']['result']['result']['recommendation'])) {
    if ($addOns['results']['marchex_cleancall']['result']['result']['recommendation'] === 'BLOCK') {
        $spamCount++;
    }
}

// Route based on spam score
if ($spamCount >= 2) {
    $response->say('This call has been blocked.');
    $response->hangup();
} else {
    // Forward to your number
    $response->dial(getenv('FORWARD_NUMBER') ?: '+12145500953');
}

header('Content-Type: text/xml');
echo $response;

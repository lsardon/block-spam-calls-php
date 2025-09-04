<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Twilio\TwiML\VoiceResponse;

// Forwarding URL - Growably webhook (replaces ngrok from original project)
// In production, this could be set via environment variable
$forwardingUrl = $_ENV['FORWARDING_URL'] ?? 'https://services.leadconnectorhq.com/phone-system/voice-call/inbound';

// Spam blocking threshold (number of services that must flag as spam)
$blockThreshold = (int)($_ENV['BLOCK_THRESHOLD'] ?? 2);

// Get call data
$from = $_POST['From'] ?? '';
$to = $_POST['To'] ?? '';
$callSid = $_POST['CallSid'] ?? '';

error_log("Incoming call from $from to $to (SID: $callSid)");

// Check for spam across multiple add-ons
$spamCount = 0;
$spamReasons = [];

if (isset($_POST['AddOns'])) {
    $addOns = json_decode($_POST['AddOns'], true);
    
    // Check Marchex Clean Call
    if (isset($addOns['results']['marchex_cleancall']['result']['result'])) {
        $marchex = $addOns['results']['marchex_cleancall']['result']['result'];
        $recommendation = $marchex['recommendation'] ?? '';
        
        if ($recommendation === 'BLOCK') {
            $spamCount++;
            $spamReasons[] = 'Marchex: ' . ($marchex['reason'] ?? 'spam detected');
            error_log("Marchex recommends BLOCK");
        } else {
            error_log("Marchex recommends: $recommendation");
        }
    }
    
    // Check Icehook Scout (if enabled)
    if (isset($addOns['results']['icehook_scout']['result']['result'])) {
        $scout = $addOns['results']['icehook_scout']['result']['result'];
        $recommendation = $scout['recommendation'] ?? '';
        
        if ($recommendation === 'BLOCK') {
            $spamCount++;
            $spamReasons[] = 'Icehook: ' . ($scout['reason'] ?? 'spam detected');
            error_log("Icehook recommends BLOCK");
        }
    }
    
    // Check Truecnam/Truespam (if enabled)
    if (isset($addOns['results']['truecnam_truespam']['result']['result'])) {
        $truecnam = $addOns['results']['truecnam_truespam']['result']['result'];
        $spamScore = $truecnam['spam_score'] ?? 0;
        
        if ($spamScore > 75) {  // 75+ is typically spam
            $spamCount++;
            $spamReasons[] = 'Truecnam: score ' . $spamScore;
            error_log("Truecnam spam score: $spamScore");
        }
    }
}

// Generate TwiML response
$response = new VoiceResponse();

// Block if threshold met
if ($spamCount >= $blockThreshold) {
    error_log("Blocking call - flagged by $spamCount services: " . implode(', ', $spamReasons));
    $response->say('This number has been disconnected.');
    $response->hangup();
} else {
    // Forward clean calls to the webhook URL
    error_log("Forwarding call to: $forwardingUrl");
    $response->redirect($forwardingUrl, ['method' => 'POST']);
}

// Output TwiML
header('Content-Type: text/xml');
echo $response;

// Log the generated TwiML for debugging
error_log("Generated TwiML: " . $response);
?>

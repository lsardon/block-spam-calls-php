<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Twilio\TwiML\VoiceResponse;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// Main webhook endpoint for incoming calls
$app->post('/', function (Request $request, Response $response, $args) {
    $params = $request->getParsedBody();
    
    // Extract caller information
    $from = $params['From'] ?? '';
    $to = $params['To'] ?? '';
    $callSid = $params['CallSid'] ?? '';
    $callerName = $params['CallerName'] ?? '';
    $callerCity = $params['FromCity'] ?? '';
    $callerState = $params['FromState'] ?? '';
    $callerCountry = $params['FromCountry'] ?? '';
    
    // Log the incoming call (optional)
    error_log("Incoming call from: $from to: $to (CallSid: $callSid)");
    
    // Check if this is a spam call
    $spamCheckResult = checkIfSpam($from, $callerName, $callerCity, $callerState);
    
    $twiml = new VoiceResponse();
    
    if ($spamCheckResult['isSpam']) {
        // Handle spam calls
        error_log("SPAM BLOCKED: $from - Reason: " . $spamCheckResult['reason']);
        
        // Choose response based on spam type
        switch ($spamCheckResult['action']) {
            case 'hangup':
                // Just hang up on obvious spam
                $twiml->hangup();
                break;
                
            case 'voicemail':
                // Send to voicemail (you could forward to a voicemail service)
                $twiml->say('Please leave a message.', [
                    'voice' => 'woman',
                    'language' => 'en-US'
                ]);
                $twiml->hangup();
                break;
                
            case 'blocked_message':
            default:
                // Play blocked message
                $twiml->say('This number has been blocked. If you believe this is an error, please email support.', [
                    'voice' => 'woman',
                    'language' => 'en-US'
                ]);
                $twiml->pause(['length' => 1]);
                $twiml->hangup();
                break;
        }
    } else {
        // Legitimate call - forward to LeadConnector
        error_log("CALL FORWARDED: $from to LeadConnector");
        
        // Optional: Play a brief message before forwarding
        // $twiml->say('Connecting your call.', [
        //     'voice' => 'woman',
        //     'language' => 'en-US'
        // ]);
        
        // Forward the call to LeadConnector with all original parameters
        $twiml->redirect('https://services.leadconnectorhq.com/phone-system/voice-call/inbound');
    }
    
    // Return TwiML response (use __toString() or cast to string)
    $response->getBody()->write((string)$twiml);
    return $response->withHeader('Content-Type', 'text/xml');
});

// Health check endpoint (GET request)
$app->get('/', function (Request $request, Response $response, $args) {
    $status = [
        'status' => 'active',
        'service' => 'Spam Call Filter',
        'forwarding_to' => 'LeadConnector',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $response->getBody()->write(json_encode($status));
    return $response->withHeader('Content-Type', 'application/json');
});

// Spam checking function with multiple criteria
function checkIfSpam($phoneNumber, $callerName = '', $city = '', $state = '') {
    $result = [
        'isSpam' => false,
        'reason' => '',
        'action' => 'forward'
    ];
    
    // 1. Check blocked numbers list (from environment variable)
    $blockedNumbers = array_filter(explode(',', $_ENV['BLOCKED_NUMBERS'] ?? ''));
    if (in_array($phoneNumber, $blockedNumbers)) {
        return [
            'isSpam' => true,
            'reason' => 'Number in blocklist',
            'action' => 'blocked_message'
        ];
    }
    
    // 2. Check blocked area codes (from environment variable)
    $blockedAreaCodes = array_filter(explode(',', $_ENV['BLOCKED_AREA_CODES'] ?? ''));
    $areaCode = getAreaCode($phoneNumber);
    if ($areaCode && in_array($areaCode, $blockedAreaCodes)) {
        return [
            'isSpam' => true,
            'reason' => "Blocked area code: $areaCode",
            'action' => 'hangup'
        ];
    }
    
    // 3. Check for anonymous/private numbers
    if (empty($phoneNumber) || $phoneNumber === 'anonymous' || $phoneNumber === 'private' || strlen($phoneNumber) < 10) {
        return [
            'isSpam' => true,
            'reason' => 'Anonymous or invalid number',
            'action' => 'voicemail'
        ];
    }
    
    // 4. Business hours check (if enabled)
    if (($_ENV['BUSINESS_HOURS_ONLY'] ?? 'false') === 'true') {
        $currentHour = (int)date('H');
        $startHour = (int)($_ENV['BUSINESS_HOURS_START'] ?? 8);
        $endHour = (int)($_ENV['BUSINESS_HOURS_END'] ?? 20);
        
        if ($currentHour < $startHour || $currentHour >= $endHour) {
            // During off-hours, you might want to send to voicemail instead of blocking
            if (($_ENV['OFF_HOURS_BLOCK'] ?? 'false') === 'true') {
                return [
                    'isSpam' => true,
                    'reason' => 'Call outside business hours',
                    'action' => 'voicemail'
                ];
            }
        }
    }
    
    // 5. Check for suspicious patterns
    if (isSuspiciousPattern($phoneNumber, $callerName)) {
        return [
            'isSpam' => true,
            'reason' => 'Suspicious pattern detected',
            'action' => 'blocked_message'
        ];
    }
    
    // 6. Check allowed numbers list (whitelist) - always allow these
    $allowedNumbers = array_filter(explode(',', $_ENV['ALLOWED_NUMBERS'] ?? ''));
    if (!empty($allowedNumbers) && !in_array($phoneNumber, $allowedNumbers)) {
        // If whitelist is defined and number is not in it
        if (($_ENV['WHITELIST_ONLY'] ?? 'false') === 'true') {
            return [
                'isSpam' => true,
                'reason' => 'Number not in whitelist',
                'action' => 'blocked_message'
            ];
        }
    }
    
    return $result;
}

// Helper function to extract area code from phone number
function getAreaCode($phoneNumber) {
    // Remove non-numeric characters
    $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // For US numbers (11 digits starting with 1 or 10 digits)
    if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '1') {
        return substr($cleaned, 1, 3);
    } elseif (strlen($cleaned) === 10) {
        return substr($cleaned, 0, 3);
    }
    
    return null;
}

// Check for suspicious patterns in phone numbers or caller names
function isSuspiciousPattern($phoneNumber, $callerName) {
    // Check for repeated digits (like 1111111111)
    $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (preg_match('/(\d)\1{9,}/', $cleaned)) {
        return true;
    }
    
    // Check for sequential numbers (like 1234567890)
    if (strpos($cleaned, '1234567') !== false || strpos($cleaned, '0123456') !== false) {
        return true;
    }
    
    // Check for known spam caller name patterns
    $spamNamePatterns = array_filter(explode(',', $_ENV['SPAM_NAME_PATTERNS'] ?? 'SPAM,SCAM,TELEMARKET,ROBOCALL'));
    foreach ($spamNamePatterns as $pattern) {
        if (stripos($callerName, trim($pattern)) !== false) {
            return true;
        }
    }
    
    return false;
}

$app->run();

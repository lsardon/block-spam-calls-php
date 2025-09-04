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
    
    // Log the incoming call
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
                $twiml->hangup();
                break;
                
            case 'voicemail':
                $twiml->say('Please leave a message.', [
                    'voice' => 'woman',
                    'language' => 'en-US'
                ]);
                $twiml->hangup();
                break;
                
            case 'blocked_message':
            default:
                $twiml->say('This number has been blocked. If you believe this is an error, please email support.', [
                    'voice' => 'woman',
                    'language' => 'en-US'
                ]);
                $twiml->pause(['length' => 1]);
                $twiml->hangup();
                break;
        }
    } else {
        // Legitimate call - forward based on routing rules
        error_log("CALL FORWARDED: $from to destination for $to");
        
        // Get the forward URL based on the called number
        $forwardUrl = getForwardUrl($to);
        
        if ($forwardUrl) {
            error_log("Forwarding to: $forwardUrl");
            $twiml->redirect($forwardUrl);
        } else {
            // No routing found - use default
            error_log("No specific route found for $to, using default");
            $defaultUrl = $_ENV['DEFAULT_FORWARD_URL'] ?? 'https://services.leadconnectorhq.com/phone-system/voice-call/inbound';
            $twiml->redirect($defaultUrl);
        }
    }
    
    $response->getBody()->write((string)$twiml);
    return $response->withHeader('Content-Type', 'text/xml');
});

// Health check endpoint
$app->get('/', function (Request $request, Response $response, $args) {
    $status = [
        'status' => 'active',
        'service' => 'Spam Call Filter',
        'forwarding_to' => 'LeadConnector (Multi-Route)',
        'timestamp' => date('Y-m-d H:i:s'),
        'routes_configured' => getConfiguredRoutes()
    ];
    
    $response->getBody()->write(json_encode($status));
    return $response->withHeader('Content-Type', 'application/json');
});

// NEW FUNCTION: Get forward URL based on the number called
function getForwardUrl($toNumber) {
    // Clean the number for comparison
    $cleaned = preg_replace('/[^0-9+]/', '', $toNumber);
    
    // Check environment variables for routing
    // Format: ROUTE_[PHONE_NUMBER]=URL
    // Example: ROUTE_12145500953=https://services.leadconnectorhq.com/webhook1
    
    // Remove + and special chars for env variable name
    $envKey = 'ROUTE_' . preg_replace('/[^0-9]/', '', $cleaned);
    
    if (isset($_ENV[$envKey])) {
        return $_ENV[$envKey];
    }
    
    // Also check with simplified format (last 10 digits only)
    if (strlen($cleaned) > 10) {
        $last10 = substr($cleaned, -10);
        $envKey = 'ROUTE_' . $last10;
        if (isset($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }
    }
    
    // Check for route groups (like SALES, SUPPORT, etc)
    // Format: ROUTE_GROUP_[NAME]_NUMBERS=+12145500953,+19725551234
    // Format: ROUTE_GROUP_[NAME]_URL=https://...
    
    $routeGroups = ['SALES', 'SUPPORT', 'MAIN', 'AFTER_HOURS'];
    foreach ($routeGroups as $group) {
        $numbersKey = 'ROUTE_GROUP_' . $group . '_NUMBERS';
        $urlKey = 'ROUTE_GROUP_' . $group . '_URL';
        
        if (isset($_ENV[$numbersKey]) && isset($_ENV[$urlKey])) {
            $numbers = array_map('trim', explode(',', $_ENV[$numbersKey]));
            if (in_array($cleaned, $numbers) || in_array('+' . preg_replace('/[^0-9]/', '', $cleaned), $numbers)) {
                return $_ENV[$urlKey];
            }
        }
    }
    
    return null; // Will use default
}

// Get list of configured routes for health check
function getConfiguredRoutes() {
    $routes = [];
    
    // Individual routes
    foreach ($_ENV as $key => $value) {
        if (strpos($key, 'ROUTE_') === 0 && !strpos($key, 'GROUP')) {
            $number = str_replace('ROUTE_', '', $key);
            if (is_numeric($number)) {
                $routes['individual'][] = '+' . $number;
            }
        }
    }
    
    // Group routes
    $routeGroups = ['SALES', 'SUPPORT', 'MAIN', 'AFTER_HOURS'];
    foreach ($routeGroups as $group) {
        $numbersKey = 'ROUTE_GROUP_' . $group . '_NUMBERS';
        if (isset($_ENV[$numbersKey])) {
            $routes['groups'][$group] = $_ENV[$numbersKey];
        }
    }
    
    return $routes;
}

// [Keep all your existing checkIfSpam, getAreaCode, isSuspiciousPattern functions exactly as they are]
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
    
    // 6. Check allowed numbers list (whitelist)
    $allowedNumbers = array_filter(explode(',', $_ENV['ALLOWED_NUMBERS'] ?? ''));
    if (!empty($allowedNumbers) && !in_array($phoneNumber, $allowedNumbers)) {
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

function getAreaCode($phoneNumber) {
    $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '1') {
        return substr($cleaned, 1, 3);
    } elseif (strlen($cleaned) === 10) {
        return substr($cleaned, 0, 3);
    }
    
    return null;
}

function isSuspiciousPattern($phoneNumber, $callerName) {
    $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (preg_match('/(\d)\1{9,}/', $cleaned)) {
        return true;
    }
    
    if (strpos($cleaned, '1234567') !== false || strpos($cleaned, '0123456') !== false) {
        return true;
    }
    
    $spamNamePatterns = array_filter(explode(',', $_ENV['SPAM_NAME_PATTERNS'] ?? 'SPAM,SCAM,TELEMARKET,ROBOCALL'));
    foreach ($spamNamePatterns as $pattern) {
        if (stripos($callerName, trim($pattern)) !== false) {
            return true;
        }
    }
    
    return false;
}

$app->run();

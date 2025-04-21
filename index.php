<?php
require __DIR__ . '/vendor/autoload.php';

define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WEBHOOK_URL', 'https://your-render-service.onrender.com'); // Replace with your Render URL

// Initialize users.json if not exists
if (!file_exists('users.json')) {
    file_put_contents('users.json', '{}');
}

function logError($error) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - $error\n", FILE_APPEND);
}

function saveUsers($users) {
    file_put_contents('users.json', json_encode($users, JSON_PRETTY_PRINT));
}

function getMainKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [
                ['text' => '💰 Earn', 'callback_data' => 'earn'],
                ['text' => '💳 Balance', 'callback_data' => 'balance']
            ],
            [
                ['text' => '👥 Referrals', 'callback_data' => 'referrals'],
                ['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard']
            ],
            [
                ['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'],
                ['text' => '❓ Help', 'callback_data' => 'help']
            ]
        ]
    ]);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $reply_markup
    ];
    
    file_get_contents(API_URL . 'sendMessage?' . http_build_query($data));
}

function initializeBot() {
    // Set webhook
    $webhook_url = WEBHOOK_URL;
    file_get_contents(API_URL . "setWebhook?url=$webhook_url");
}

function processUpdate($update) {
    $users = json_decode(file_get_contents('users.json'), true);
    
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $params = explode(' ', $text);
            if (count($params) > 1) {
                $ref_code = $params[1];
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref_code && $id !== $chat_id) {
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50;
                        $users[$chat_id]['referred_by'] = $id;
                        break;
                    }
                }
            }
            
            $msg = "👋 Welcome! Use the buttons below to earn and manage points.\n";
            $msg .= "Your referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted_users = $users;
                usort($sorted_users, function($a, $b) {
                    return $b['balance'] - $a['balance'];
                });
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach (array_slice($sorted_users, 0, 5) as $user) {
                    $msg .= "$i. User {$user['ref_code']}: {$user['balance']} points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\n";
                $msg .= "Referrals: {$users[$chat_id]['referrals']}\n";
                $msg .= "Invite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n";
                $msg .= "50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\n";
                    $msg .= "Your balance: {$users[$chat_id]['balance']}\n";
                    $msg .= "Need " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n";
                $msg .= "💰 Earn: Get 10 points/min\n";
                $msg .= "👥 Refer: 50 points/ref\n";
                $msg .= "🏧 Withdraw: Min 100 points\n";
                $msg .= "Use buttons below to navigate!";
                break;
                
            default:
                $msg = "❌ Unknown command";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// Handle incoming webhook
try {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);
    
    if ($update) {
        initializeBot();
        processUpdate($update);
        http_response_code(200);
    } else {
        http_response_code(403);
        echo "Forbidden";
    }
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo "Internal Server Error";
}
?>
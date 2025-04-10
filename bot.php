<?php
/* ====================================================
 * ربات مدیریت تیم تلگرام - نسخه نهایی
 * ویژگی‌ها:
 * 1. پنل مدیریت وب با آمار و کنترل ربات
 * 2. سیستم پروفایل کاربران
 * 3. مدیریت پروژه‌ها با ظرفیت محدود
 * 4. پنل مدیریت درون تلگرامی
 * 5. سیستم چرخش خودکار پروکسی SOCKS5
 * 6. رابط کاربری جذاب با ایموجی
 * 7. مقاوم در برابر تحریمات
 * ==================================================== */

// ==================== تنظیمات اصلی ====================
define('BOT_TOKEN', '7735644617:AAHrma6nKvWbTh5pXvTGNz6eObUsUuK6I8w');
define('ADMIN_IDS', [8000743553]); // آیدی ادمین‌ها
define('DB_FILE', 'bot_db.sqlite');
define('WEB_PANEL_PASSWORD', '1387'); // برای دسترسی به پنل وب
define('PROXIES_FILE', 'proxies.txt'); // فایل پروکسی‌ها
define('MAX_PROXY_RETRIES', 3); // حداکثر تلاش برای هر پروکسی

// ==================== ایموجی‌ها ====================
$emoji = [
    'user' => '👤', 'team' => '👥', 'project' => '📁', 'stats' => '📊',
    'warn' => '⚠️', 'success' => '✅', 'error' => '❌', 'admin' => '🛡️',
    'home' => '🏠', 'profile' => '📝', 'list' => '📋', 'add' => '➕',
    'lock' => '🔒', 'unlock' => '🔓', 'settings' => '⚙️', 'back' => '🔙'
];

// ==================== مدیریت دیتابیس ====================
function initDB() {
    if (!file_exists(DB_FILE)) {
        $db = new SQLite3(DB_FILE);
        
        // ایجاد جدول کاربران
        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            telegram_id INTEGER UNIQUE,
            username TEXT,
            name TEXT,
            skills TEXT,
            bio TEXT,
            is_admin BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // ایجاد جدول پروژه‌ها
        $db->exec("CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            title TEXT,
            description TEXT,
            required_skills TEXT,
            max_members INTEGER,
            current_members INTEGER DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // ایجاد جدول عضویت کاربران در پروژه‌ها
        $db->exec("CREATE TABLE user_projects (
            user_id INTEGER,
            project_id INTEGER,
            joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, project_id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (project_id) REFERENCES projects(id)
        )");
        
        // ایجاد کاربر ادمین اولیه
        $stmt = $db->prepare("INSERT INTO users (telegram_id, username, name, is_admin) VALUES (?, ?, ?, 1)");
        $stmt->bindValue(1, ADMIN_IDS[0]);
        $stmt->bindValue(2, 'admin');
        $stmt->bindValue(3, 'مدیر سیستم');
        $stmt->execute();
        
        $db->close();
    }
    return new SQLite3(DB_FILE);
}

// ==================== مدیریت پروکسی ====================
function loadProxies() {
    if (!file_exists(PROXIES_FILE)) return [];
    $proxies = file(PROXIES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_filter($proxies, function($proxy) {
        return strpos($proxy, 'socks5://') === 0;
    });
}

function getRandomProxy() {
    static $proxies = [];
    static $bad_proxies = [];
    
    if (empty($proxies)) {
        $proxies = loadProxies();
        if (empty($proxies)) return false;
    }
    
    $available_proxies = array_diff($proxies, array_keys($bad_proxies));
    
    if (empty($available_proxies)) {
        $bad_proxies = []; // ریست لیست پروکسی‌های مشکل‌دار
        $available_proxies = $proxies;
    }
    
    return $available_proxies[array_rand($available_proxies)];
}

function markProxyAsBad($proxy) {
    static $bad_proxies = [];
    $bad_proxies[$proxy] = true;
    file_put_contents('proxy_errors.log', date('Y-m-d H:i:s') . " - Bad proxy: $proxy\n", FILE_APPEND);
}

// ==================== ارتباط با تلگرام ====================
function sendTelegramRequest($method, $data = [], $retry = MAX_PROXY_RETRIES) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $attempt = 0;
    
    while ($attempt < $retry) {
        $attempt++;
        $proxy = getRandomProxy();
        
        if (!$proxy) {
            file_put_contents('errors.log', "No valid proxies available\n", FILE_APPEND);
            return false;
        }
        
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'proxy' => $proxy,
                'request_fulluri' => true,
                'timeout' => 5
            ]
        ];
        
        try {
            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            
            if ($result !== false) {
                $response = json_decode($result, true);
                if ($response['ok']) return $response;
            }
        } catch (Exception $e) {
            markProxyAsBad($proxy);
            continue;
        }
        
        markProxyAsBad($proxy);
    }
    
    file_put_contents('errors.log', "Failed after $retry attempts for method: $method\n", FILE_APPEND);
    return false;
}

function sendTelegramMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) $data['reply_markup'] = $reply_markup;
    
    return sendTelegramRequest('sendMessage', $data);
}

// ==================== توابع کاربری ====================
function showMainMenu($chat_id, $user_id, $db) {
    global $emoji;
    $is_admin = $db->querySingle("SELECT is_admin FROM users WHERE telegram_id = $user_id");
    
    $text = "{$emoji['home']} <b>منوی اصلی</b>\n\n";
    $text .= "{$emoji['profile']} <b>پروفایل کاربری</b>\n";
    $text .= "/profile - مشاهده و ویرایش پروفایل\n\n";
    $text .= "{$emoji['project']} <b>پروژه‌ها</b>\n";
    $text .= "/projects - نمایش پروژه‌های فعال\n";
    
    if ($is_admin) {
        $text .= "\n{$emoji['admin']} <b>منوی مدیریت</b>\n";
        $text .= "/admin - پنل مدیریت\n";
    }
    
    $keyboard = [
        'keyboard' => [
            ["{$emoji['profile']} پروفایل", "{$emoji['project']} پروژه‌ها"],
            [$is_admin ? "{$emoji['admin']} مدیریت" : "{$emoji['settings']} تنظیمات"]
        ],
        'resize_keyboard' => true
    ];
    
    sendTelegramMessage($chat_id, $text, $keyboard);
}

function handleProfile($chat_id, $user_id, $db) {
    global $emoji;
    $profile = $db->querySingle("SELECT * FROM users WHERE telegram_id = $user_id", true);
    
    if (!$profile) {
        $db->exec("INSERT INTO users (telegram_id) VALUES ($user_id)");
        $profile = $db->querySingle("SELECT * FROM users WHERE telegram_id = $user_id", true);
    }
    
    $text = "{$emoji['profile']} <b>پروفایل شما</b>\n\n";
    $text .= "<b>نام:</b> " . ($profile['name'] ?? 'ثبت نشده') . "\n";
    $text .= "<b>مهارت‌ها:</b> " . ($profile['skills'] ?? 'ثبت نشده') . "\n";
    $text .= "<b>درباره شما:</b> " . ($profile['bio'] ?? 'ثبت نشده') . "\n\n";
    $text .= "برای ویرایش هر بخش، آن را به فرمت زیر ارسال کنید:\n";
    $text .= "نام: نام شما\nمهارت‌ها: مهارت‌های شما\nدرباره: توضیح درباره شما";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "{$emoji['back']} بازگشت", 'callback_data' => 'main_menu']
            ]
        ]
    ];
    
    sendTelegramMessage($chat_id, $text, $keyboard);
}

function showProjects($chat_id, $user_id, $db) {
    global $emoji;
    $projects = $db->query("SELECT * FROM projects WHERE is_active = 1 AND current_members < max_members");
    
    $text = "{$emoji['project']} <b>پروژه‌های فعال</b>\n\n";
    $has_projects = false;
    $keyboard = [];
    
    while ($project = $projects->fetchArray()) {
        $has_projects = true;
        $text .= "<b>{$project['title']}</b>\n";
        $text .= "{$emoji['team']} ظرفیت: {$project['current_members']}/{$project['max_members']}\n";
        $text .= "مهارت‌های مورد نیاز: {$project['required_skills']}\n\n";
        
        $keyboard[] = [
            [
                'text' => "عضویت در {$project['title']}",
                'callback_data' => "join_project_{$project['id']}"
            ]
        ];
    }
    
    if (!$has_projects) {
        $text .= "در حال حاضر هیچ پروژه فعالی وجود ندارد.";
    }
    
    $keyboard[] = [['text' => "{$emoji['back']} بازگشت", 'callback_data' => 'main_menu']];
    
    sendTelegramMessage($chat_id, $text, ['inline_keyboard' => $keyboard]);
}

function joinProject($user_id, $project_id, $db) {
    global $emoji;
    
    // بررسی آیا کاربر قبلا عضو شده
    $is_member = $db->querySingle("SELECT 1 FROM user_projects WHERE user_id = (SELECT id FROM users WHERE telegram_id = $user_id) AND project_id = $project_id");
    if ($is_member) {
        return "شما قبلا در این پروژه عضو شده‌اید!";
    }
    
    // بررسی ظرفیت پروژه
    $project = $db->querySingle("SELECT max_members, current_members FROM projects WHERE id = $project_id", true);
    if ($project['current_members'] >= $project['max_members']) {
        return "ظرفیت این پروژه تکمیل شده است!";
    }
    
    // افزودن کاربر به پروژه
    $db->exec("BEGIN");
    try {
        $db->exec("INSERT INTO user_projects (user_id, project_id) VALUES ((SELECT id FROM users WHERE telegram_id = $user_id), $project_id)");
        $db->exec("UPDATE projects SET current_members = current_members + 1 WHERE id = $project_id");
        $db->exec("COMMIT");
        return "{$emoji['success']} شما با موفقیت به پروژه اضافه شدید!";
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        return "{$emoji['error']} خطا در ثبت نام! لطفا مجددا تلاش کنید.";
    }
}

// ==================== توابع مدیریتی ====================
function handleAdminCommand($chat_id, $user_id, $text, $db) {
    global $emoji;
    $parts = explode(' ', $text, 2);
    $command = $parts[1] ?? '';
    
    switch ($command) {
        case 'stats':
            showAdminStats($chat_id, $db);
            break;
            
        case 'add_project':
            sendTelegramMessage($chat_id, "{$emoji['add']} لطفا اطلاعات پروژه را به فرمت زیر ارسال کنید:\n\nعنوان|توضیحات|مهارت‌های مورد نیاز|تعداد اعضا");
            break;
            
        case 'list_projects':
            listProjects($chat_id, $db);
            break;
            
        case '':
            showAdminMenu($chat_id);
            break;
            
        default:
            sendTelegramMessage($chat_id, "{$emoji['error']} دستور نامعتبر!");
    }
}

function showAdminStats($chat_id, $db) {
    global $emoji;
    
    $users = $db->querySingle("SELECT COUNT(*) FROM users");
    $projects = $db->querySingle("SELECT COUNT(*) FROM projects");
    $active_projects = $db->querySingle("SELECT COUNT(*) FROM projects WHERE is_active = 1");
    
    $text = "{$emoji['stats']} <b>آمار ربات</b>\n\n";
    $text .= "{$emoji['user']} <b>کاربران:</b> $users\n";
    $text .= "{$emoji['project']} <b>پروژه‌ها:</b> $projects\n";
    $text .= "{$emoji['team']} <b>پروژه‌های فعال:</b> $active_projects";
    
    sendTelegramMessage($chat_id, $text);
}

function listProjects($chat_id, $db) {
    global $emoji;
    $projects = $db->query("SELECT * FROM projects ORDER BY is_active DESC");
    
    $text = "{$emoji['list']} <b>لیست پروژه‌ها</b>\n\n";
    $keyboard = [];
    
    while ($project = $projects->fetchArray()) {
        $status = $project['is_active'] ? "{$emoji['success']} فعال" : "{$emoji['error']} غیرفعال";
        $text .= "<b>{$project['title']}</b> ($status)\n";
        $text .= "ظرفیت: {$project['current_members']}/{$project['max_members']}\n\n";
        
        $keyboard[] = [
            [
                'text' => ($project['is_active'] ? "غیرفعال کردن" : "فعال کردن") . " {$project['title']}",
                'callback_data' => "toggle_project_{$project['id']}"
            ]
        ];
    }
    
    $keyboard[] = [['text' => "{$emoji['back']} بازگشت", 'callback_data' => 'admin_menu']];
    
    sendTelegramMessage($chat_id, $text, ['inline_keyboard' => $keyboard]);
}

function showAdminMenu($chat_id) {
    global $emoji;
    
    $text = "{$emoji['admin']} <b>پنل مدیریت</b>\n\n";
    $text .= "{$emoji['stats']} /admin stats - نمایش آمار\n";
    $text .= "{$emoji['add']} /admin add_project - افزودن پروژه جدید\n";
    $text .= "{$emoji['list']} /admin list_projects - مدیریت پروژه‌ها";
    
    sendTelegramMessage($chat_id, $text);
}

// ==================== پنل وب ====================
function handleWebPanel() {
    global $emoji;
    
    if ($_SERVER['REQUEST_URI'] === '/admin' && isset($_GET['password'])) {
        if ($_GET['password'] !== WEB_PANEL_PASSWORD) {
            die("{$emoji['lock']} دسترسی غیرمجاز!");
        }
        
        $db = initDB();
        
        // آمار کلی
        $users = $db->querySingle("SELECT COUNT(*) FROM users");
        $projects = $db->querySingle("SELECT COUNT(*) FROM projects");
        $active_projects = $db->querySingle("SELECT COUNT(*) FROM projects WHERE is_active = 1");
        
        // آخرین کاربران
        $last_users = $db->query("SELECT name, username, created_at FROM users ORDER BY id DESC LIMIT 5");
        
        // آخرین پروژه‌ها
        $last_projects = $db->query("SELECT title, current_members, max_members FROM projects ORDER BY id DESC LIMIT 5");
        
        echo "<!DOCTYPE html>
        <html dir='rtl'>
        <head>
            <meta charset='UTF-8'>
            <title>پنل مدیریت ربات</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
                .container { max-width: 1200px; margin: 0 auto; }
                .header { background-color: #4CAF50; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .stats { display: flex; gap: 15px; margin-bottom: 20px; }
                .stat-card { background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex: 1; text-align: center; }
                .table-container { background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 12px; text-align: right; border-bottom: 1px solid #ddd; }
                th { background-color: #f2f2f2; }
                .progress-bar { height: 20px; background-color: #e0e0e0; border-radius: 10px; margin-top: 5px; }
                .progress { height: 100%; background-color: #4CAF50; border-radius: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>پنل مدیریت ربات تلگرام</h1>
                </div>
                
                <div class='stats'>
                    <div class='stat-card'>
                        <h3>کاربران</h3>
                        <p>$users</p>
                    </div>
                    <div class='stat-card'>
                        <h3>پروژه‌ها</h3>
                        <p>$projects</p>
                    </div>
                    <div class='stat-card'>
                        <h3>پروژه‌های فعال</h3>
                        <p>$active_projects</p>
                    </div>
                </div>
                
                <div class='table-container'>
                    <h2>آخرین کاربران</h2>
                    <table>
                        <tr><th>نام</th><th>نام کاربری</th><th>تاریخ عضویت</th></tr>";
                        
                        while ($user = $last_users->fetchArray()) {
                            echo "<tr>
                                <td>{$user['name']}</td>
                                <td>@{$user['username']}</td>
                                <td>{$user['created_at']}</td>
                            </tr>";
                        }
                        
        echo "      </table>
                </div>
                
                <div class='table-container'>
                    <h2>آخرین پروژه‌ها</h2>
                    <table>
                        <tr><th>عنوان</th><th>ظرفیت</th><th>پیشرفت</th></tr>";
                        
                        while ($project = $last_projects->fetchArray()) {
                            $percent = ($project['current_members'] / $project['max_members']) * 100;
                            echo "<tr>
                                <td>{$project['title']}</td>
                                <td>{$project['current_members']}/{$project['max_members']}</td>
                                <td>
                                    <div class='progress-bar'>
                                        <div class='progress' style='width: {$percent}%'></div>
                                    </div>
                                </td>
                            </tr>";
                        }
                        
        echo "      </table>
                </div>
            </div>
        </body>
        </html>";
        
        $db->close();
        exit;
    }
}

// ==================== مدیریت اصلی ربات ====================
function handleRequest($update) {
    try {
        $db = initDB();
        
        // بررسی پنل وب
        if (php_sapi_name() === 'cli-server') {
            handleWebPanel();
        }
        
        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
        $chat_id = $message['chat']['id'] ?? null;
        $user_id = $update['callback_query']['from']['id'] ?? $message['from']['id'] ?? null;
        $text = $update['callback_query']['data'] ?? $message['text'] ?? '';
        
        if (!$chat_id || !$user_id) return;
        
        // مدیریت callback_query
        if (isset($update['callback_query'])) {
            $callback_data = $update['callback_query']['data'];
            
            if ($callback_data === 'main_menu') {
                showMainMenu($chat_id, $user_id, $db);
            }
            elseif ($callback_data === 'admin_menu') {
                showAdminMenu($chat_id);
            }
            elseif (strpos($callback_data, 'join_project_') === 0) {
                $project_id = str_replace('join_project_', '', $callback_data);
                $result = joinProject($user_id, $project_id, $db);
                sendTelegramMessage($chat_id, $result);
            }
            elseif (strpos($callback_data, 'toggle_project_') === 0) {
                $project_id = str_replace('toggle_project_', '', $callback_data);
                $db->exec("UPDATE projects SET is_active = NOT is_active WHERE id = $project_id");
                sendTelegramMessage($chat_id, "وضعیت پروژه با موفقیت تغییر کرد!");
                listProjects($chat_id, $db);
            }
            
            return;
        }
        
        // دستورات عمومی
        if (strpos($text, '/start') === 0) {
            showMainMenu($chat_id, $user_id, $db);
        }
        elseif (strpos($text, '/profile') === 0) {
            handleProfile($chat_id, $user_id, $db);
        }
        elseif (strpos($text, '/projects') === 0) {
            showProjects($chat_id, $user_id, $db);
        }
        // دستورات ادمین
        elseif (strpos($text, '/admin') === 0 && $db->querySingle("SELECT is_admin FROM users WHERE telegram_id = $user_id")) {
            handleAdminCommand($chat_id, $user_id, $text, $db);
        }
        // ایجاد/ویرایش پروفایل
        elseif (preg_match('/^(نام|مهارت‌ها|درباره):(.+)/u', $text, $matches)) {
            $field = trim($matches[1]);
            $value = trim($matches[2]);
            
            $field_map = ['نام' => 'name', 'مهارت‌ها' => 'skills', 'درباره' => 'bio'];
            $db_field = $field_map[$field] ?? null;
            
            if ($db_field) {
                $stmt = $db->prepare("UPDATE users SET $db_field = :value WHERE telegram_id = :id");
                $stmt->bindValue(':value', $value);
                $stmt->bindValue(':id', $user_id);
                $stmt->execute();
                
                sendTelegramMessage($chat_id, "✅ بخش <b>$field</b> با موفقیت به‌روزرسانی شد!");
                handleProfile($chat_id, $user_id, $db);
            }
        }
        // افزودن پروژه جدید (ادمین)
        elseif (strpos($text, '|') !== false && $db->querySingle("SELECT is_admin FROM users WHERE telegram_id = $user_id")) {
            $parts = explode('|', $text);
            if (count($parts) >= 4) {
                $stmt = $db->prepare("INSERT INTO projects (title, description, required_skills, max_members) VALUES (?, ?, ?, ?)");
                $stmt->bindValue(1, trim($parts[0]));
                $stmt->bindValue(2, trim($parts[1]));
                $stmt->bindValue(3, trim($parts[2]));
                $stmt->bindValue(4, (int)trim($parts[3]));
                $stmt->execute();
                
                sendTelegramMessage($chat_id, "✅ پروژه جدید با موفقیت اضافه شد!");
            }
        }
        // پیام‌های متنی دیگر
        else {
            showMainMenu($chat_id, $user_id, $db);
        }
        
    } catch (Exception $e) {
        file_put_contents('bot_errors.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
        sendTelegramMessage($chat_id, "⚠️ خطایی در سیستم رخ داده است. لطفا مجددا تلاش کنید.");
    } finally {
        if (isset($db)) $db->close();
    }
}

// ==================== اجرای ربات ====================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    handleRequest($update);
} elseif (php_sapi_name() === 'cli-server') {
    handleWebPanel();
}
?>

<?php
// auth.php — تسجيل الدخول عبر Google (بدون قاعدة بيانات، عبر الجلسة)
session_start();
header('Content-Type: application/json; charset=utf-8');

$CLIENT_ID = getenv('GOOGLE_CLIENT_ID');
$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ($_GET['action'] ?? 'me');

// التحقق من توكن جوجل وتسجيل الدخول
if ($action === 'verify') {
    $token = $input['credential'] ?? '';
    if (!$token) {
        echo json_encode(['error' => 'لا يوجد رمز دخول'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تحقّق من صحة التوكن عبر جوجل
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $p = json_decode($res, true);
    if ($code !== 200 || empty($p['sub'])) {
        echo json_encode(['error' => 'رمز الدخول غير صالح'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // تأكّد إنه التوكن صادر لتطبيقك
    if ($CLIENT_ID && ($p['aud'] ?? '') !== $CLIENT_ID) {
        echo json_encode(['error' => 'عدم تطابق معرّف التطبيق'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['user'] = [
        'name'    => $p['name']    ?? '',
        'email'   => $p['email']   ?? '',
        'picture' => $p['picture'] ?? '',
        'sub'     => $p['sub'],
    ];
    echo json_encode(['user' => $_SESSION['user']], JSON_UNESCAPED_UNICODE);
    exit;
}

// تسجيل الخروج
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// معرفة المستخدم الحالي
echo json_encode(['user' => $_SESSION['user'] ?? null], JSON_UNESCAPED_UNICODE);

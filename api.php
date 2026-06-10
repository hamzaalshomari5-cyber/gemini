<?php
// api.php — وسيط الاتصال مع Gemini (محادثة + توليد صور)
header('Content-Type: application/json; charset=utf-8');

// ===================== الإعدادات =====================
$API_KEY     = getenv('GEMINI_API_KEY');      // ضع المفتاح في Variables على Railway
$TEXT_MODEL  = 'gemini-2.5-flash';            // موديل المحادثة
$IMAGE_MODEL = 'gemini-2.5-flash-image';      // موديل الصور (بدّله لـ gemini-3.1-flash-image للأحدث)
$IMAGE_MODEL_FALLBACK = 'gemini-3.1-flash-image-preview'; // موديل بديل وقت الازدحام
// =====================================================

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'مفتاح GEMINI_API_KEY غير مضبوط في متغيرات البيئة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'chat';

function callGemini($model, $payload, $apiKey, $retries = 3) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    for ($attempt = 0; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // إعادة المحاولة عند الازدحام المؤقت (503/429)
        if (!$err && ($code === 503 || $code === 429) && $attempt < $retries) {
            sleep(2);
            continue;
        }
        return [$res, $code, $err];
    }
}

// ===================== توليد الصور =====================
if ($action === 'image') {
    $prompt = trim($input['prompt'] ?? '');
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'اكتب وصف الصورة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بناء الأجزاء: نص + صورة مرفوعة (اختياري) لتعديلها
    $parts = [['text' => $prompt]];
    if (!empty($input['image']['data'])) {
        $parts[] = ['inlineData' => [
            'mimeType' => $input['image']['mime'] ?? 'image/png',
            'data'     => $input['image']['data'],
        ]];
    }

    $payload = [
        'contents'         => [['parts' => $parts]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
    ];

    list($res, $code, $err) = callGemini($IMAGE_MODEL, $payload, $API_KEY);

    // لو الموديل ضل مزحوم بعد المحاولات، جرّب الموديل البديل
    if (!$err && ($code === 503 || $code === 429)) {
        list($res2, $code2, $err2) = callGemini($IMAGE_MODEL_FALLBACK, $payload, $API_KEY);
        if (!$err2 && $code2 === 200) { $res = $res2; $code = $code2; $err = $err2; }
    }

    if ($err) {
        http_response_code(500);
        echo json_encode(['error' => 'خطأ بالاتصال: ' . $err], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = json_decode($res, true);
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? 'خطأ غير معروف';
        if ($code === 503 || $code === 429) {
            $msg = 'الموديل مزحوم حالياً 😅 جرّب كمان دقيقة.';
        }
        http_response_code($code);
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $imageData = null; $mime = 'image/png'; $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        if (isset($part['inlineData']['data'])) {
            $imageData = $part['inlineData']['data'];
            $mime      = $part['inlineData']['mimeType'] ?? 'image/png';
        } elseif (isset($part['text'])) {
            $text .= $part['text'];
        }
    }

    if (!$imageData) {
        http_response_code(500);
        echo json_encode(['error' => 'ما رجعت صورة، جرّب وصف أوضح'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'image' => "data:{$mime};base64,{$imageData}",
        'text'  => $text,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== المحادثة =====================
$messages = $input['messages'] ?? [];
$contents = [];
foreach ($messages as $m) {
    $role       = (($m['role'] ?? '') === 'assistant') ? 'model' : 'user';
    $contents[] = ['role' => $role, 'parts' => [['text' => $m['content'] ?? '']]];
}

if (empty($contents)) {
    http_response_code(400);
    echo json_encode(['error' => 'لا توجد رسالة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// كشف طلب الصورة تلقائياً ضمن المحادثة العادية
$lastUser = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if ((($messages[$i]['role'] ?? '') !== 'assistant')) { $lastUser = $messages[$i]['content'] ?? ''; break; }
}
$imageWords = ['ارسم', 'إرسم', 'ارسملي', 'رسمة', 'صورة', 'صوره', 'صورّ', 'سويلي', 'اعمللي صورة', 'اعملي صورة', 'draw', 'image', 'picture', 'generate image', 'paint'];
$wantsImage = false;
foreach ($imageWords as $w) {
    if (function_exists('mb_stripos') ? (mb_stripos($lastUser, $w) !== false) : (stripos($lastUser, $w) !== false)) {
        $wantsImage = true; break;
    }
}

if ($wantsImage && $lastUser !== '') {
    $payload = [
        'contents'         => [['parts' => [['text' => $lastUser]]]],
        'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
    ];
    list($res, $code, $err) = callGemini($IMAGE_MODEL, $payload, $API_KEY);
    if (!$err && $code === 200) {
        $data = json_decode($res, true);
        $imageData = null; $mime = 'image/png'; $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['data'])) {
                $imageData = $part['inlineData']['data'];
                $mime      = $part['inlineData']['mimeType'] ?? 'image/png';
            } elseif (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        if ($imageData) {
            echo json_encode([
                'reply' => $text,
                'image' => "data:{$mime};base64,{$imageData}",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // إذا فشل توليد الصورة، بيكمل كمحادثة نصية عادية تحت
}

list($res, $code, $err) = callGemini($TEXT_MODEL, ['contents' => $contents], $API_KEY);
if ($err) {
    http_response_code(500);
    echo json_encode(['error' => 'خطأ بالاتصال: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($res, true);
if ($code !== 200) {
    http_response_code($code);
    echo json_encode(['error' => $data['error']['message'] ?? 'خطأ غير معروف'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply = '';
foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
    if (isset($part['text'])) $reply .= $part['text'];
}

echo json_encode(['reply' => $reply !== '' ? $reply : '...'], JSON_UNESCAPED_UNICODE);

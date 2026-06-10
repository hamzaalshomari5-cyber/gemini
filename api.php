<?php
// api.php — وسيط Gemini (محادثة) + Pollinations (توليد صور مجاني بدون فوترة)
header('Content-Type: application/json; charset=utf-8');

// ===================== الإعدادات =====================
$API_KEY     = getenv('GEMINI_API_KEY');   // مفتاح Gemini (للمحادثة) من Railway Variables
$TEXT_MODEL  = 'gemini-2.5-flash';          // موديل المحادثة
$IMAGE_MODEL = 'gemini-2.5-flash-image';    // يُستخدم فقط لتعديل صورة مرفوعة
// =====================================================

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'مفتاح GEMINI_API_KEY غير مضبوط في متغيرات البيئة'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'chat';

// نداء Gemini مع إعادة محاولة عند الازدحام
function callGemini($model, $payload, $apiKey, $retries = 2) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    for ($attempt = 0; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!$err && ($code === 503 || $code === 429) && $attempt < $retries) { sleep(2); continue; }
        return [$res, $code, $err];
    }
}

// تحويل الوصف العربي إلى prompt إنجليزي مختصر (لتحسين جودة الصورة)
function toEnglishPrompt($text, $apiKey, $model) {
    $q = "Convert the following request into one concise English image-generation prompt. "
       . "Output ONLY the prompt, no quotes, no explanation:\n\n" . $text;
    list($res, $code, $err) = callGemini($model, ['contents' => [['parts' => [['text' => $q]]]]], $apiKey);
    if (!$err && $code === 200) {
        $data = json_decode($res, true);
        $t = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) {
            if (isset($p['text'])) $t .= $p['text'];
        }
        $t = trim($t);
        if ($t !== '') return $t;
    }
    return $text; // إذا فشلت الترجمة، استعمل النص الأصلي
}

// توليد صورة عبر Pollinations (مجاني، بدون مفتاح)
function pollinationsImage($prompt) {
    $endpoints = [
        'https://image.pollinations.ai/prompt/' . rawurlencode($prompt) . '?width=1024&height=1024&model=flux',
        'https://gen.pollinations.ai/image/' . rawurlencode($prompt) . '?width=1024&height=1024&model=flux',
    ];
    $lastCode = 0;
    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0', 'Accept: image/*'],
        ]);
        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $lastCode = $code;
        if ($code === 200 && $body && strpos($ctype, 'image') !== false) {
            return ['data:' . $ctype . ';base64,' . base64_encode($body), null];
        }
    }
    return [null, 'تعذّر توليد الصورة (كود ' . $lastCode . ')، جرّب كمان مرة'];
}

// ===================== توليد / تعديل صورة =====================
if ($action === 'image') {
    $prompt = trim($input['prompt'] ?? '');
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'اكتب وصف الصورة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تعديل صورة مرفوعة → عبر Gemini (بدّو تفعيل فوترة)
    if (!empty($input['image']['data'])) {
        $payload = [
            'contents' => [['parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => $input['image']['mime'] ?? 'image/png', 'data' => $input['image']['data']]],
            ]]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ];
        list($res, $code, $err) = callGemini($IMAGE_MODEL, $payload, $API_KEY);
        $data = json_decode($res, true);
        if (!$err && $code === 200) {
            $imageData = null; $mime = 'image/png'; $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData']['data'])) { $imageData = $part['inlineData']['data']; $mime = $part['inlineData']['mimeType'] ?? 'image/png'; }
                elseif (isset($part['text'])) { $text .= $part['text']; }
            }
            if ($imageData) {
                echo json_encode(['image' => "data:{$mime};base64,{$imageData}", 'text' => $text], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        echo json_encode(['error' => 'تعديل الصور بدّو تفعيل الفوترة (Billing) على حساب Google'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // توليد صورة جديدة → Pollinations (مجاني)
    $eng = toEnglishPrompt($prompt, $API_KEY, $TEXT_MODEL);
    list($img, $perr) = pollinationsImage($eng);
    if (!$img) { http_response_code(503); echo json_encode(['error' => $perr], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['image' => $img], JSON_UNESCAPED_UNICODE);
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

// كشف طلب صورة ضمن المحادثة العادية
$lastUser = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if ((($messages[$i]['role'] ?? '') !== 'assistant')) { $lastUser = $messages[$i]['content'] ?? ''; break; }
}
$imageWords = ['ارسم', 'إرسم', 'ارسملي', 'رسمة', 'رسملي', 'صورة', 'صوره', 'صورّ', 'سويلي صورة', 'اعملي صورة', 'اعمللي صورة', 'draw', 'image', 'picture', 'paint'];
$wantsImage = false;
foreach ($imageWords as $w) {
    if (function_exists('mb_stripos') ? (mb_stripos($lastUser, $w) !== false) : (stripos($lastUser, $w) !== false)) { $wantsImage = true; break; }
}

if ($wantsImage && $lastUser !== '') {
    $eng = toEnglishPrompt($lastUser, $API_KEY, $TEXT_MODEL);
    list($img, $perr) = pollinationsImage($eng);
    if ($img) { echo json_encode(['image' => $img], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['reply' => '⚠️ ' . $perr], JSON_UNESCAPED_UNICODE);
    exit;
}

list($res, $code, $err) = callGemini($TEXT_MODEL, ['contents' => $contents], $API_KEY);
if ($err) { http_response_code(500); echo json_encode(['error' => 'خطأ بالاتصال: ' . $err], JSON_UNESCAPED_UNICODE); exit; }
$data = json_decode($res, true);
if ($code !== 200) { http_response_code($code); echo json_encode(['error' => $data['error']['message'] ?? 'خطأ غير معروف'], JSON_UNESCAPED_UNICODE); exit; }
$reply = '';
foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) { if (isset($part['text'])) $reply .= $part['text']; }
echo json_encode(['reply' => $reply !== '' ? $reply : '...'], JSON_UNESCAPED_UNICODE);

<?php
// api.php — محرّكات محادثة متعددة + توليد/تعديل صور عبر Cloudflare
header('Content-Type: application/json; charset=utf-8');

// ===================== الإعدادات =====================
$API_KEY     = getenv('GEMINI_API_KEY');     // Gemini (محادثة + ترجمة وصف الصور)
$TEXT_MODEL  = 'gemini-2.5-flash';
$CF_ACCOUNT  = getenv('CF_ACCOUNT_ID');       // Cloudflare (صور + محادثة Llama)
$CF_TOKEN    = getenv('CF_API_TOKEN');
$CF_CHAT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

$ANTHROPIC_KEY   = getenv('ANTHROPIC_API_KEY');  $CLAUDE_MODEL     = 'claude-sonnet-4-6';
$GROQ_KEY        = getenv('GROQ_API_KEY');       $GROQ_MODEL       = 'llama-3.3-70b-versatile';
$OPENROUTER_KEY  = getenv('OPENROUTER_API_KEY'); $OPENROUTER_MODEL = 'meta-llama/llama-3.3-70b-instruct:free';
// =====================================================

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['error' => 'مفتاح GEMINI_API_KEY غير مضبوط'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = []; }
$action = $input['action'] ?? ($_GET['action'] ?? 'chat');

// قائمة المحرّكات المفعّلة (يلي عندها مفاتيح)
if ($action === 'engines') {
    $list = ['gemini'];
    if ($CF_ACCOUNT && $CF_TOKEN) $list[] = 'cloudflare';
    if ($GROQ_KEY)               $list[] = 'groq';
    if ($OPENROUTER_KEY)         $list[] = 'openrouter';
    if ($ANTHROPIC_KEY)          $list[] = 'claude';
    echo json_encode(['engines' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- دوال مساعدة ---------- */
function callGemini($model, $payload, $apiKey, $retries = 2) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    for ($attempt = 0; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120,
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        curl_close($ch);
        if (!$err && ($code === 503 || $code === 429) && $attempt < $retries) { sleep(2); continue; }
        return [$res, $code, $err];
    }
}

// تحويل رسائل المحادثة لصيغة موحّدة (user/assistant)
function normMsgs($messages) {
    $out = [];
    foreach ($messages as $m) {
        $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
        $out[] = ['role' => $role, 'content' => (string)($m['content'] ?? '')];
    }
    return $out;
}

// نداء أي خدمة متوافقة مع OpenAI (Groq / OpenRouter)
function callOAI($url, $key, $model, $messages, $extra = []) {
    $headers = array_merge(['Authorization: Bearer ' . $key, 'Content-Type: application/json'], $extra);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'messages' => normMsgs($messages), 'max_tokens' => 1024], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return [$res, $code, $err];
}

// إخراج رد OpenAI-style
function emitOAI($res, $code, $err, $label) {
    if ($err) { http_response_code(500); echo json_encode(['error' => 'خطأ بالاتصال بـ ' . $label . ': ' . $err], JSON_UNESCAPED_UNICODE); exit; }
    $data = json_decode($res, true);
    if ($code !== 200) { http_response_code($code); echo json_encode(['error' => $data['error']['message'] ?? ($label . ' كود ' . $code)], JSON_UNESCAPED_UNICODE); exit; }
    $reply = $data['choices'][0]['message']['content'] ?? '';
    echo json_encode(['reply' => $reply !== '' ? $reply : '...'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Claude (Anthropic)
function callClaude($messages, $key, $model) {
    $payload = ['model' => $model, 'max_tokens' => 1024, 'messages' => normMsgs($messages)];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return [$res, $code, $err];
}

// محادثة Cloudflare (Llama)
function cfChat($messages, $account, $token, $model) {
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/{$model}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['messages' => normMsgs($messages)], JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return [$res, $code, $err];
}

// ترجمة وصف عربي لـ prompt إنجليزي (للصور)
function toEnglishPrompt($text, $apiKey, $model) {
    $q = "Convert the following request into one concise English image-generation prompt. Output ONLY the prompt, no quotes:\n\n" . $text;
    list($res, $code, $err) = callGemini($model, ['contents' => [['parts' => [['text' => $q]]]]], $apiKey);
    if (!$err && $code === 200) {
        $data = json_decode($res, true); $t = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $p) { if (isset($p['text'])) $t .= $p['text']; }
        $t = trim($t); if ($t !== '') return $t;
    }
    return $text;
}

// توليد صورة (Cloudflare FLUX)
function cfImage($prompt, $account, $token) {
    if (!$account || !$token) return [null, 'لازم تضيف CF_ACCOUNT_ID و CF_API_TOKEN على Railway'];
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/@cf/black-forest-labs/flux-1-schnell";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'steps' => 4]), CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = json_decode($res, true);
    if ($code === 200 && !empty($data['result']['image'])) return ['data:image/jpeg;base64,' . $data['result']['image'], null];
    $msg = $data['errors'][0]['message'] ?? ('كود ' . $code);
    return [null, 'تعذّر توليد الصورة (' . $msg . ')'];
}

// تعديل صورة مرفوعة (Cloudflare img2img)
function cfImageEdit($prompt, $imageBinary, $account, $token) {
    if (!$account || !$token) return [null, 'لازم تضيف CF_ACCOUNT_ID و CF_API_TOKEN على Railway'];
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/@cf/runwayml/stable-diffusion-v1-5-img2img";
    $bytes = array_values(unpack('C*', $imageBinary));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'image' => $bytes, 'strength' => 0.65, 'num_steps' => 20]),
        CURLOPT_TIMEOUT => 180,
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code === 200 && strpos($ctype, 'image') !== false) return ['data:' . $ctype . ';base64,' . base64_encode($res), null];
    $data = json_decode($res, true);
    return [null, 'تعذّر تعديل الصورة (' . ($data['errors'][0]['message'] ?? ('كود ' . $code)) . ')'];
}

/* ---------- توليد / تعديل الصور ---------- */
if ($action === 'image') {
    $prompt = trim($input['prompt'] ?? '');
    if ($prompt === '') { http_response_code(400); echo json_encode(['error' => 'اكتب وصف الصورة'], JSON_UNESCAPED_UNICODE); exit; }

    if (!empty($input['image']['data'])) {
        $binary = base64_decode($input['image']['data']);
        if (!$binary) { http_response_code(400); echo json_encode(['error' => 'الصورة غير صالحة'], JSON_UNESCAPED_UNICODE); exit; }
        $eng = toEnglishPrompt($prompt, $API_KEY, $TEXT_MODEL);
        list($img, $perr) = cfImageEdit($eng, $binary, $CF_ACCOUNT, $CF_TOKEN);
        if (!$img) { http_response_code(503); echo json_encode(['error' => $perr], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['image' => $img], JSON_UNESCAPED_UNICODE); exit;
    }

    $eng = toEnglishPrompt($prompt, $API_KEY, $TEXT_MODEL);
    list($img, $perr) = cfImage($eng, $CF_ACCOUNT, $CF_TOKEN);
    if (!$img) { http_response_code(503); echo json_encode(['error' => $perr], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['image' => $img], JSON_UNESCAPED_UNICODE); exit;
}

/* ---------- المحادثة ---------- */
$messages = $input['messages'] ?? [];
$contents = [];
foreach ($messages as $m) {
    $role = (($m['role'] ?? '') === 'assistant') ? 'model' : 'user';
    $contents[] = ['role' => $role, 'parts' => [['text' => $m['content'] ?? '']]];
}
if (empty($contents)) { http_response_code(400); echo json_encode(['error' => 'لا توجد رسالة'], JSON_UNESCAPED_UNICODE); exit; }

// كشف طلب صورة ضمن المحادثة (يولّد عبر Cloudflare مهما كان المحرّك)
$lastUser = '';
for ($i = count($messages) - 1; $i >= 0; $i--) {
    if ((($messages[$i]['role'] ?? '') !== 'assistant')) { $lastUser = $messages[$i]['content'] ?? ''; break; }
}
$imageWords = ['ارسم', 'إرسم', 'ارسملي', 'رسمة', 'رسملي', 'صورة', 'صوره', 'صورّ', 'سويلي صورة', 'اعملي صورة', 'اعمللي صورة', 'draw', 'image', 'picture', 'paint'];
$wantsImage = false;
foreach ($imageWords as $w) {
    if (function_exists('mb_stripos') ? (mb_stripos($lastUser, $w) !== false) : (stripos($lastUser, $w) !== false)) { $wantsImage = true; break; }
}
if (!empty($input['multi'])) { $wantsImage = false; }
if ($wantsImage && $lastUser !== '') {
    $eng = toEnglishPrompt($lastUser, $API_KEY, $TEXT_MODEL);
    list($img, $perr) = cfImage($eng, $CF_ACCOUNT, $CF_TOKEN);
    if ($img) { echo json_encode(['image' => $img], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['reply' => '⚠️ ' . $perr], JSON_UNESCAPED_UNICODE); exit;
}

// تشغيل محرّك واحد — يرجّع [الرد أو null، رسالة الخطأ، الكود]
function runEngine($engine, $messages, $contents, $cfg) {
    switch ($engine) {
        case 'gemini':
            list($res, $code, $err) = callGemini($cfg['TEXT_MODEL'], ['contents' => $contents], $cfg['API_KEY']);
            if ($err) return [null, $err, 0];
            $d = json_decode($res, true);
            if ($code !== 200) return [null, $d['error']['message'] ?? 'gemini', $code];
            $r = ''; foreach ($d['candidates'][0]['content']['parts'] ?? [] as $p) { if (isset($p['text'])) $r .= $p['text']; }
            return [$r !== '' ? $r : null, null, 200];
        case 'groq':
            if (!$cfg['GROQ_KEY']) return [null, 'no-key', 0];
            list($res, $code, $err) = callOAI('https://api.groq.com/openai/v1/chat/completions', $cfg['GROQ_KEY'], $cfg['GROQ_MODEL'], $messages);
            return oaiReply($res, $code, $err);
        case 'openrouter':
            if (!$cfg['OPENROUTER_KEY']) return [null, 'no-key', 0];
            list($res, $code, $err) = callOAI('https://openrouter.ai/api/v1/chat/completions', $cfg['OPENROUTER_KEY'], $cfg['OPENROUTER_MODEL'], $messages, ['HTTP-Referer: https://railway.app', 'X-Title: AI Assistant']);
            return oaiReply($res, $code, $err);
        case 'cloudflare':
            if (!$cfg['CF_ACCOUNT'] || !$cfg['CF_TOKEN']) return [null, 'no-key', 0];
            list($res, $code, $err) = cfChat($messages, $cfg['CF_ACCOUNT'], $cfg['CF_TOKEN'], $cfg['CF_CHAT_MODEL']);
            if ($err) return [null, $err, 0];
            $d = json_decode($res, true);
            if ($code !== 200) return [null, $d['errors'][0]['message'] ?? 'cloudflare', $code];
            $r = $d['result']['response'] ?? '';
            return [$r !== '' ? $r : null, null, 200];
        case 'claude':
            if (!$cfg['ANTHROPIC_KEY']) return [null, 'no-key', 0];
            list($res, $code, $err) = callClaude($messages, $cfg['ANTHROPIC_KEY'], $cfg['CLAUDE_MODEL']);
            if ($err) return [null, $err, 0];
            $d = json_decode($res, true);
            if ($code !== 200) return [null, $d['error']['message'] ?? 'claude', $code];
            $r = ''; foreach ($d['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') $r .= $b['text']; }
            return [$r !== '' ? $r : null, null, 200];
    }
    return [null, 'unknown', 0];
}
function oaiReply($res, $code, $err) {
    if ($err) return [null, $err, 0];
    $d = json_decode($res, true);
    if ($code !== 200) return [null, $d['error']['message'] ?? 'oai', $code];
    $r = $d['choices'][0]['message']['content'] ?? '';
    return [$r !== '' ? $r : null, null, 200];
}

$cfg = [
    'API_KEY' => $API_KEY, 'TEXT_MODEL' => $TEXT_MODEL,
    'CF_ACCOUNT' => $CF_ACCOUNT, 'CF_TOKEN' => $CF_TOKEN, 'CF_CHAT_MODEL' => $CF_CHAT_MODEL,
    'ANTHROPIC_KEY' => $ANTHROPIC_KEY, 'CLAUDE_MODEL' => $CLAUDE_MODEL,
    'GROQ_KEY' => $GROQ_KEY, 'GROQ_MODEL' => $GROQ_MODEL,
    'OPENROUTER_KEY' => $OPENROUTER_KEY, 'OPENROUTER_MODEL' => $OPENROUTER_MODEL,
];
$selected = $input['model'] ?? 'gemini';

// وضع المقارنة: شغّل المحرّك المطلوب لحالو بدون تبديل
if (!empty($input['multi'])) {
    list($reply, $err, $code) = runEngine($selected, $messages, $contents, $cfg);
    echo json_encode($reply !== null ? ['reply' => $reply, 'engine' => $selected] : ['reply' => '⚠️ ' . ($err ?: ('كود ' . $code))], JSON_UNESCAPED_UNICODE);
    exit;
}

// التبديل التلقائي: المحرّك المختار أولاً، وإذا فشل ينط للي بعده
$available = ['gemini'];
if ($CF_ACCOUNT && $CF_TOKEN) $available[] = 'cloudflare';
if ($GROQ_KEY)               $available[] = 'groq';
if ($OPENROUTER_KEY)         $available[] = 'openrouter';
if ($ANTHROPIC_KEY)          $available[] = 'claude';

$chain = [];
foreach (array_merge([$selected], $available) as $e) {
    if (in_array($e, $available, true) && !in_array($e, $chain, true)) $chain[] = $e;
}

$lastErr = '';
foreach ($chain as $eng) {
    list($reply, $err, $code) = runEngine($eng, $messages, $contents, $cfg);
    if ($reply !== null) {
        echo json_encode(['reply' => $reply, 'engine' => $eng], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $lastErr = $err ?: ('كود ' . $code);
}
echo json_encode(['error' => 'كل المحرّكات مشغولة حالياً (' . $lastErr . ') — جرّب بعد شوي'], JSON_UNESCAPED_UNICODE);

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>المساعد الذكي</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Segoe UI", Tahoma, sans-serif; }
  :root {
    --bg1: #0f1020; --bg2: #1a1530;
    --bubble-user: linear-gradient(135deg,#6d5dfc,#8b5cf6);
    --bubble-ai: #1e2235;
    --accent: #8b5cf6;
    --text: #eef0f7; --muted: #9aa0b5;
  }
  body {
    background: radial-gradient(1200px 600px at 80% -10%, #2a2150 0%, var(--bg1) 60%);
    color: var(--text); height: 100vh; display: flex; flex-direction: column; overflow: hidden;
  }
  header {
    padding: 16px 18px; text-align: center; font-size: 18px; font-weight: 700;
    border-bottom: 1px solid rgba(255,255,255,.07);
    background: rgba(255,255,255,.02); backdrop-filter: blur(8px);
  }
  header span { background: linear-gradient(90deg,#8b5cf6,#22d3ee); -webkit-background-clip: text; background-clip: text; color: transparent; }
  #chat {
    flex: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 14px;
  }
  #chat::-webkit-scrollbar { width: 7px; }
  #chat::-webkit-scrollbar-thumb { background: #3a3a55; border-radius: 8px; }
  .msg { max-width: 82%; padding: 12px 15px; border-radius: 18px; line-height: 1.7; white-space: pre-wrap; word-wrap: break-word; animation: pop .2s ease; font-size: 15px; }
  @keyframes pop { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; } }
  .user { align-self: flex-start; background: var(--bubble-user); border-bottom-right-radius: 5px; }
  .ai   { align-self: flex-end; background: var(--bubble-ai); border-bottom-left-radius: 5px; border: 1px solid rgba(255,255,255,.05); }
  .msg img { max-width: 100%; border-radius: 12px; margin-top: 6px; display: block; }
  .welcome { text-align: center; color: var(--muted); margin: auto; font-size: 15px; line-height: 2; }
  .typing { align-self: flex-end; color: var(--muted); font-size: 14px; padding: 6px 12px; }
  .dot { display:inline-block; width:7px;height:7px;margin:0 1px;border-radius:50%;background:var(--accent);animation:b 1.2s infinite; }
  .dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
  @keyframes b { 0%,60%,100%{opacity:.3;transform:translateY(0)} 30%{opacity:1;transform:translateY(-4px)} }
  footer {
    padding: 12px; border-top: 1px solid rgba(255,255,255,.07); background: rgba(255,255,255,.02);
  }
  .bar { display: flex; gap: 8px; align-items: flex-end; max-width: 850px; margin: 0 auto; }
  textarea {
    flex: 1; resize: none; background: #14172a; color: var(--text); border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px; padding: 12px 14px; font-size: 15px; max-height: 120px; outline: none;
  }
  textarea:focus { border-color: var(--accent); }
  button {
    border: none; cursor: pointer; border-radius: 14px; width: 48px; height: 48px; font-size: 20px;
    color: #fff; transition: transform .1s, opacity .2s; flex-shrink: 0;
  }
  button:active { transform: scale(.92); }
  button:disabled { opacity: .4; cursor: not-allowed; }
  #send { background: linear-gradient(135deg,#6d5dfc,#8b5cf6); }
  #imgBtn { background: linear-gradient(135deg,#22d3ee,#0ea5e9); }
  .hint { text-align:center; color:var(--muted); font-size:12px; margin-top:8px; }
</style>
</head>
<body>
  <header><span>🤖 المساعد الذكي</span></header>

  <div id="chat">
    <div class="welcome">
      أهلاً 👋<br>
      اكتب أي سؤال وبرد عليك المساعد.<br>
      وبتقدر تضغط 🎨 لتوليد صورة من وصفك.
    </div>
  </div>

  <footer>
    <div id="preview" style="display:none; max-width:850px; margin:0 auto 8px; position:relative; width:fit-content;">
      <img id="previewImg" style="height:60px; border-radius:10px; border:1px solid rgba(255,255,255,.15);">
      <span id="removeImg" style="position:absolute; top:-8px; left:-8px; background:#ef4444; color:#fff; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px;">×</span>
    </div>
    <div class="bar">
      <textarea id="input" rows="1" placeholder="اكتب رسالتك هون..."></textarea>
      <button id="attachBtn" title="رفع صورة" style="background:linear-gradient(135deg,#475569,#334155);">📎</button>
      <button id="imgBtn" title="توليد / تعديل صورة">🎨</button>
      <button id="send" title="إرسال">➤</button>
    </div>
    <input type="file" id="fileInput" accept="image/*" style="display:none;">
    <div class="hint">📎 ارفع صورة + 🎨 لتعديلها &nbsp;|&nbsp; 🎨 لوحدها = صورة جديدة &nbsp;|&nbsp; ➤ = محادثة</div>
  </footer>

<script>
const chat   = document.getElementById('chat');
const input  = document.getElementById('input');
const send   = document.getElementById('send');
const imgBtn = document.getElementById('imgBtn');
const attachBtn = document.getElementById('attachBtn');
const fileInput = document.getElementById('fileInput');
const preview   = document.getElementById('preview');
const previewImg= document.getElementById('previewImg');
const removeImg = document.getElementById('removeImg');
let history  = [];          // سجل المحادثة
let busy     = false;
let attached = null;        // الصورة المرفوعة {data, mime, url}

// ===== رفع الصورة =====
attachBtn.onclick = () => fileInput.click();
removeImg.onclick = clearAttached;
fileInput.onchange = () => {
  const f = fileInput.files[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = () => {
    const url = reader.result;
    attached = { data: url.split(',')[1], mime: f.type, url };
    previewImg.src = url;
    preview.style.display = 'block';
  };
  reader.readAsDataURL(f);
  fileInput.value = '';
};
function clearAttached() {
  attached = null;
  preview.style.display = 'none';
}

input.addEventListener('input', () => {
  input.style.height = 'auto';
  input.style.height = Math.min(input.scrollHeight, 120) + 'px';
});
input.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
});
send.onclick   = sendChat;
imgBtn.onclick = sendImage;

function clearWelcome() {
  const w = chat.querySelector('.welcome');
  if (w) w.remove();
}

function addMsg(text, who) {
  clearWelcome();
  const d = document.createElement('div');
  d.className = 'msg ' + who;
  d.textContent = text;
  chat.appendChild(d);
  chat.scrollTop = chat.scrollHeight;
  return d;
}

function addImage(src) {
  clearWelcome();
  const d = document.createElement('div');
  d.className = 'msg ai';
  const img = document.createElement('img');
  img.src = src;
  d.appendChild(img);
  chat.appendChild(d);
  chat.scrollTop = chat.scrollHeight;
}

function showTyping() {
  const t = document.createElement('div');
  t.className = 'typing';
  t.id = 'typing';
  t.innerHTML = 'يكتب <span class="dot"></span><span class="dot"></span><span class="dot"></span>';
  chat.appendChild(t);
  chat.scrollTop = chat.scrollHeight;
}
function hideTyping() { const t = document.getElementById('typing'); if (t) t.remove(); }

function setBusy(state) {
  busy = state;
  send.disabled = imgBtn.disabled = state;
}

async function sendChat() {
  const text = input.value.trim();
  if (!text || busy) return;
  setBusy(true);
  addMsg(text, 'user');
  history.push({ role: 'user', content: text });
  input.value = ''; input.style.height = 'auto';
  showTyping();

  try {
    const r = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'chat', messages: history })
    });
    const data = await r.json();
    hideTyping();
    if (data.error) { addMsg('⚠️ ' + data.error, 'ai'); }
    else {
      addMsg(data.reply, 'ai');
      history.push({ role: 'assistant', content: data.reply });
    }
  } catch (e) {
    hideTyping();
    addMsg('⚠️ تعذّر الاتصال بالسيرفر', 'ai');
  }
  setBusy(false);
}

async function sendImage() {
  const text = input.value.trim();
  if (!text || busy) return;
  setBusy(true);

  // رسالة المستخدم: النص + الصورة المرفوعة إذا وُجدت
  clearWelcome();
  const u = document.createElement('div');
  u.className = 'msg user';
  u.textContent = '🎨 ' + text;
  if (attached) {
    const im = document.createElement('img');
    im.src = attached.url;
    u.appendChild(im);
  }
  chat.appendChild(u);
  chat.scrollTop = chat.scrollHeight;

  const body = { action: 'image', prompt: text };
  if (attached) body.image = { data: attached.data, mime: attached.mime };

  input.value = ''; input.style.height = 'auto';
  clearAttached();
  showTyping();

  try {
    const r = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await r.json();
    hideTyping();
    if (data.error) { addMsg('⚠️ ' + data.error, 'ai'); }
    else {
      if (data.text) addMsg(data.text, 'ai');
      addImage(data.image);
    }
  } catch (e) {
    hideTyping();
    addMsg('⚠️ تعذّر معالجة الصورة', 'ai');
  }
  setBusy(false);
}
</script>
</body>
</html>

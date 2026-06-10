<?php $GID = getenv('GOOGLE_CLIENT_ID'); ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>المساعد الذكي</title>
<script src="https://accounts.google.com/gsi/client" async></script>
<style>
  * { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,sans-serif; -webkit-tap-highlight-color:transparent; }
  :root{
    --bg1:#0f1020; --accent:#8b5cf6; --accent2:#22d3ee;
    --bubble-user:linear-gradient(135deg,#6d5dfc,#8b5cf6);
    --bubble-ai:#1e2235; --text:#eef0f7; --muted:#9aa0b5; --panel:#171a2e;
  }
  html,body{ height:100%; overflow:hidden; }
  body{ background:radial-gradient(1000px 500px at 80% -10%,#2a2150 0%,var(--bg1) 60%); color:var(--text); display:flex; flex-direction:column; height:100dvh; }
  header{ display:flex; align-items:center; justify-content:space-between; padding:calc(env(safe-area-inset-top) + 12px) 14px 12px; border-bottom:1px solid rgba(255,255,255,.07); background:rgba(255,255,255,.02); backdrop-filter:blur(8px); }
  header .title{ font-size:17px; font-weight:700; background:linear-gradient(90deg,#8b5cf6,#22d3ee); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .icon-btn{ width:40px; height:40px; border:none; border-radius:12px; cursor:pointer; background:rgba(255,255,255,.06); color:var(--text); font-size:18px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .icon-btn:active{ transform:scale(.9); }
  .avatar{ width:40px; height:40px; border-radius:50%; object-fit:cover; }
  #chat{ flex:1; overflow-y:auto; padding:16px 14px; display:flex; flex-direction:column; gap:12px; -webkit-overflow-scrolling:touch; }
  #chat::-webkit-scrollbar{ width:6px; } #chat::-webkit-scrollbar-thumb{ background:#3a3a55; border-radius:8px; }
  .msg{ max-width:85%; padding:11px 14px; border-radius:18px; line-height:1.7; white-space:pre-wrap; word-wrap:break-word; animation:pop .2s ease; font-size:15px; }
  @keyframes pop{ from{opacity:0;transform:translateY(8px)} to{opacity:1} }
  .user{ align-self:flex-start; background:var(--bubble-user); border-bottom-right-radius:5px; }
  .ai{ align-self:flex-end; background:var(--bubble-ai); border-bottom-left-radius:5px; border:1px solid rgba(255,255,255,.05); }
  .msg img{ max-width:100%; border-radius:12px; margin-top:6px; display:block; }
  .welcome{ text-align:center; color:var(--muted); margin:auto; font-size:15px; line-height:2.1; }
  .typing{ align-self:flex-end; color:var(--muted); font-size:14px; padding:6px 12px; }
  .dot{ display:inline-block; width:7px; height:7px; margin:0 1px; border-radius:50%; background:var(--accent); animation:b 1.2s infinite; }
  .dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
  @keyframes b{ 0%,60%,100%{opacity:.3;transform:translateY(0)} 30%{opacity:1;transform:translateY(-4px)} }
  footer{ padding:10px 12px calc(env(safe-area-inset-bottom) + 10px); border-top:1px solid rgba(255,255,255,.07); background:rgba(255,255,255,.02); }
  #preview{ display:none; position:relative; width:fit-content; margin-bottom:8px; }
  #preview img{ height:58px; border-radius:10px; border:1px solid rgba(255,255,255,.15); }
  #removeImg{ position:absolute; top:-8px; left:-8px; background:#ef4444; color:#fff; width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; }
  .bar{ display:flex; gap:7px; align-items:flex-end; }
  textarea{ flex:1; resize:none; background:#14172a; color:var(--text); border:1px solid rgba(255,255,255,.1); border-radius:14px; padding:12px 14px; font-size:16px; max-height:110px; outline:none; }
  textarea:focus{ border-color:var(--accent); }
  .send-btn{ border:none; cursor:pointer; border-radius:14px; width:46px; height:46px; font-size:19px; color:#fff; flex-shrink:0; }
  .send-btn:active{ transform:scale(.9); } .send-btn:disabled{ opacity:.4; }
  #attachBtn{ background:linear-gradient(135deg,#475569,#334155); }
  #imgBtn{ background:linear-gradient(135deg,#22d3ee,#0ea5e9); }
  #send{ background:linear-gradient(135deg,#6d5dfc,#8b5cf6); }
  .hint{ text-align:center; color:var(--muted); font-size:11px; margin-top:7px; }
  .overlay{ position:fixed; inset:0; background:rgba(0,0,0,.55); display:none; align-items:flex-end; z-index:50; }
  .overlay.open{ display:flex; }
  .sheet{ position:relative; width:100%; background:var(--panel); border-radius:20px 20px 0 0; padding:22px 18px calc(env(safe-area-inset-bottom) + 22px); animation:up .25s ease; }
  @keyframes up{ from{transform:translateY(100%)} to{transform:translateY(0)} }
  .sheet h3{ font-size:17px; margin-bottom:16px; text-align:center; }
  .sheet .row{ width:100%; padding:14px; border-radius:12px; background:rgba(255,255,255,.05); border:none; color:var(--text); font-size:15px; text-align:right; cursor:pointer; margin-bottom:10px; }
  .sheet .row:active{ background:rgba(255,255,255,.1); }
  .field{ width:100%; padding:13px; border-radius:12px; background:#14172a; border:1px solid rgba(255,255,255,.1); color:var(--text); font-size:16px; margin-bottom:10px; outline:none; }
  .field:focus{ border-color:var(--accent); }
  .primary{ width:100%; padding:14px; border:none; border-radius:12px; background:linear-gradient(135deg,#6d5dfc,#8b5cf6); color:#fff; font-size:16px; font-weight:600; cursor:pointer; margin-bottom:10px; }
  .tabs{ display:flex; gap:8px; margin-bottom:16px; }
  .tab{ flex:1; padding:11px; text-align:center; border-radius:10px; background:rgba(255,255,255,.05); cursor:pointer; font-size:15px; }
  .tab.active{ background:var(--accent); color:#fff; }
  .note{ text-align:center; color:var(--muted); font-size:12px; margin-top:6px; }
  .sep{ text-align:center; color:var(--muted); font-size:12px; margin:14px 0; position:relative; }
  .sep::before,.sep::after{ content:""; position:absolute; top:50%; width:38%; height:1px; background:rgba(255,255,255,.1); }
  .sep::before{ right:0; } .sep::after{ left:0; }
  .gbox{ display:flex; justify-content:center; }
  .close-x{ position:absolute; top:14px; left:16px; font-size:22px; color:var(--muted); cursor:pointer; }
  #loggedIn{ display:none; text-align:center; }
  #loggedIn img{ width:74px; height:74px; border-radius:50%; margin-bottom:10px; }
  #liName{ font-size:17px; font-weight:600; }
  #liEmail{ color:var(--muted); font-size:13px; margin-bottom:18px; }
  .logout-btn{ width:100%; padding:13px; border:1px solid rgba(239,68,68,.4); border-radius:12px; background:rgba(239,68,68,.1); color:#f87171; font-size:15px; cursor:pointer; }
</style>
</head>
<body>
  <header>
    <button class="icon-btn" id="settingsBtn" title="الإعدادات">⚙️</button>
    <div class="title">🤖 المساعد الذكي</div>
    <button class="icon-btn" id="accountBtn" title="الحساب">👤</button>
  </header>

  <div id="chat">
    <div class="welcome">أهلاً 👋<br>اكتب أي سؤال وبرد عليك المساعد.<br>🎨 لتوليد صورة &nbsp;•&nbsp; 📎 لرفع صورة وتعديلها</div>
  </div>

  <footer>
    <div id="preview"><img id="previewImg"><span id="removeImg">×</span></div>
    <div class="bar">
      <textarea id="input" rows="1" placeholder="اكتب رسالتك..."></textarea>
      <button class="send-btn" id="attachBtn" title="رفع صورة">📎</button>
      <button class="send-btn" id="imgBtn" title="توليد / تعديل صورة">🎨</button>
      <button class="send-btn" id="send" title="إرسال">➤</button>
    </div>
    <input type="file" id="fileInput" accept="image/*" style="display:none;">
    <div class="hint">📎 صورة + 🎨 تعديل &nbsp;|&nbsp; 🎨 توليد صورة &nbsp;|&nbsp; ➤ محادثة</div>
  </footer>

  <div class="overlay" id="settingsSheet">
    <div class="sheet">
      <span class="close-x" data-close>×</span>
      <h3>الإعدادات</h3>
      <button class="row" id="clearBtn">🗑️ مسح المحادثة</button>
      <div class="note">المساعد الذكي — مدعوم بـ Gemini و Cloudflare AI</div>
    </div>
  </div>

  <div class="overlay" id="accountSheet">
    <div class="sheet">
      <span class="close-x" data-close>×</span>

      <div id="loginView">
        <div class="tabs">
          <div class="tab active" id="tabLogin">تسجيل دخول</div>
          <div class="tab" id="tabSignup">إنشاء حساب</div>
        </div>
        <input class="field" id="acEmail" type="email" placeholder="الإيميل">
        <input class="field" id="acPass" type="password" placeholder="كلمة السر">
        <button class="primary" id="acSubmit">تسجيل الدخول</button>
        <div class="sep">أو</div>
        <div class="gbox">
<?php if ($GID): ?>
          <div id="g_id_onload" data-client_id="<?php echo htmlspecialchars($GID, ENT_QUOTES); ?>" data-callback="handleCredential" data-auto_prompt="false"></div>
          <div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="filled_blue" data-text="continue_with" data-size="large" data-locale="ar"></div>
<?php else: ?>
          <div class="note">⚙️ أضف المتغير GOOGLE_CLIENT_ID على Railway لتفعيل دخول جوجل</div>
<?php endif; ?>
        </div>
        <div class="note" id="acNote">الإيميل/كلمة السر قيد الإعداد — استعمل جوجل حالياً</div>
      </div>

      <div id="loggedIn">
        <img id="liPic" alt="">
        <div id="liName"></div>
        <div id="liEmail"></div>
        <button class="logout-btn" id="logoutBtn">تسجيل الخروج</button>
      </div>
    </div>
  </div>

<script>
const chat=document.getElementById('chat'), input=document.getElementById('input');
const send=document.getElementById('send'), imgBtn=document.getElementById('imgBtn');
const attachBtn=document.getElementById('attachBtn'), fileInput=document.getElementById('fileInput');
const preview=document.getElementById('preview'), previewImg=document.getElementById('previewImg'), removeImg=document.getElementById('removeImg');
const accountBtn=document.getElementById('accountBtn');
let history=[], busy=false, attached=null;

document.getElementById('settingsBtn').onclick=()=>openSheet('settingsSheet');
accountBtn.onclick=()=>openSheet('accountSheet');
document.querySelectorAll('[data-close]').forEach(x=>x.onclick=closeAll);
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{ if(e.target===o) closeAll(); }));
function openSheet(id){ document.getElementById(id).classList.add('open'); }
function closeAll(){ document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open')); }
document.getElementById('clearBtn').onclick=()=>{ history=[]; chat.innerHTML='<div class="welcome">تم مسح المحادثة ✅<br>ابدأ من جديد</div>'; closeAll(); };

const tabLogin=document.getElementById('tabLogin'), tabSignup=document.getElementById('tabSignup'), acSubmit=document.getElementById('acSubmit');
tabLogin.onclick=()=>{ tabLogin.classList.add('active'); tabSignup.classList.remove('active'); acSubmit.textContent='تسجيل الدخول'; };
tabSignup.onclick=()=>{ tabSignup.classList.add('active'); tabLogin.classList.remove('active'); acSubmit.textContent='إنشاء حساب'; };
acSubmit.onclick=()=>{ document.getElementById('acNote').textContent='🔧 الإيميل/كلمة السر قيد الإعداد — استعمل جوجل حالياً'; };

/* ===== Google ===== */
window.handleCredential=async(resp)=>{
  try{
    const r=await fetch('auth.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'verify',credential:resp.credential})});
    const data=await r.json();
    if(data.user) applyUser(data.user);
    else document.getElementById('acNote').textContent='⚠️ '+(data.error||'فشل الدخول');
  }catch(e){ document.getElementById('acNote').textContent='⚠️ تعذّر الاتصال'; }
};
function applyUser(u){
  document.getElementById('loginView').style.display='none';
  document.getElementById('loggedIn').style.display='block';
  document.getElementById('liPic').src=u.picture||'';
  document.getElementById('liName').textContent=u.name||'مستخدم';
  document.getElementById('liEmail').textContent=u.email||'';
  if(u.picture) accountBtn.innerHTML='<img class="avatar" src="'+u.picture+'" alt="">';
}
function setLoggedOut(){
  document.getElementById('loginView').style.display='block';
  document.getElementById('loggedIn').style.display='none';
  accountBtn.textContent='👤';
}
document.getElementById('logoutBtn').onclick=async()=>{ try{ await fetch('auth.php?action=logout'); }catch(e){} setLoggedOut(); closeAll(); };
fetch('auth.php?action=me').then(r=>r.json()).then(d=>{ if(d.user) applyUser(d.user); }).catch(()=>{});

/* ===== رفع وتصغير الصورة ===== */
attachBtn.onclick=()=>fileInput.click();
removeImg.onclick=clearAttached;
fileInput.onchange=()=>{
  const f=fileInput.files[0]; if(!f) return;
  const reader=new FileReader();
  reader.onload=e=>{
    const img=new Image();
    img.onload=()=>{
      let w=img.width,h=img.height,max=768;
      if(w>h&&w>max){ h=Math.round(h*max/w); w=max; } else if(h>max){ w=Math.round(w*max/h); h=max; }
      const c=document.createElement('canvas'); c.width=w; c.height=h;
      c.getContext('2d').drawImage(img,0,0,w,h);
      const url=c.toDataURL('image/jpeg',0.9);
      attached={ data:url.split(',')[1], mime:'image/jpeg', url };
      previewImg.src=url; preview.style.display='block';
    };
    img.src=e.target.result;
  };
  reader.readAsDataURL(f); fileInput.value='';
};
function clearAttached(){ attached=null; preview.style.display='none'; }

/* ===== الواجهة ===== */
input.addEventListener('input',()=>{ input.style.height='auto'; input.style.height=Math.min(input.scrollHeight,110)+'px'; });
input.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendChat(); } });
send.onclick=sendChat; imgBtn.onclick=sendImage;

function clearWelcome(){ const w=chat.querySelector('.welcome'); if(w) w.remove(); }
function addMsg(t,who){ clearWelcome(); const d=document.createElement('div'); d.className='msg '+who; d.textContent=t; chat.appendChild(d); chat.scrollTop=chat.scrollHeight; return d; }
function addImage(src){ clearWelcome(); const d=document.createElement('div'); d.className='msg ai'; const im=document.createElement('img'); im.onload=()=>chat.scrollTop=chat.scrollHeight; im.src=src; d.appendChild(im); chat.appendChild(d); chat.scrollTop=chat.scrollHeight; }
function showTyping(){ const t=document.createElement('div'); t.className='typing'; t.id='typing'; t.innerHTML='يعمل <span class="dot"></span><span class="dot"></span><span class="dot"></span>'; chat.appendChild(t); chat.scrollTop=chat.scrollHeight; }
function hideTyping(){ const t=document.getElementById('typing'); if(t) t.remove(); }
function setBusy(s){ busy=s; send.disabled=imgBtn.disabled=attachBtn.disabled=s; }

async function sendChat(){
  const text=input.value.trim(); if(!text||busy) return;
  setBusy(true); addMsg(text,'user'); history.push({role:'user',content:text});
  input.value=''; input.style.height='auto'; showTyping();
  try{
    const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'chat',messages:history})});
    const data=await r.json(); hideTyping();
    if(data.error){ addMsg('⚠️ '+data.error,'ai'); }
    else{ if(data.reply){ addMsg(data.reply,'ai'); history.push({role:'assistant',content:data.reply}); } if(data.image) addImage(data.image); }
  }catch(e){ hideTyping(); addMsg('⚠️ تعذّر الاتصال بالسيرفر','ai'); }
  setBusy(false);
}

async function sendImage(){
  const text=input.value.trim(); if(!text||busy) return;
  setBusy(true); clearWelcome();
  const u=document.createElement('div'); u.className='msg user'; u.textContent=(attached?'🖌️ ':'🎨 ')+text;
  if(attached){ const im=document.createElement('img'); im.src=attached.url; u.appendChild(im); }
  chat.appendChild(u); chat.scrollTop=chat.scrollHeight;
  const body={action:'image',prompt:text};
  if(attached) body.image={data:attached.data,mime:attached.mime};
  input.value=''; input.style.height='auto'; clearAttached(); showTyping();
  try{
    const r=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const data=await r.json(); hideTyping();
    if(data.error){ addMsg('⚠️ '+data.error,'ai'); }
    else{ if(data.text) addMsg(data.text,'ai'); addImage(data.image); }
  }catch(e){ hideTyping(); addMsg('⚠️ تعذّر معالجة الصورة','ai'); }
  setBusy(false);
}
</script>
</body>
</html>

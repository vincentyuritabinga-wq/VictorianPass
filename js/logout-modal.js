document.addEventListener('DOMContentLoaded',function(){
  var style=document.createElement('style');
  style.textContent='\n.vp-logout-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:2000;font-family:\'Poppins\',sans-serif;animation:vpOverlayFade .2s ease}\n.vp-logout-modal{background:#ffffff;color:#1f2937;border:1px solid #e5e7eb;border-radius:16px;max-width:520px;width:92%;padding:22px;text-align:center;box-shadow:0 20px 45px rgba(15,23,42,.25);animation:vpModalPop .35s cubic-bezier(0.22,1,0.36,1);font-family:\'Poppins\',sans-serif}\n.vp-logout-modal .title{font-weight:800;font-size:1.2rem;margin:0 0 8px;color:#23412e}\n.vp-logout-modal .text{font-size:.98rem;color:#475569;margin:0 0 16px}\n.vp-logout-modal .actions{display:flex;gap:10px;justify-content:center}\n.vp-logout-modal .btn{padding:10px 18px;border-radius:10px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease, background-color .2s ease, color .2s ease;font-family:\'Poppins\',sans-serif}\n.vp-logout-modal .btn-confirm{background:#23412e;color:#fff}\n.vp-logout-modal .btn-cancel{background:#e5e7eb;color:#1f2937}\n.vp-logout-modal .btn:hover{transform:translateY(-2px);box-shadow:0 8px 16px rgba(15,23,42,.12)}\n.vp-logout-modal .btn-confirm:hover{background:#1a2f22}\n.vp-logout-modal .btn-cancel:hover{background:#dbe2e8}\n.vp-logout-modal .close-change-password{position:absolute;right:12px;top:10px;background:transparent;border:none;font-size:20px;color:#23412e;cursor:pointer;transition:transform .2s ease,color .2s ease}\n.vp-logout-modal .close-change-password:hover{transform:scale(1.05);color:#1a2f22}\n.vp-logout-modal .change-password-title{margin-bottom:12px;font-weight:700;font-size:1.2rem;color:#23412e}\n.vp-logout-modal input{font-family:\'Poppins\',sans-serif;transition:border-color .2s ease, box-shadow .2s ease}\n.vp-logout-modal input:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,.15);outline:none}\n@keyframes vpModalPop{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}\n@keyframes vpOverlayFade{from{opacity:0}to{opacity:1}}\n';
  document.head.appendChild(style);
  var overlay=document.createElement('div');
  overlay.className='vp-logout-overlay';
  overlay.innerHTML='<div class="vp-logout-modal"><div class="title">Confirm Logout</div><div class="text">Are you sure you want to log out?</div><div class="actions"><button class="btn btn-confirm" id="vpLogoutYes">Log Out</button><button class="btn btn-cancel" id="vpLogoutNo">Cancel</button></div></div>';
  document.body.appendChild(overlay);
  var targetHref=null;
  function open(){ overlay.style.display='flex'; }
  function close(){ overlay.style.display='none'; targetHref=null; }
  overlay.addEventListener('click',function(e){ if(e.target===overlay) close(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
  var yesBtn=function(){ var y=document.getElementById('vpLogoutYes'); if(!y) return; y.addEventListener('click',function(){ if(targetHref){ window.location.href=targetHref; } }); };
  var noBtn=function(){ var n=document.getElementById('vpLogoutNo'); if(!n) return; n.addEventListener('click',function(){ close(); }); };
  yesBtn(); noBtn();
  function bind(){
    var links=Array.prototype.slice.call(document.querySelectorAll('a[href]'));
    links.forEach(function(a){
      var href=a.getAttribute('href')||'';
      if(/logout\.php/i.test(href) || /[?&]logout=/.test(href)){
        a.addEventListener('click',function(e){
          e.preventDefault();
          targetHref=/logout\.php/i.test(href)?'logout.php?confirm=yes':href;
          open();
        });
      }
    });
    var targets=Array.prototype.slice.call(document.querySelectorAll('[data-logout-href]'));
    targets.forEach(function(el){
      var href=el.getAttribute('data-logout-href')||'';
      if(!href) return;
      el.addEventListener('click',function(e){
        e.preventDefault();
        targetHref=/logout\.php/i.test(href)?'logout.php?confirm=yes':href;
        open();
      });
    });
  }
  bind();
});

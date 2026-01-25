document.addEventListener('DOMContentLoaded',function(){
  var style=document.createElement('style');
  style.textContent='\n.vp-logout-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:2000}\n.vp-logout-modal{background:#1b1816;color:#fff;border:1px solid rgba(255,255,255,.12);border-radius:14px;max-width:520px;width:92%;padding:20px;text-align:center;box-shadow:0 16px 40px rgba(0,0,0,.35)}\n.vp-logout-modal .title{font-weight:800;font-size:1.2rem;margin:0 0 8px;color:#e74c3c}\n.vp-logout-modal .text{font-size:.98rem;color:#ddd;margin:0 0 14px}\n.vp-logout-modal .actions{display:flex;gap:10px;justify-content:center}\n.vp-logout-modal .btn{padding:10px 16px;border-radius:10px;font-weight:700;text-decoration:none;display:inline-block;border:none;cursor:pointer}\n.vp-logout-modal .btn-confirm{background:#c0392b;color:#fff}\n.vp-logout-modal .btn-cancel{background:#e5e7eb;color:#222}\n.vp-logout-modal .btn:hover{transform:translateY(-1px)}\n';
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

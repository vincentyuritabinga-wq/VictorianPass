<?php
session_start();
require_once 'connect.php';

// Redirect if not logged in or not a visitor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'visitor') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = [];

// Fetch visitor data
if ($con) {
    $stmt = $con->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}
$fullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));

// Fetch Activities
$activities = [];

// Reservations
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, end_date, status, created_at, ref_code FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $end = $row['end_date'] ?? null;
        $dateStr = '';
        if (!empty($start) && !empty($end)) {
            $dateStr = date('M d, Y', strtotime($start)) . ' - ' . date('M d, Y', strtotime($end));
        } elseif (!empty($start)) {
            $dateStr = date('M d, Y', strtotime($start));
        } else {
            $dateStr = 'Date not set';
        }
        $activities[] = [
            'type' => 'reservation',
            'title' => 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity'),
            'details' => $dateStr,
            'status' => $row['status'] ?? 'pending',
            'date' => $row['created_at'],
            'ref_code' => $row['ref_code'] ?? 'RES'
        ];
    }
    $stmt->close();
}

usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'items' => $activities]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visitor Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="app-container">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <a href="mainpage.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item active"><i class="fa-solid fa-list"></i> <span>My Requests</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span></a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <!-- Top Header -->
    <header class="top-header">
      <div class="header-brand">
        <img src="images/logo.svg" alt="Logo">
        <div class="brand-text">
          <span class="brand-main">VictorianPass</span>
          <span class="brand-sub">Victorian Heights Subdivision</span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <a href="dashboardvisitor.php" class="user-profile">
          <span class="user-name">Hi, <?php echo htmlspecialchars($fullName); ?></span>
          <img src="images/mainpage/profile'.jpg" alt="Profile" class="user-avatar">
        </a>
      </div>
    </header>

    <div class="content-wrapper">
      <div class="right-panel">
        <div class="activity-list-header">
          <div>My Requests</div>
          <div class="search-bar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search by code or keyword" id="requestSearch">
          </div>
        </div>

        <div class="item-list">
          <?php if (empty($activities)): ?>
            <div style="padding:20px; text-align:center; color:#777;">No records found.</div>
          <?php else: ?>
            <?php foreach ($activities as $act):
                $statusClass = 'status-pending';
                $s = strtolower($act['status']);
                if (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-ongoing';
                elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false) $statusClass = 'status-denied';
                elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                $displayStatus = ucfirst($act['status']);
            ?>
            <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>">
               <div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>
               <div class="item-content">
                 <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                   <div>
                     <span class="status-badge <?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                     <span class="item-title"><?php echo htmlspecialchars($act['title']); ?></span>
                     <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                   </div>
                   <div class="item-time"><?php echo date('h:i A', strtotime($act['date'])); ?></div>
                 </div>
                 <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                   <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                 </div>
                 <div class="item-extra" data-loaded="0"></div>
               </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
  <div id="cancelModal" class="cancel-modal" style="display:none;">
    <div class="cancel-modal-content">
      <div class="cancel-modal-header">
        <h3>Cancel Reservation</h3>
        <button type="button" class="cancel-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="cancel-modal-body">
        <p>Are you sure you want to cancel this reservation?</p>
        <p class="cancel-modal-note">Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.</p>
      </div>
      <div class="cancel-modal-actions">
        <button type="button" class="cancel-modal-keep">Keep Reservation</button>
        <button type="button" class="cancel-modal-confirm">Confirm Cancel</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var searchInput=document.getElementById('requestSearch');
  function filterList(){
    var q=(searchInput.value||'').toLowerCase();
    document.querySelectorAll('.item-list .list-item').forEach(function(li){
      var text=li.textContent.toLowerCase();
      li.style.display=text.indexOf(q)!==-1?'':'none';
    });
  }
  if(searchInput){ searchInput.addEventListener('input',filterList); }
  var prevStatuses={};
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    var code=li.getAttribute('data-ref-code')||'';
    var st=li.getAttribute('data-status')||'';
    if(code) prevStatuses[code]=st;
  });
  function statusClassFor(s){
    s=(s||'').toLowerCase();
    if(s.indexOf('approv')!==-1||s.indexOf('resolved')!==-1||s.indexOf('ongoing')!==-1) return 'status-ongoing';
    if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) return 'status-denied';
    if(s.indexOf('cancel')!==-1) return 'status-cancelled';
    return 'status-pending';
  }
  function fmtLabel(s){
    s=String(s||'').toLowerCase();
    return s.charAt(0).toUpperCase()+s.slice(1);
  }
  var notifCountEl=document.getElementById('notifCount');
  var notifBtn=document.getElementById('notifBtn');
  var notifPanel=document.getElementById('notifPanel');
  var notifItems=[];
  var cancelModal=document.getElementById('cancelModal');
  var cancelModalKeep=cancelModal?cancelModal.querySelector('.cancel-modal-keep'):null;
  var cancelModalConfirm=cancelModal?cancelModal.querySelector('.cancel-modal-confirm'):null;
  var cancelModalClose=cancelModal?cancelModal.querySelector('.cancel-modal-close'):null;
  var cancelModalRef=null;
  var cancelModalLi=null;
  function openCancelModal(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    cancelModal.style.display='flex';
  }
  function closeCancelModalVisitor(){
    if(!cancelModal) return;
    cancelModal.style.display='none';
    cancelModalRef=null;
    cancelModalLi=null;
  }
  function performCancelVisitor(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalVisitor();
      return;
    }
    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'cancel',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to cancel reservation.');
        return;
      }
      li.setAttribute('data-status','denied');
      prevStatuses[ref]='denied';
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('denied');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
        badge.classList.add(statusClassFor('denied'));
      }
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }
      closeCancelModalVisitor();
    })["catch"](function(){
      alert('Network error. Please try again.');
    });
  }
  if(cancelModalKeep){
    cancelModalKeep.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalClose){
    cancelModalClose.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalConfirm){
    cancelModalConfirm.addEventListener('click',function(){
      performCancelVisitor();
    });
  }
  function buildExtraContent(li, extra){
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var status=li.getAttribute('data-status')||'';
    var ref=li.getAttribute('data-ref-code')||'';
    var label=fmtLabel(status);
    var statusNote='';
    var s=status.toLowerCase();
    var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
    var isApproved=s.indexOf('approv')!==-1;
    if(isApproved) statusNote='This request is approved. Use this QR pass at the gate.';
    else if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) statusNote='This request was denied. Please contact the subdivision office for details.';
    else if(s.indexOf('pending')!==-1||s===''||s==='new') statusNote='This request is pending. Wait for the admin to review it. The QR entry pass will be available after approval.';
    else if(s.indexOf('resolved')!==-1) statusNote='This item has been marked as resolved by the admin.';
    else if(s.indexOf('expired')!==-1) statusNote='This pass is expired and can no longer be used.';
    var titleEl=li.querySelector('.item-title');
    var detailsEl=li.querySelector('.item-details');
    var refSpan=li.querySelector('.item-ref span');
    function esc(t){
      return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    var summaryParts=[];
    if(titleEl){ summaryParts.push(titleEl.textContent.trim()); }
    if(detailsEl){ summaryParts.push(detailsEl.textContent.replace(/^\s*-\s*/,'').trim()); }
    if(refSpan){ summaryParts.push('Code: '+refSpan.textContent.trim()); }
    var summaryText=summaryParts.join(' • ');
    var canCancel=(type==='reservation'||type==='guest_form')&&(s.indexOf('pending')!==-1||s===''||s==='new');
    var html='';
    if(type==='reservation'||type==='guest_form'){
      html+='<div class="item-extra-section">';
      if(isApproved && ref){
        var statusLink=location.origin+basePath+'/status_view.php?code='+encodeURIComponent(ref);
        var qrSrc='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='+encodeURIComponent(statusLink);
        html+='<div class="item-extra-title">Entry QR Pass</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-qr-wrap"><img class="item-extra-qr" src="'+qrSrc+'" alt="Entry QR Code"></div>';
        html+='<div class="item-extra-info">';
      }else{
        html+='<div class="item-extra-title">Entry Request Status</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-info-only">';
      }
      html+='<div class="item-extra-status"><span class="status-label">'+label+'</span></div>';
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      if(canCancel && ref){
        html+='<button type="button" class="item-extra-cancel" data-ref="'+esc(ref)+'">Cancel reservation</button>';
      }
      if(isApproved && ref){
        var qrViewLink=basePath+'/qr_view.php?code='+encodeURIComponent(ref);
        html+='<a class="item-extra-link" href="'+qrViewLink+'" target="_blank">Open full QR pass</a>';
      }
      if(isApproved && ref){
        html+='</div></div></div>';
      }else{
        var qrViewLink=basePath+'/qr_view.php?code='+encodeURIComponent(ref);
        html+='<a class="item-extra-link" href="'+qrViewLink+'" target="_blank">View details</a>';
        html+='</div></div></div>';
      }
    }else{
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-title">Request Details</div>';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      html+='<div class="item-extra-status"><span class="status-label">'+label+'</span></div>';
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      html+='</div></div></div>';
    }
    extra.innerHTML=html;
    var cancelBtn=extra.querySelector('.item-extra-cancel');
    if(cancelBtn && ref && canCancel){
      cancelBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openCancelModal(li,ref);
      });
    }
  }
  function addNotificationEntry(code,status,li){
    if(!code) return;
    var key=code+'|'+String(status||'');
    for(var i=0;i<notifItems.length;i++){ if(notifItems[i].key===key) return; }
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var titleEl=li.querySelector('.item-title');
    var title=titleEl?titleEl.textContent.trim():(type==='reservation'?'Reservation Schedule':'Request Update');
    var timeText='';
    try{ timeText=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'}); }catch(e){ timeText=''; }
    notifItems.push({
      key:key,
      code:code,
      status:fmtLabel(status),
      title:title,
      type:type,
      time:timeText
    });
  }
  function renderNotifPanel(){
    if(!notifPanel) return;
    if(!notifItems.length){
      notifPanel.innerHTML='<div class="notif-empty">No recent updates</div>';
      return;
    }
    var html='';
    for(var i=notifItems.length-1;i>=0;i--){
      var it=notifItems[i]||{};
      var code=String(it.code||'').replace(/[<>]/g,'');
      var title=String(it.title||'').replace(/[<>]/g,'');
      var status=String(it.status||'').replace(/[<>]/g,'');
      var time=String(it.time||'').replace(/[<>]/g,'');
      html+='<div class="notif-item" data-code="'+code+'"><div class="notif-item-main"><div class="notif-item-title">'+title+'</div><div class="notif-item-sub">Code: '+code+' • '+status+'</div>';
      if(time) html+='<div class="notif-item-time">'+time+'</div>';
      html+='</div></div>';
    }
    notifPanel.innerHTML=html;
  }
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    li.addEventListener('click',function(e){
      if(e.target.closest('a')) return;
      li.classList.toggle('expanded');
      var extra=li.querySelector('.item-extra');
      if(!extra) return;
      if(extra.getAttribute('data-loaded')!=='1'&&li.classList.contains('expanded')){
        buildExtraContent(li,extra);
        extra.setAttribute('data-loaded','1');
      }
    });
  });
  if(notifBtn){
    notifBtn.addEventListener('click',function(e){
      e.stopPropagation();
      if(notifPanel){
        notifPanel.style.display=(notifPanel.style.display==='block'?'none':'block');
      }
      document.querySelectorAll('.item-list .list-item.status-updated').forEach(function(li){
        li.classList.remove('status-updated');
      });
      if(notifCountEl){
        notifCountEl.textContent='0';
        notifCountEl.style.display='none';
      }
    });
  }
  if(notifPanel){
    document.addEventListener('click',function(e){
      var t=e.target;
      if(t===notifPanel||notifPanel.contains(t)||t===notifBtn||(notifBtn&&notifBtn.contains(t))) return;
      notifPanel.style.display='none';
    });
  }
  function refreshStatuses(){
    fetch('dashboardvisitor.php?ajax=1',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.success||!Array.isArray(data.items)) return;
        var map={};
        data.items.forEach(function(it){ if(it.ref_code) map[it.ref_code]=it; });
        var changed=0;
        document.querySelectorAll('.item-list .list-item').forEach(function(li){
          var code=li.getAttribute('data-ref-code')||'';
          if(!code||!map[code]) return;
          var info=map[code];
          var newStatus=info.status||'';
          var oldStatus=prevStatuses[code];
          if(oldStatus!==undefined && oldStatus!==newStatus){
            changed++;
            li.classList.add('status-updated');
          }
          prevStatuses[code]=newStatus;
          li.setAttribute('data-status',newStatus);
          var badge=li.querySelector('.status-badge');
          if(badge){
            badge.textContent=fmtLabel(newStatus);
            badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
            badge.classList.add(statusClassFor(newStatus));
          }
          var timeEl=li.querySelector('.item-time');
          if(timeEl && info.date){
            var d=new Date(info.date.replace(' ','T'));
            if(!isNaN(d.getTime())) timeEl.textContent=d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
          }
        });
        if(notifCountEl){
          if(changed>0){
            notifCountEl.textContent=String(changed);
            notifCountEl.style.display='inline-block';
          }else{
            notifCountEl.textContent='0';
            notifCountEl.style.display='none';
          }
        }
      })["catch"](function(){});
  }
  setInterval(refreshStatuses,10000);
})();
</script>
</body>
</html>
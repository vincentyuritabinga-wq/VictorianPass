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
    $stmt = $con->prepare("SELECT first_name, last_name, email, phone, sex, birthdate FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}
$firstName = $user_data['first_name'] ?? 'Visitor';
$fullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));

// Profile Picture Logic
$profilePicPath = 'images/mainpage/profile\'.jpg'; // Default
if (file_exists('uploads/profiles/user_' . $user_id . '.jpg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpg';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.png')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.png';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.jpeg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpeg';
}
$profilePicUrl = $profilePicPath . '?t=' . time();

// Fetch Activities
$activities = [];

// Reservations
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, end_date, status, approval_status, created_at, ref_code FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
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
        
        $statusVal = $row['status'] ?? 'pending';
        if (!empty($row['approval_status'])) {
            $statusVal = $row['approval_status'];
        }

        $activities[] = [
            'type' => 'reservation',
            'title' => 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity'),
            'details' => $dateStr,
            'status' => $statusVal,
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
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
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
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Top Header -->
    <header class="top-header">
      <div class="header-brand">
        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
        <img src="images/logo.svg" alt="Logo">
        <div class="brand-text">
          <span class="brand-main">VictorianPass</span>
          <span class="brand-sub">Victorian Heights Subdivision</span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <a href="#" class="user-profile" id="profileTrigger">
          <span class="user-name">Hi, <?php echo htmlspecialchars($firstName); ?></span>
          <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="user-avatar" id="headerProfileImg">
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
  </div>
</div>

<div id="activityModal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <div id="activityModalBody"></div>
  </div>
</div>

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

<!-- Hidden Pass Template for Generation -->
<div id="hiddenPassCard" style="position:fixed; left:-9999px; top:0; width:400px; background:#1e1e1e; color:#fff; font-family:'Poppins',sans-serif; border-radius:16px; overflow:hidden; padding-bottom:20px;">
    <div class="header" style="background:#000; padding:15px; text-align:center; border-bottom:1px solid #333;">
        <img src="images/logo.svg" style="height:32px; vertical-align:middle;">
        <span style="margin-left:10px; font-weight:600; font-size:1.1rem; color:#e5ddc6; vertical-align:middle;">Victorian Heights</span>
    </div>
    <div class="status-banner" style="padding:30px 20px; text-align:center; background-color:#22c55e; color:#000;">
        <div style="font-size:1.8rem; font-weight:800; text-transform:uppercase; margin-bottom:8px;">VALID ENTRY PASS</div>
        <div style="font-size:1rem; font-weight:500;">Access Granted</div>
    </div>
    <div class="qr-section" style="background:#fff; padding:20px; text-align:center;">
        <img id="passQR" src="" style="width:180px; height:180px; display:block; margin:0 auto;">
        <div style="color:#333; margin-top:10px; font-size:0.85rem; font-weight:500;">Present this QR code to the guard</div>
    </div>
    <div class="details" style="padding:24px;">
        <div style="margin-bottom:12px; font-size:0.85rem; color:#9bd08f; font-weight:700; text-transform:uppercase;">Pass Details</div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Pass Type</span>
            <span style="font-weight:600; font-size:1rem; color:#eee;">Visitor</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Name</span>
            <span id="passName" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Ref Code</span>
            <span id="passRef" style="font-weight:600; font-size:1rem; color:#eee; font-family:monospace;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:0; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Validity</span>
            <span id="passDate" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
    </div>
    <div style="text-align:center; color:#666; font-size:0.8rem; margin-top:20px;">
        VictorianPass Validation System &copy; <?php echo date('Y'); ?>
    </div>
</div>

<script>
(function(){
  var visitorName = "<?php echo htmlspecialchars($fullName); ?>";
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
      li.setAttribute('data-status','cancelled');
      prevStatuses[ref]='cancelled';
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('cancelled');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
        badge.classList.add(statusClassFor('cancelled'));
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

  // Sidebar Toggle Logic
  var menuToggle = document.getElementById('menuToggle');
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  if(menuToggle && sidebar && overlay) {
      function closeSidebar() {
          sidebar.classList.remove('open');
          overlay.classList.remove('show');
      }

      menuToggle.addEventListener('click', function() {
          sidebar.classList.add('open');
          overlay.classList.add('show');
      });

      overlay.addEventListener('click', closeSidebar);
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
    else if(s.indexOf('cancelled')!==-1) statusNote='This request was cancelled by the user.';
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
        html+='<div class="item-extra-title">Entry QR Pass</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-action">';
        html+='<button class="btn-download-pass" onclick="generateAndDownloadPass(\''+esc(ref)+'\', \''+esc(visitorName)+'\', \''+esc(detailsEl.textContent.replace(/^\s*-\s*/,'').trim())+'\', \'Visitor\')"><i class="fa-solid fa-download"></i> Download Entry Pass</button>';
        html+='</div>';
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
        html+='</div></div></div>';
      }else{
        html+='<button type="button" class="item-extra-link view-details-btn" data-ref="'+esc(ref)+'">View details</button>';
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
    var viewBtn=extra.querySelector('.view-details-btn');
    if(viewBtn && ref){
      viewBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openActivityModal(ref);
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

  // Make function global so inline onclick can access it
  window.generateAndDownloadPass = function(ref, name, details, typeLabel) {
    var card = document.getElementById('hiddenPassCard');
    var qrImg = document.getElementById('passQR');
    var nameEl = document.getElementById('passName');
    var refEl = document.getElementById('passRef');
    var dateEl = document.getElementById('passDate');
    
    // Populate
    nameEl.textContent = name;
    refEl.textContent = ref;
    dateEl.textContent = details; 
    
    // Generate QR URL
    var basePath = window.location.pathname.replace(/\/[^\/]*$/,'');
    var verifyLink = window.location.origin + basePath + '/qr_view.php?code=' + encodeURIComponent(ref);
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(verifyLink);
    
    // Set QR and wait for load
    qrImg.onload = function() {
        html2canvas(card, {
            scale: 2,
            useCORS: true,
            backgroundColor: null 
        }).then(function(canvas) {
            var link = document.createElement('a');
            link.download = 'EntryPass_' + ref + '.png';
            link.href = canvas.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    };
    qrImg.src = qrUrl;
  };

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

  // Modal handling
  var activityModal = document.getElementById('activityModal');
  var activityModalBody = document.getElementById('activityModalBody');
  var activityModalClose = activityModal ? activityModal.querySelector('.close') : null;

  window.openActivityModal = function(refCode) {
    if (!activityModal || !activityModalBody) {
      activityModal = document.getElementById('activityModal');
      activityModalBody = document.getElementById('activityModalBody');
      activityModalClose = activityModal ? activityModal.querySelector('.close') : null;
      if (activityModalClose) {
          activityModalClose.onclick = function() {
            activityModal.style.display = 'none';
          };
      }
      if (!activityModal || !activityModalBody) return;
    }
    activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
    activityModal.style.display = 'block';

    fetch('get_activity_details.php?code=' + encodeURIComponent(refCode))
      .then(r => r.text())
      .then(html => {
        activityModalBody.innerHTML = html;
      })
      .catch(() => {
        activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;color:red;">Failed to load details.</div>';
      });
  };

  if (activityModalClose) {
    activityModalClose.onclick = function() {
      activityModal.style.display = 'none';
    };
  }

  window.onclick = function(event) {
    if (event.target == activityModal) {
      activityModal.style.display = 'none';
    }
  };
})();
</script>
<div id="profileModal" class="profile-modal">
  <div class="profile-modal-content">
    <button class="close-profile-modal">&times;</button>
    <div class="profile-header">
      <div class="profile-icon-large">
        <img src="<?php echo $profilePicUrl; ?>" alt="Profile" id="profileModalImg">
        <label for="profileUpload" class="profile-edit-overlay" title="Change Profile Picture">
           <i class="fa-solid fa-camera"></i>
        </label>
        <input type="file" id="profileUpload" accept="image/*" style="display:none">
      </div>
      <div class="profile-title">
        <h3><?php echo htmlspecialchars($fullName); ?></h3>
        <span class="profile-role">Visitor</span>
      </div>
    </div>
    <div class="profile-details">
      <div class="detail-row">
        <div class="detail-label">Name</div>
        <div class="detail-value"><?php echo htmlspecialchars($fullName); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Email</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Contact Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['phone'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Sex</div>
        <div class="detail-value"><?php echo htmlspecialchars(ucfirst($user_data['sex'] ?? '')); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Birthdate</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['birthdate'] ?? ''); ?></div>
      </div>
    </div>
    <div class="profile-actions">
       <a href="logout.php" class="btn-logout-modal">Log Out</a>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var profileModal = document.getElementById("profileModal");
    var profileTrigger = document.getElementById("profileTrigger");
    var profileClose = document.getElementsByClassName("close-profile-modal")[0];

    if(profileTrigger) {
        profileTrigger.onclick = function(e) {
            e.preventDefault();
            profileModal.style.display = "block";
        }
    }

    if(profileClose) {
        profileClose.onclick = function() {
            profileModal.style.display = "none";
        }
    }

    window.onclick = function(event) {
        if (event.target == profileModal) {
            profileModal.style.display = "none";
        }
    }

    // Profile Picture Upload
    var profileUpload = document.getElementById('profileUpload');
    if(profileUpload) {
        profileUpload.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var formData = new FormData();
                formData.append('profile_pic', this.files[0]);

                var img = document.getElementById('profileModalImg');
                img.style.opacity = '0.5';

                fetch('upload_profile_pic.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    img.style.opacity = '1';
                    if (data.success) {
                        if(img) img.src = data.new_url;
                        var headerImg = document.getElementById('headerProfileImg');
                        if(headerImg) headerImg.src = data.new_url;
                    } else {
                        alert(data.message || 'Upload failed');
                    }
                })
                .catch(error => {
                    img.style.opacity = '1';
                    console.error('Error:', error);
                    alert('An error occurred during upload.');
                });
            }
        });
    }
});
</script>
</body>
</html>
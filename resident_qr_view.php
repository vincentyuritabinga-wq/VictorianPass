<?php
session_start();
include 'connect.php';

$rid = isset($_GET['rid']) ? intval($_GET['rid']) : 0;
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

function vp_resident_link($rid){
  $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
  return sprintf('%s://%s%s/resident_qr_view.php?rid=%d', $scheme, $host, $basePath, $rid);
}

$user = null;
if ($con instanceof mysqli) {
  if ($rid > 0) {
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, IFNULL(status,'active') as status FROM users WHERE id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) { $user = $res->fetch_assoc(); }
    $stmt->close();
  }
}

if (!$user) { $error = 'Resident not found.'; }

$fullName = '';
if ($user) {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}
// Normalize phone to 09 format if stored as +63
$displayPhone = '';
if ($user) {
  $displayPhone = $user['phone'] ?? '';
  if (preg_match('/^\+63(9\d{9})$/', $displayPhone)) { $displayPhone = '0' . substr($displayPhone, 3); }
}
$link = ($user ? vp_resident_link(intval($user['id'])) : vp_resident_link($rid));
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($link);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Resident ID - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet" />
  <style>
    * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:#0f0f0f; }
    .wrap { padding: 24px; }
    .card { width: 360px; max-width: 92vw; background: #1e1e1e; border-radius: 16px; box-shadow: 0 10px 28px rgba(0,0,0,0.35); overflow: hidden; color:#fff; }
    .card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#111; }
    .brand { display:flex; align-items:center; gap:8px; }
    .brand img { height:28px; }
    .brand .text { font-weight:700; color:#e5ddc6; }
    .id-top { display:flex; gap:12px; padding:14px 16px; background:#181818; }
    .avatar { width:160px; height:160px; border-radius:12px; background:#ffffff; color:#23412e; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.4rem; overflow:hidden; }
    .avatar img { width:100%; height:100%; object-fit:contain; }
    .top-info { flex:1; }
    .top-info .name { font-size:1.05rem; font-weight:700; margin:0 0 4px 0; color:#eaeaea; }
    .top-info .contact { font-size:0.86rem; color:#cfcfcf; }
    .divider { height:1px; background:#2f2f2f; margin:0 16px; }
    .id-body { padding:14px 16px; }
    .row { display:flex; align-items:center; justify-content:space-between; margin:6px 0; }
    .label { color:#bdbdbd; font-weight:600; font-size:0.85rem; }
    .value { color:#eaeaea; font-size:0.95rem; }
    .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:0.85rem; font-weight:700; }
    .badge.active { background:#23412e; color:#e5ddc6; }
    .badge.disabled { background:#a83b3b; color:#fff; }
    .foot { padding:10px 16px; color:#aaa; font-size:0.82rem; }
    .download-btn { background:#e5ddc6; color:#23412e; border:none; border-radius:8px; padding:8px 12px; font-weight:700; text-decoration:none; display:inline-block; margin-top:6px; }
    @media (max-width:420px){ .id-top{ gap:10px; } .avatar{ width:120px; height:120px; } }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="card-header">
        <div class="brand"><img src="images/logo.svg" alt="VictorianPass"><div class="text">VictorianPass</div></div>
      </div>
      <?php if (!empty($error)): ?>
        <div class="foot" style="color:#ffb3b3">⚠️ <?php echo htmlspecialchars($error); ?></div>
      <?php else: ?>
      <div class="id-top">
        <div class="avatar"><img src="<?php echo htmlspecialchars($qrImg); ?>" alt="Resident QR" crossorigin="anonymous"></div>
        <div class="top-info">
          <div class="name"><?php echo htmlspecialchars($fullName ?: 'Resident'); ?></div>
          <div class="contact"><?php echo htmlspecialchars($user['email'] ?? ''); ?><?php if(!empty($displayPhone)){ echo ' • ' . htmlspecialchars($displayPhone); } ?></div>
        </div>
      </div>
      <div class="divider"></div>
      <div class="id-body">
        <div class="row"><div class="label">Block</div><div class="value"><?php echo htmlspecialchars($user['house_number'] ?? '-'); ?></div></div>
        <div class="row"><div class="label">Unit / Address</div><div class="value"><?php echo htmlspecialchars($user['address'] ?? '-'); ?></div></div>
        <div class="row"><div class="label">Contact</div><div class="value"><?php echo htmlspecialchars($displayPhone ?: '-') ; ?></div></div>
        <div class="row"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($user['email'] ?? '-') ; ?></div></div>
        <div class="row"><div class="label">Status</div><div class="value"><span class="badge <?php echo ((strtolower($user['status']??'active')==='active')?'active':'disabled'); ?>"><?php echo htmlspecialchars(ucfirst($user['status'] ?? 'Active')); ?></span></div></div>
      </div>
      <div class="divider"></div>
      <div class="foot">Scan QR to open this digital ID • Code linked to resident profile<br>
        <a class="download-btn" href="#" onclick="downloadResidentQR();return false;">Download QR</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
    function downloadResidentQR(){
      var target = document.querySelector('.card');
      if(!target) return;
      var fname = 'Resident_' + (<?php echo json_encode($user['id'] ?? $rid); ?> || 'ID') + '_QR.png';
      html2canvas(target, {scale:2, useCORS:true, backgroundColor:null}).then(function(canvas){
        var link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = fname;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
    }
  </script>
</body>
</html>

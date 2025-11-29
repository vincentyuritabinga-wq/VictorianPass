<?php
session_start();
require_once 'connect.php';

// Restrict to logged-in residents
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details
if ($con) {
  $stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, address FROM users WHERE id = ?");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) === 1) {
      $user = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
  }
}

if (!$user) {
  header('Location: mainpage.php');
  exit;
}

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$address = $user['address'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resident Report</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
    body { margin: 0; background: #F3ECDC; color: #333; }
    .navbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 5%; background: #2b2623; }
    .logo { display: flex; align-items: center; gap: 12px; }
    .logo img { width: 40px; }
    .brand-text h1 { margin: 0; font-size: 1.2rem; color: #f4f4f4; font-weight: 600; }
    .brand-text p { margin: 0; font-size: 0.8rem; color: #aaa; font-weight: 400; }
    .nav-actions { display: flex; align-items: center; gap: 12px; }
    .profile-icon { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; cursor: pointer; }
    .container { display: grid; grid-template-columns: 420px 1fr; gap: 22px; padding: 28px 40px; align-items: start; }
    @media (max-width: 900px){ .container{ grid-template-columns: 1fr; } }
    .explanation { background: #2b2b2b; color: #eee; padding: 22px; border-radius: 12px; box-shadow: 0 6px 12px rgba(0,0,0,0.25); line-height: 1.65; border: 1px solid #3a3a3a; }
    .explanation .brand-line { display:flex; align-items:center; gap:10px; margin: 10px 0 12px; }
    .explanation .brand-line .line { flex:1; height: 3px; background: #e5b84a; border-radius: 2px; }
    .explanation h2 { margin: 0; font-size: 20px; font-weight: 700; color:#f0f0f0; }
    .explanation p { font-size: 14px; margin-bottom: 14px; }
    .explanation .btn { display: inline-flex; align-items:center; justify-content:center; gap:8px; margin: 10px 6px 0 0; padding: 10px 16px; background: #23412e; color: white; text-decoration: none; font-size: 14px; border-radius: 28px; box-shadow: 0 3px 8px rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08) }
    .report-card { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 6px 16px rgba(0,0,0,0.18); border:1px solid rgba(0,0,0,0.06) }
    .report-header { text-align:center; margin-bottom:8px }
    .report-header h2 { margin: 0; font-size: 20px; color: #23412e; font-weight:800 }
    .report-sub { font-size: 12px; color:#666; margin: 2px 0 10px; }
    .report-title { text-align:center; font-weight:800; margin: 8px 0 16px; }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; margin-bottom: 6px; font-size: 14px; color: #222; }
    .form-group input[type="text"], .form-group input[type="email"], .form-group textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; font-size: 14px; }
    .checkbox-group { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-top: 10px; }
    .checkbox-group label { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    .upload-box { border: 2px dashed #aaa; padding: 16px; text-align: center; border-radius: 8px; background: #f9f9f9; cursor: pointer; transition: all 0.3s ease; }
    .upload-box img { width: 50px; margin-bottom: 8px; }
    .upload-box:hover { background: #f0f0f0; transform: scale(1.02); }
    .file-list { margin-top: 10px; font-size: 13px; color: #444; line-height: 1.4; }
    .file-list div { margin-bottom: 4px; word-break: break-word; }
    .next-btn { margin-top: 25px; padding: 12px 20px; background: #23412e; color: white; border: none; border-radius: 10px; cursor: pointer; width: 160px; font-size: 16px; font-weight: 700; position: relative; overflow: hidden; transition: transform 0.25s ease, box-shadow 0.25s ease; float:right }
    .next-btn:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(229,221,198,0.4); }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; z-index: 1000; }
    .modal-content { background: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 700px; max-height: 80vh; overflow-y: auto; box-shadow: 0 6px 18px rgba(0,0,0,0.3); position: relative; line-height: 1.6; }
    .close-btn { position: absolute; top: 12px; right: 15px; font-size: 22px; cursor: pointer; color: #333; }
  </style>
</head>
<body>
  <!-- HEADER -->
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
    <div class="nav-actions">
      <a href="profileresident.php"><img src="images/mainpage/profile'.jpg" alt="Profile" class="profile-icon"></a>
    </div>
  </header>

  <div class="container">
    <!-- Left Side -->
    <div class="explanation">
      <div class="brand-line"><span class="line"></span><img src="images/logo.svg" alt="Logo" style="width:26px;height:26px"><span class="line"></span></div>
      <h2>Resident Report</h2>
      <p>Reporting complaints ensures that every resident’s voice is heard and that community standards are properly maintained. By addressing issues early, we promote a safe, fair, and harmonious environment for all homeowners.</p>
      <p>Please be responsible when filing a complaint. Submitting truthful and well-documented concerns helps the association act effectively.</p>
      <a class="btn" href="#" onclick="openModal('termsModal')">Terms and Conditions</a>
      <a class="btn" href="#" onclick="openModal('rulesModal')">Rules and Regulations</a>
    </div>

    <!-- Right Side -->
    <div class="report-card">
      <div class="report-header">
        <h2>Victorian Heights Subdivision</h2>
        <div class="report-sub">Dahlia Fairview, BRGY. Sauyo, Quezon City</div>
        <div class="report-sub">Magna Carta for Homeowners and Homeowners’ Associations &nbsp; — &nbsp; REPUBLIC ACT NO. 9904</div>
        <div class="report-title">Case Report</div>
      </div>

      <input type="hidden" id="complainant" name="complainant" value="<?php echo htmlspecialchars($fullName); ?>">

      <div style="display:grid;grid-template-columns:1fr 220px;gap:12px;margin-bottom:8px">
        <div class="form-group" style="margin:0">
          <label for="subject">Complainee</label>
          <input type="text" id="subject" name="subject" placeholder="Name" required>
        </div>
        <div class="form-group" style="margin:0">
          <label for="reportDate">Date</label>
          <input type="date" id="reportDate" name="report_date" placeholder="Date">
        </div>
      </div>

      <div class="form-group">
        <label for="address">Address</label>
        <input type="text" id="address" name="address" placeholder="Enter your address" value="<?php echo htmlspecialchars($address); ?>" required>
      </div>

      <div class="form-group">
        <label>Nature of Concern</label>
        <div class="checkbox-group">
          <label><input type="checkbox" name="nature[]" value="Public nuisance"> Public nuisance</label>
          <label><input type="checkbox" name="nature[]" value="Amenity misuse"> Amenity misuse</label>
          <label><input type="checkbox" name="nature[]" value="Dispute"> Dispute</label>
          <label><input type="checkbox" name="nature[]" value="Breach of Rules"> Breach of Rules</label>
        </div>
      </div>

      <div class="form-group">
        <label for="other">Other concern</label>
        <input type="text" id="other" name="other" placeholder="Specify here">
      </div>

      <!-- Attach Proof -->
      <div class="form-group">
        <label for="proof">Attach Proof (optional)</label>
        <div class="upload-box" onclick="document.getElementById('proof').click()">
          <input type="file" id="proof" name="proof[]" multiple hidden accept="image/*,.pdf,.doc,.docx">
          <img src="images/mainpage/upload.svg" alt="Upload Icon">
          <p>Click to upload photos or documents<br><small>(images, PDF, or Word files)</small></p>
        </div>
        <div id="fileList" class="file-list"></div>
      </div>

      <button id="submitBtn" class="next-btn">Next</button>
    </div>
  </div>

  <!-- Terms Modal -->
  <div class="modal" id="termsModal">
    <div class="modal-content">
      <span class="close-btn" onclick="closeModal('termsModal')">&times;</span>
      <h3>Terms and Conditions</h3>
      <p>By submitting this complaint form, you agree to the following:</p>
      <p>1. Complaints must be submitted in good faith and based on facts.<br>2. False or malicious complaints may result in penalties.<br>3. The HOA reserves the right to investigate and validate all concerns.<br>4. Confidentiality will be respected, but information may be shared when necessary.<br>5. Filing a complaint does not guarantee resolution in favor of the complainant.<br>6. All parties involved will be treated fairly during the resolution process.</p>
    </div>
  </div>

  <!-- Rules Modal -->
  <div class="modal" id="rulesModal">
    <div class="modal-content">
      <span class="close-btn" onclick="closeModal('rulesModal')">&times;</span>
      <h3>Rules and Regulations</h3>
      <p>All users must follow community rules. Misuse of the system may lead to suspension or blacklisting.</p>
    </div>
  </div>

  <!-- Image Preview Modal -->
  <div class="modal" id="imgPreviewModal">
    <div class="modal-content" style="max-width:860px">
      <span class="close-btn" onclick="closeModal('imgPreviewModal')">&times;</span>
      <img id="imgPreviewTag" src="" alt="Preview" style="width:100%;height:auto;border-radius:10px" />
    </div>
  </div>

  <script>
    function openModal(id){ document.getElementById(id).style.display="flex"; }
    function closeModal(id){ document.getElementById(id).style.display="none"; }
    window.onclick=function(e){
      if(e.target.id==="termsModal") closeModal('termsModal');
      if(e.target.id==="rulesModal") closeModal('rulesModal');
    }

    const proofInput = document.getElementById('proof');
    const fileList   = document.getElementById('fileList');
    proofInput.addEventListener('change', () => {
      fileList.innerHTML = '';
      [...proofInput.files].forEach(f => {
        const item = document.createElement('div');
        const isImg = (f.type||'').startsWith('image/');
        if(isImg){
          const url = URL.createObjectURL(f);
          const thumb = document.createElement('img');
          thumb.src = url;
          thumb.style.width='64px'; thumb.style.height='64px'; thumb.style.objectFit='cover';
          thumb.style.borderRadius='8px'; thumb.style.marginRight='8px'; thumb.style.cursor='pointer';
          thumb.addEventListener('click',()=>{ document.getElementById('imgPreviewTag').src=url; openModal('imgPreviewModal'); });
          const name=document.createElement('span'); name.textContent=f.name;
          item.appendChild(thumb); item.appendChild(name);
        } else {
          item.textContent = `• ${f.name}`;
        }
        fileList.appendChild(item);
      });
    });

    document.getElementById('submitBtn').addEventListener('click', async () => {
      const complainant = document.getElementById('complainant').value.trim();
      const subject = document.getElementById('subject').value.trim();
      const addr = document.getElementById('address').value.trim();
      const rdate = document.getElementById('reportDate').value.trim();
      const other = document.getElementById('other').value.trim();

      if (!subject || !addr) {
        alert('Please provide complainee name and address.');
        return;
      }

      const formData = new FormData();
      formData.append('complainant', complainant);
      formData.append('subject', subject);
      formData.append('report_date', rdate);
      formData.append('address', addr);
      formData.append('other', other);
      document.querySelectorAll('input[name="nature[]"]:checked').forEach(cb => {
        formData.append('nature[]', cb.value);
      });
      [...proofInput.files].forEach(file => formData.append('proof[]', file));

      try {
        const resp = await fetch('submit_report.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
          alert(`Report submitted! Reference: ${data.reference}`);
          document.getElementById('complainant').value = '';
          document.getElementById('address').value = '';
          document.getElementById('other').value = '';
          document.querySelectorAll('input[name="nature[]"]').forEach(cb => cb.checked = false);
          proofInput.value = '';
          fileList.innerHTML = '';
        } else {
          alert(data.message || 'Submission failed');
        }
      } catch (e) {
        alert('Network error while submitting report');
      }
    });
  </script>
</body>
</html>

<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";
$message_type = "";

$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id;

// Standard POST Form Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['username']);
    $group = trim($_POST['group_name'] ?? '');
    
    $upload_dir = '../uploads/profiles/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    // Profile Picture Upload
    $pfp_dest = null;
    if (isset($_FILES['pfp_file']) && $_FILES['pfp_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['pfp_file']['name'], PATHINFO_EXTENSION));
        $filename = "pfp_" . $user_id . "_" . time() . "." . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['pfp_file']['tmp_name'], $dest)) {
            $pfp_dest = $dest;
        }
    } elseif (isset($_POST['selected_avatar']) && !empty($_POST['selected_avatar'])) {
        $pfp_dest = $_POST['selected_avatar'];
    }

    // Banner Upload
    $banner_dest = null;
    if (isset($_FILES['banner_file']) && $_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION));
        $filename = "banner_" . $user_id . "_" . time() . "." . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['banner_file']['tmp_name'], $dest)) {
            $banner_dest = $dest;
        }
    }

    // Update query
    if ($pfp_dest && $banner_dest) {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ?, profile_pic = ?, banner_pic = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $pfp_dest, $banner_dest, $user_id]);
    } elseif ($pfp_dest) {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ?, profile_pic = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $pfp_dest, $user_id]);
    } elseif ($banner_dest) {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ?, banner_pic = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $banner_dest, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $user_id]);
    }

    if ($success) {
        $_SESSION['username'] = $name;
        $_SESSION['research_group_name'] = $group;
        $message = "Profile successfully updated.";
        $message_type = "success";
    } else {
        $message = "Failed to update profile.";
        $message_type = "error";
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

function getStageProgressForProfile($pdo, $userId, $itemIds) {
    if (empty($itemIds)) return 0;
    $total_items = count($itemIds);
    $approved_items = 0;
    foreach ($itemIds as $itemId) {
        $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
        $stmt->execute([$userId, $itemId]);
        if ($stmt->fetchColumn() === 'Approved') $approved_items++;
    }
    return round(($approved_items / $total_items) * 100);
}
function getSpecificItemProgressForProfile($pdo, $userId, $itemId) {
    $stmt_any = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id = ?");
    $stmt_any->execute([$userId, $itemId]);
    if ($stmt_any->fetchColumn() == 0) return 0;
    $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$userId, $itemId]);
    $s = $stmt->fetchColumn();
    if ($s === 'Approved') return 100;
    if ($s === 'Under Review') return 75;
    if ($s === 'Revision Requested') return 50;
    return 25;
}

$p1 = getStageProgressForProfile($pdo, $effective_user_id, [11, 12, 13, 14, 15, 16]); 
$p2 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 5); 
$p3 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 3); 
$p4 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 4); 

$overall_avg = ($p1 + $p2 + $p3 + $p4) / 4;

if ($overall_avg >= 100) { $rank_title = "Legend"; $rank_dot = "#fbbf24"; } 
elseif ($overall_avg >= 80) { $rank_title = "Epic"; $rank_dot = "#a855f7"; } 
elseif ($overall_avg >= 60) { $rank_title = "Grandmaster"; $rank_dot = "#ef4444"; } 
elseif ($overall_avg >= 40) { $rank_title = "Master"; $rank_dot = "#3b82f6"; } 
elseif ($overall_avg >= 20) { $rank_title = "Elite"; $rank_dot = "#64748b"; } 
else { $rank_title = "Warrior"; $rank_dot = "#94a3b8"; }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Full Width</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --bg-white: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-line: #e2e8f0;
            --border-light: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-subtle: #e2e8f0;
            --mcnp-teal: #0f172a; 
            --banner-fallback: linear-gradient(135deg, #f1f5f9, #e2e8f0);
        }

        body.theme-default, body.theme-blue { --bg-canvas: #f0f4f9; --bg-white: #ffffff; --text-dark: #0f172a; --mcnp-teal: #1e40af; --banner-fallback: linear-gradient(135deg, #dbeafe, #bfdbfe); }
        body.theme-red { --bg-canvas: #fef2f2; --bg-white: #ffffff; --text-dark: #b91c1c; --mcnp-teal: #b91c1c; --banner-fallback: linear-gradient(135deg, #fee2e2, #fecaca); }
        body.theme-green { --bg-canvas: #f0fdf4; --bg-white: #ffffff; --text-dark: #15803d; --mcnp-teal: #15803d; --banner-fallback: linear-gradient(135deg, #dcfce7, #bbf7d0); }
        body.theme-pink, body.theme-rose { --bg-canvas: #fdf2f8; --bg-white: #ffffff; --text-dark: #be185d; --mcnp-teal: #be185d; --banner-fallback: linear-gradient(135deg, #fce7f3, #fbcfe8); }
            --banner-fallback: #e2e8f0;
        }

        body.theme-default, body.theme-blue { --bg-canvas: #f0f4f9; --bg-white: #ffffff; --text-dark: #0f172a; --mcnp-teal: #1e40af; --banner-fallback: #bfdbfe; }
        body.theme-red { --bg-canvas: #fef2f2; --bg-white: #ffffff; --text-dark: #b91c1c; --mcnp-teal: #b91c1c; --banner-fallback: #fecaca; }
        body.theme-green { --bg-canvas: #f0fdf4; --bg-white: #ffffff; --text-dark: #15803d; --mcnp-teal: #15803d; --banner-fallback: #bbf7d0; }
        body.theme-pink, body.theme-rose { --bg-canvas: #fdf2f8; --bg-white: #ffffff; --text-dark: #be185d; --mcnp-teal: #be185d; --banner-fallback: #fbcfe8; }
        body.theme-purple, body.theme-lavender { --bg-canvas: #f5f3ff; --bg-white: #ffffff; --text-dark: #6d28d9; --mcnp-teal: #6d28d9; --banner-fallback: #ddd6fe; }
        body.theme-orange, body.theme-amber { --bg-canvas: #fffbeb; --bg-white: #ffffff; --text-dark: #b45309; --mcnp-teal: #b45309; --banner-fallback: #fde68a; }
        body.theme-dark { --bg-canvas: #0f172a; --bg-card: #1e293b; --bg-white: #1e293b; --text-dark: #f8fafc; --text-primary: #f8fafc; --text-secondary: #94a3b8; --text-muted: #94a3b8; --border-line: #334155; --border-light: #0f172a; --border-subtle: #334155; --mcnp-teal: #38bdf8; --banner-fallback: #334155; }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body { 
            font-family: var(--ui-sans); 
            background: transparent;
            color: var(--text-dark); 
            min-height: 100vh;
            padding: 0; /* Let banner stretch */
            overflow-x: hidden;
        }

        /* Full Width Banner */
        .banner {
            width: calc(100% - 40px);
            max-width: 1160px;
            margin: 20px auto 0;
            border-radius: 24px;
            height: 160px; /* Shorter banner */
            background: var(--banner-fallback);
            position: relative;
            cursor: pointer;
            transition: opacity 0.2s;
            overflow: hidden; /* Prevent image from squaring the corners */
        }
        .banner:hover { opacity: 0.9; }
        .banner img { width: 100%; height: 100%; object-fit: cover; }
        
        .banner-btn {
            position: absolute; right: 16px; bottom: 16px;
            background: rgba(0,0,0,0.4); color: white; border: none;
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s;
            pointer-events: none; /* Let the banner handle the click */
        }
        .banner:hover .banner-btn { background: rgba(0,0,0,0.7); }

        /* Main Content Container (Not a boxed modal) */
        .page-content {
            padding: 0 40px;
            max-width: 1200px; /* Wider to breathe */
            margin: 0 auto;
        }

        /* Massive Avatar Layout */
        .profile-header {
            display: flex;
            align-items: flex-end;
            margin-top: -75px; /* Overlap banner */
            position: relative;
            z-index: 10;
            margin-bottom: 40px;
        }

        .pfp-container {
            position: relative;
            width: 150px; /* HUGE Avatar */
            height: 150px;
            border-radius: 50%;
            padding: 6px;
            background: var(--bg-canvas); /* Match page background */
            margin-right: 30px;
            flex-shrink: 0;
        }
        .pfp-container img {
            width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
        }
        .upload-btn {
            position: absolute; bottom: 8px; right: 8px;
            background: var(--bg-white); border: 1px solid var(--border-light);
            color: var(--text-muted); width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .upload-btn:hover { color: var(--text-dark); border-color: var(--border-line); transform: translateY(-2px); }
        .upload-btn i { width: 18px; height: 18px; }

        .user-info { flex: 1; padding-bottom: 15px; }
        .user-name { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
        
        .minimal-badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 14px; font-weight: 600; color: var(--text-secondary);
        }
        .badge-dot { width: 10px; height: 10px; border-radius: 50%; background: <?= $rank_dot ?>; }

        /* Form Area - Let it breathe */
        .minimal-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .field-group { display: flex; flex-direction: column; gap: 8px; }
        .field-group.full { grid-column: 1 / -1; }
        
        .field-group label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        
        .minimal-input {
            width: 100%; padding: 14px 18px;
            border: 1px solid var(--border-light);
            border-radius: 16px; background: var(--bg-white);
            font-family: var(--ui-sans); font-size: 15px;
            color: var(--text-dark); transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .minimal-input:focus {
            outline: none; border-color: var(--border-line);
            box-shadow: 0 4px 12px -4px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .minimal-input:disabled {
            background: var(--bg-canvas); color: var(--text-muted); cursor: not-allowed; border-style: dashed; box-shadow: none; transform: none;
        }

        .btn-save {
            background: var(--text-dark); color: white;
            border: none; padding: 12px 28px; border-radius: 999px;
            font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s;
            font-family: var(--ui-sans); box-shadow: 0 2px 6px rgba(0,0,0,0.08); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-save:hover { opacity: 0.9; transform: scale(1.02); }

        /* Dicebear Gallery */
        .gallery-wrap {
            margin-bottom: 32px; /* Replaces top margin/border */
        }
        .gallery-title { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        .avatar-grid { 
            display: flex; gap: 16px; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 8px;
            /* Hide scrollbar */
            scrollbar-width: none; 
            -ms-overflow-style: none;
        }
        .avatar-grid::-webkit-scrollbar { display: none; }
        
        .avatar-item {
            width: 56px; height: 56px; border-radius: 50%; cursor: pointer; flex-shrink: 0;
            transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 2px solid transparent; background: var(--bg-white);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .avatar-item:hover { transform: scale(1.1) translateY(-2px); }
        .avatar-item.active { border-color: var(--mcnp-teal); box-shadow: 0 2px 10px rgba(0,0,0,0.1); transform: scale(1.1); }

        /* Toast */
        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(100px);
            background: #1e293b; color: white; padding: 12px 24px; border-radius: 30px;
            font-size: 14px; font-weight: 500; opacity: 0; transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 1000; display: flex; align-items: center; gap: 8px;
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        /* Header Bar for action buttons */
        .page-actions {
            display: flex; justify-content: flex-end; margin-bottom: 20px;
        }

        /* Mobile */
        @media (max-width: 768px) {
            .page-content { padding: 0 16px; }
            .minimal-form { grid-template-columns: 1fr; gap: 16px; }
            
            .banner { width: calc(100% - 24px); margin: 12px auto 0; border-radius: 16px; height: 130px; }
            
            .profile-header { flex-direction: column; align-items: center; text-align: center; margin-top: -55px; margin-bottom: 24px; padding: 0 12px; }
            .pfp-container { width: 110px; height: 110px; margin-right: 0; margin-bottom: 12px; }
            .upload-btn { width: 32px; height: 32px; bottom: 4px; right: 4px; }
            .upload-btn i { width: 14px; height: 14px; }
            
            .user-name { font-size: 24px; }
            .minimal-badge { justify-content: center; font-size: 13px; }
            
            .page-actions { justify-content: center; }
            .btn-save { justify-content: center; }
        }
    </style>
</head>

<body>
    
    <form method="POST" enctype="multipart/form-data">

        <!-- Full Width Banner -->
        <?php 
            $has_banner = !empty($user['banner_pic']) && file_exists($user['banner_pic']); 
            $banner_src = $has_banner ? htmlspecialchars($user['banner_pic']) : '';
        ?>
        <div class="banner" onclick="document.getElementById('bannerInput').click()">
            <?php if ($has_banner): ?>
                <img src="<?= $banner_src ?>" id="bannerPreview">
            <?php else: ?>
                <img src="" id="bannerPreview" style="display:none;">
            <?php endif; ?>
            <div class="banner-btn"><i data-lucide="camera" style="width:18px;height:18px;"></i></div>
        </div>

        <div class="page-content">
            <div class="profile-header">
                <div class="pfp-container">
                    <?php
                    $current_pfp = $user['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($user['username']);
                    ?>
                    <img src="<?= htmlspecialchars($current_pfp) ?>" id="pfpPreview">
                    <div class="upload-btn" onclick="document.getElementById('pfpInput').click()">
                        <i data-lucide="camera"></i>
                    </div>
                </div>

                <div class="user-info">
                    <h1 class="user-name"><?= htmlspecialchars($user['username']) ?></h1>
                    <div class="minimal-badge">
                        <div class="badge-dot"></div>
                        <?= htmlspecialchars($user['role']) ?> • <?= $rank_title ?>
                    </div>
                </div>
            </div>

            <!-- Hidden Inputs for Files -->
            <input type="file" name="pfp_file" id="pfpInput" accept="image/*" style="display:none;" onchange="previewImage(this, 'pfpPreview')">
            <input type="file" name="banner_file" id="bannerInput" accept="image/*" style="display:none;" onchange="previewImage(this, 'bannerPreview')">
            <input type="hidden" name="selected_avatar" id="selectedAvatar" value="">

            <div class="gallery-wrap">
                <div class="gallery-title">Choose Avatar</div>
                <div class="avatar-grid" id="avatarGallery"></div>
            </div>

            <div class="minimal-form">
                <div class="field-group">
                    <label>Account Name</label>
                    <input type="text" class="minimal-input" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                
                <div class="field-group">
                    <label>Email Address</label>
                    <input type="email" class="minimal-input" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                </div>

                <div class="field-group full">
                    <label>Institution / Department</label>
                    <input type="text" class="minimal-input" value="<?= htmlspecialchars($user['department'] ?? 'Unassigned') ?>" disabled>
                </div>

                <?php if ($user['role'] === 'Student'): ?>
                    <div class="field-group full">
                        <label>Research Group Name</label>
                        <input type="text" class="minimal-input" name="group_name" value="<?= htmlspecialchars($user['research_group_name'] ?? '') ?>">
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="page-actions">
                <button type="submit" class="btn-save">Save Changes</button>
            </div>

        </div>
    </form>

    <div class="toast" id="toastMsg"><i data-lucide="<?= $message_type === 'success' ? 'check-circle' : 'alert-circle' ?>" style="width:16px;height:16px;"></i> <span></span></div>

    <script>
        lucide.createIcons();

        const syncTheme = () => {
            const t = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = t;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);

        <?php if ($message): ?>
            showToast(<?= json_encode($message) ?>, <?= json_encode($message_type) ?>);
            <?php if ($message_type === 'success'): ?>
                setTimeout(() => {
                    if (window.parent && typeof window.parent.collapseZoomModules === 'function') {
                        window.parent.collapseZoomModules();
                    } else {
                        // Fallback if not inside the zoom module
                        window.parent.location.href = 'student.php';
                    }
                }, 1000);
            <?php endif; ?>
        <?php endif; ?>

        function showToast(msg, type) {
            const t = document.getElementById('toastMsg');
            t.querySelector('span').textContent = msg;
            t.style.background = (type === 'error') ? '#ef4444' : '#1e293b';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        // Simple local preview before saving
        function previewImage(input, imgId) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById(imgId);
                    img.src = e.target.result;
                    img.style.display = 'block';
                    // Clear the selected avatar if user chose a custom file
                    if(imgId === 'pfpPreview') {
                        document.getElementById('selectedAvatar').value = '';
                        document.querySelectorAll('.avatar-item').forEach(el => el.classList.remove('active'));
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Legends Gallery
        const heroSeeds = ['Felix', 'Aneka', 'Caleb', 'Jocelyn', 'Ginger', 'Boots', 'Jasper', 'Sheba'];
        const gallery = document.getElementById('avatarGallery');
        heroSeeds.forEach(seed => {
            const url = `https://api.dicebear.com/9.x/adventurer/svg?seed=${seed}`;
            const img = document.createElement('img');
            img.src = url;
            img.className = 'avatar-item';
            img.onclick = () => {
                document.querySelectorAll('.avatar-item').forEach(el => el.classList.remove('active'));
                img.classList.add('active');
                document.getElementById('pfpPreview').src = url;
                document.getElementById('selectedAvatar').value = url;
                document.getElementById('pfpInput').value = ""; // Clear file input
            };
            gallery.appendChild(img);
        });
    </script>
</body>
</html>

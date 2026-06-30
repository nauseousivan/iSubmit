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

// Determine the effective user_id for data retrieval (leader's ID if current user is a member)
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id; // Use leader_id if exists, otherwise current user_id

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['username']);
    $group = trim($_POST['group_name'] ?? '');
    $selected_avatar = $_POST['selected_avatar'] ?? null;
    $success = false;

    // Handle Custom File Upload
    if (isset($_FILES['pfp_file']) && $_FILES['pfp_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $ext = strtolower(pathinfo($_FILES['pfp_file']['name'], PATHINFO_EXTENSION));
        $filename = "pfp_" . $user_id . "_" . time() . "." . $ext;
        $dest = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['pfp_file']['tmp_name'], $dest)) {
            $selected_avatar = $dest;
        }
    }

    if ($selected_avatar) {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ?, profile_pic = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $selected_avatar, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, research_group_name = ? WHERE user_id = ?");
        $success = $stmt->execute([$name, $group, $user_id]);
    }

    if ($success) {
        $_SESSION['username'] = $name;
        $_SESSION['research_group_name'] = $group;
        $message = "Profile successfully updated.";
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Calculate Progress for Rank
function getStageProgressForProfile($pdo, $userId, $itemIds)
{
    if (empty($itemIds)) return 0;

    $total_items = count($itemIds);
    $approved_items = 0;

    foreach ($itemIds as $itemId) {
        $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
        $stmt->execute([$userId, $itemId]);
        $latest_status = $stmt->fetchColumn();

        if ($latest_status === 'Approved') {
            $approved_items++;
        }
    }
    return round(($approved_items / $total_items) * 100);
}
function getSpecificItemProgressForProfile($pdo, $userId, $itemId)
{
    // Check if any upload exists for this item
    $stmt_any = $pdo->prepare("SELECT COUNT(*) FROM uploads WHERE user_id = ? AND item_id = ?");
    $stmt_any->execute([$userId, $itemId]);
    $has_upload = $stmt_any->fetchColumn() > 0;

    if (!$has_upload) {
        return 0; // No upload, 0% progress
    }

    $stmt = $pdo->prepare("SELECT verification_status FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$userId, $itemId]);
    $status = $stmt->fetchColumn();
    if ($status === 'Approved') return 100;
    if ($status === 'Under Review') return 75;
    if ($status === 'Revision Requested') return 50;
    return 25; // Pending or any other status after initial upload
}

$p1 = getStageProgressForProfile($pdo, $effective_user_id, [11, 12, 13, 14, 15, 16]); // Proposal Defense Phase
$p2 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 5); // Final Defense (single item for now)
$p3 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 3); // Stats
$p4 = getSpecificItemProgressForProfile($pdo, $effective_user_id, 4); // Plagiarism

// Calculate overall average progress for rank
$overall_avg = ($p1 + $p2 + $p3 + $p4) / 4; // Average of the 4 main stages

if ($overall_avg >= 100) {
    $rank_title = "Legend";
    $rank_color = "var(--color-approved)";
} elseif ($overall_avg >= 80) {
    $rank_title = "Epic";
    $rank_color = "var(--mcnp-teal)";
} elseif ($overall_avg >= 60) {
    $rank_title = "Grandmaster";
    $rank_color = "var(--color-review)";
} elseif ($overall_avg >= 40) {
    $rank_title = "Master";
    $rank_color = "var(--accent-teal)";
} elseif ($overall_avg >= 20) {
    $rank_title = "Elite";
    $rank_color = "#A9A9A9";
} else {
    $rank_title = "Warrior";
    $rank_color = "var(--text-muted)";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Info | MCNP-ISAP</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

        :root {
            --bg-beige: #f9f7f2;
            --bg-white: #ffffff;
            --mcnp-teal: #0c343d;
            --mcnp-hover: #144652;
            --border-line: #e5e7eb;
            --text-muted: #6b7280;
            --text-dark: #1f2937;
            --bubbly-app-edge: 24px;
            --bubbly-ui-edge: 12px;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Cambria', serif;
            background-color: var(--bg-white);
            color: var(--text-dark);
            min-height: 100vh;
            padding: 24px;
            transition: 0.3s;
        }

        body::-webkit-scrollbar {
            display: none;
        }

        body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 28px;
        }

        .pfp-preview-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 30px;
        }

        .pfp-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            cursor: pointer;
            margin-bottom: 10px;
            overflow: hidden;
            border: 4px solid var(--mcnp-teal);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .pfp-wrapper:hover .pfp-overlay {
            opacity: 1;
        }

        .pfp-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: 0.3s;
            color: white;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .rank-badge-lg {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: <?= $rank_color ?>;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            border: 2px solid white;
            white-space: nowrap;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            z-index: 10;
        }

        .current-pfp-large {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: white;
        }

        .avatar-selector-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin: 15px 0;
            padding: 15px;
            background: var(--bg-beige);
            border-radius: 14px;
            border: 1px dashed var(--border-line);
        }

        .avatar-item {
            width: 100%;
            aspect-ratio: 1;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: 0.2s;
            background: white;
        }

        .avatar-item:hover {
            transform: scale(1.1);
            border-color: var(--accent-teal);
        }

        .avatar-item.active {
            border-color: var(--mcnp-teal);
            box-shadow: 0 0 0 3px rgba(12, 52, 61, 0.1);
        }

        .page-title h1 {
            font-family: var(--ui-sans);
            font-size: 32px;
            font-weight: 800;
            color: var(--mcnp-teal);
            margin-bottom: 4px;
            letter-spacing: -0.025em;
        }

        .page-title p {
            font-size: 15px;
            color: var(--text-muted);
        }

        .card {
            background: var(--bg-white);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid var(--border-line);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            font-family: var(--ui-sans);
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        input {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border-line);
            background: var(--bg-beige);
            font-family: var(--ui-sans);
            font-size: 14px;
            transition: 0.2s;
        }

        input:focus {
            outline: none;
            border-color: var(--mcnp-teal);
            background: var(--bg-white);
        }

        .btn-save {
            font-family: var(--ui-sans);
            background: linear-gradient(to bottom right, var(--mcnp-teal), var(--mcnp-hover));
            color: white;
            border: none;
            width: 100%;
            padding: 16px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            font-size: 15px;
            transition: 0.2s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .msg {
            color: #137333;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 20px;
            text-align: center;
            padding: 12px;
            background: #e6f4ea;
            border-radius: 8px;
            border-left: 4px solid #27ae60;
        }

        /* Mobile updates */
        @media (max-width: 640px) {
            body {
                padding: 12px !important;
            }

            .header {
                display: none !important;
            }

            .card {
                padding: 0 !important;
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
            }

            .avatar-selector-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 8px;
                padding: 10px !important;
                background-color: var(--bg-card, #ffffff) !important;
            }

            .pfp-wrapper {
                width: 90px !important;
                height: 90px !important;
            }

            input,
            select {
                padding: 12px !important;
                font-size: 13px !important;
                border-radius: 10px !important;
                background-color: var(--bg-card, #ffffff) !important;
            }

            .btn-save {
                padding: 14px !important;
                font-size: 14px !important;
                border-radius: 10px !important;
            }
        }

        body.theme-default,
        body.theme-blue {
            --bg-beige: #e8f0ff;
            --bg-white: #ffffff;
            --text-dark: #1c2a44;
            --text-muted: #5f6f8a;
            --border-line: #c6d4e9;
            --mcnp-teal: #4a7c8c;
            --mcnp-hover: #3b6370;
        }

        body.theme-red {
            --bg-beige: #ffe8e8;
            --bg-white: #ffffff;
            --text-dark: #4c1f20;
            --text-muted: #9d5b5c;
            --border-line: #f2c7c7;
            --mcnp-teal: #d65a5a;
            --mcnp-hover: #c04f4f;
        }

        body.theme-pink,
        body.theme-rose {
            --bg-beige: #fde8f5;
            --bg-white: #ffffff;
            --text-dark: #4c2346;
            --text-muted: #9f628d;
            --border-line: #f3c7dc;
            --mcnp-teal: #c56ba8;
            --mcnp-hover: #ac5e94;
        }

        body.theme-green {
            --bg-beige: #e8f6ea;
            --bg-white: #ffffff;
            --text-dark: #2f4a33;
            --text-muted: #6d8b75;
            --border-line: #c9dec9;
            --mcnp-teal: #4a9e7b;
            --mcnp-hover: #3a8565;
        }

        body.theme-purple,
        body.theme-lavender {
            --bg-beige: #f5f3ff;
            --bg-white: #ffffff;
            --text-dark: #4c1d95;
            --text-muted: #9c9284;
            --border-line: #ddd6fe;
            --mcnp-teal: #6d28d9;
            --mcnp-hover: #4c1d95;
        }

        body.theme-orange,
        body.theme-amber {
            --bg-beige: #fffbeb;
            --bg-white: #ffffff;
            --text-dark: #78350f;
            --text-muted: #9c9284;
            --border-line: #fde68a;
            --mcnp-teal: #b45309;
            --mcnp-hover: #78350f;
        }

        body.theme-dark {
            --bg-beige: #1a1d21;
            --bg-white: #24282d;
            --text-dark: #e0e0e0;
            --text-muted: #b0ada8;
            --border-line: #3a3f45;
            --mcnp-teal: #4e9cae;
            --mcnp-hover: #5fb3c8;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="page-title">
                <h1>Profile Info</h1>
                <p>Configure personal information and avatar settings.</p>
            </div>
        </div>

        <div class="card">
            <?php if ($message): ?><div class="msg"><?= $message ?></div><?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="pfp-preview-block">
                    <?php
                    $current_pfp = $user['profile_pic'] ?: "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($user['username']);
                    ?>
                    <div class="pfp-wrapper" onclick="document.getElementById('pfpInput').click()">
                        <img src="<?= htmlspecialchars($current_pfp) ?>" class="current-pfp-large" id="pfpPreview">
                        <div class="pfp-overlay">Edit Avatar</div>
                        <div class="rank-badge-lg"><?= $rank_title ?></div>
                    </div>
                    <input type="file" name="pfp_file" id="pfpInput" style="display: none;" onchange="previewFile()">
                </div>

                <div class="field" style="margin-top: 25px;">
                    <label>Select Avatar</label>
                    <div class="avatar-selector-grid" id="avatarGallery">
                        <!-- Generated via JS -->
                    </div>
                    <input type="hidden" name="selected_avatar" id="selectedAvatarInput">
                </div>

                <div class="field">
                    <label>Account Name</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required disabled style="opacity: 0.6; cursor: not-allowed;">
                    <p style="font-size: 11px; color: var(--text-muted); margin-top: 5px;"> Email cannot be changed.</p>
                </div>
                <?php if ($user['role'] === 'Student'): ?>
                    <div class="field">
                        <label>Research Group Title</label>
                        <input type="text" name="group_name" value="<?= htmlspecialchars($user['research_group_name'] ?? '') ?>">
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn-save">Update Profile</button>
            </form>
        </div>
    </div>
    <script>
        const syncTheme = () => {
            const savedTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = savedTheme;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className;
                    if (pTheme && pTheme !== document.body.className) {
                        document.body.className = pTheme;
                    }
                }
            } catch (e) {}
        }, 500);

        // LEGENDS HERO AVATAR SEEDS
        const heroSeeds = ['Felix', 'Aneka', 'Caleb', 'Jocelyn', 'Ginger', 'Boots', 'Jasper', 'Sheba', 'Patches', 'Willow'];
        const gallery = document.getElementById('avatarGallery');
        const preview = document.getElementById('pfpPreview');
        const hiddenInput = document.getElementById('selectedAvatarInput');

        heroSeeds.forEach(seed => {
            const url = `https://api.dicebear.com/9.x/adventurer/svg?seed=${seed}`;
            const img = document.createElement('img');
            img.src = url;
            img.className = 'avatar-item';
            img.title = seed;
            img.onclick = () => selectAvatar(url, img);
            gallery.appendChild(img);
        });

        function selectAvatar(url, element) {
            // Clear all active states
            document.querySelectorAll('.avatar-item').forEach(el => el.classList.remove('active'));
            // Set active state
            element.classList.add('active');
            // Update preview and hidden input
            preview.src = url;
            hiddenInput.value = url;
            // Clear file input if they chose a default hero
            document.getElementById('pfpInput').value = "";
        }

        function previewFile() {
            const file = document.getElementById('pfpInput').files[0];
            const reader = new FileReader();
            // Clear gallery selection when uploading custom
            document.querySelectorAll('.avatar-item').forEach(el => el.classList.remove('active'));
            hiddenInput.value = "";

            reader.onloadend = function() {
                preview.src = reader.result;
            }
            if (file) reader.readAsDataURL(file);
        }
    </script>
</body>

</html>
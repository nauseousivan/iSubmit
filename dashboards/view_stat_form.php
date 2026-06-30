<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id'])) { exit("Access Denied"); }

$form_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE form_id = ?");
$stmt->execute([$form_id]);
$form = $stmt->fetch();

if (!$form) { exit("Form not found."); }
$proponents = json_decode($form['proponents'], true) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
        :root { 
            --mcnp-teal: #0c343d; 
            --text-main: #1e293b; 
            --text-muted: #64748b; 
            --border-color: #e2e8f0;
            --bg-body: #f8fafc;
        }
        body { 
            font-family: 'Inter', system-ui, sans-serif; 
            padding: 30px; 
            background: var(--bg-body); 
            color: var(--text-main); 
            margin: 0; 
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
            padding: 40px;
        }
        .header {
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .header h3 { 
            color: var(--mcnp-teal); 
            margin: 0 0 5px 0; 
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .control-badge {
            background: #f1f5f9;
            color: var(--text-main);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            border: 1px solid #cbd5e1;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .box { 
            background: #f8fafc; 
            border: 1px solid var(--border-color); 
            padding: 20px; 
            border-radius: 12px; 
        }
        .val { font-weight: 700; color: var(--text-main); font-size: 15px; margin-top: 4px; }
        .lbl { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        
        .section-title {
            color: var(--mcnp-teal);
            font-size: 18px;
            font-weight: 700;
            margin: 30px 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
        table th, table td { padding: 12px 16px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border-color); }
        table th { background: #f8fafc; color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        table tr:last-child td { border-bottom: none; }
        
        .file-link { 
            display: inline-flex; 
            align-items: center;
            gap: 6px;
            background: #f1f5f9; 
            color: var(--mcnp-teal); 
            padding: 6px 12px; 
            text-decoration: none; 
            border-radius: 6px; 
            font-size: 13px; 
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid #cbd5e1;
        }
        .file-link:hover { background: var(--mcnp-teal); color: #fff; border-color: var(--mcnp-teal); }
    </style>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h3>Statistical Treatment Form</h3>
                <div style="color: var(--text-muted); font-size: 14px;">Institutional Research Portal</div>
            </div>
            <div class="control-badge">
                <i data-lucide="hash" style="width: 14px; height: 14px;"></i> <?= htmlspecialchars($form['formatted_control_no']) ?>
            </div>
        </div>
        
        <div class="grid">
            <div class="box">
                <div class="lbl">Research Title</div>
                <div class="val"><?= htmlspecialchars($form['research_title']) ?></div>
                <div style="margin-top: 15px;">
                    <div class="lbl">Course / Program</div>
                    <div class="val"><?= htmlspecialchars($form['course']) ?></div>
                </div>
            </div>
            <div class="box">
                <div class="lbl">Main Payment OR Number</div>
                <div class="val" style="color:#ea580c; font-size:20px; font-weight: 800;"><?= htmlspecialchars($form['main_or_number']) ?></div>
                <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr;">
                    <div>
                        <div class="lbl">Date Submitted</div>
                        <div class="val"><?= date('M d, Y', strtotime($form['date_submitted'])) ?></div>
                    </div>
                    <div>
                        <div class="lbl">Expected Release</div>
                        <div class="val"><?= date('M d, Y', strtotime($form['date_released'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-title">
            <i data-lucide="users" style="width: 18px; height: 18px;"></i> Research Proponents
        </div>
        <table>
            <tr>
                <th>Name</th>
                <th>OR Number</th>
            </tr>
            <?php foreach ($proponents as $p): ?>
            <tr>
                <td style="font-weight: 500; color: var(--text-main);"><?= htmlspecialchars($p['name']) ?></td>
                <td style="color: var(--text-muted); font-family: monospace; font-size: 13px;"><?= htmlspecialchars($p['or_number']) ?: 'N/A' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="section-title">
            <i data-lucide="file-check-2" style="width: 18px; height: 18px;"></i> Required Deliverables
        </div>
        <table>
            <tr>
                <th>Requirement</th>
                <th style="width: 120px;">Action</th>
            </tr>
            <tr>
                <td style="font-weight: 500;">Statement of the Problem</td>
                <td><a href="<?= htmlspecialchars($form['file_sop']) ?>" target="_blank" class="file-link"><i data-lucide="download" style="width:14px; height:14px;"></i> View File</a></td>
            </tr>
            <tr>
                <td style="font-weight: 500;">Sample Questionnaire</td>
                <td><a href="<?= htmlspecialchars($form['file_questionnaire']) ?>" target="_blank" class="file-link"><i data-lucide="download" style="width:14px; height:14px;"></i> View File</a></td>
            </tr>
            <tr>
                <td style="font-weight: 500;">Coded Data in Excel</td>
                <td><a href="<?= htmlspecialchars($form['file_coded_data']) ?>" target="_blank" class="file-link"><i data-lucide="download" style="width:14px; height:14px;"></i> View File</a></td>
            </tr>
            <tr>
                <td style="font-weight: 500;">Approved Communication Letter</td>
                <td><a href="<?= htmlspecialchars($form['file_comm_letter']) ?>" target="_blank" class="file-link"><i data-lucide="download" style="width:14px; height:14px;"></i> View File</a></td>
            </tr>
            <tr>
                <td style="font-weight: 500;">Minutes of the Meeting (MOM)</td>
                <td><a href="<?= htmlspecialchars($form['file_mom']) ?>" target="_blank" class="file-link"><i data-lucide="download" style="width:14px; height:14px;"></i> View File</a></td>
            </tr>
        </table>
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

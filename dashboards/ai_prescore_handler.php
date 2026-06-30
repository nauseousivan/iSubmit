<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director'])) { 
    echo json_encode(['success' => false, 'error' => 'Access Denied']); 
    exit; 
}

// TODO: Replace with your actual OpenAI Secret Key (Generate a new one if this was exposed!)
$openai_api_key = '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';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_id'])) {
    $upload_id = $_POST['upload_id'];

    // 1. Fetch file path from the database
    $stmt = $pdo->prepare("SELECT file_path FROM uploads WHERE upload_id = ?");
    $stmt->execute([$upload_id]);
    $file_path = $stmt->fetchColumn();

    if (!$file_path || !file_exists($file_path)) {
        echo json_encode(['success' => false, 'error' => 'File not found on server.']);
        exit;
    }

    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $document_text = "";

    // 2. Extract Text based on extension
    try {
        if ($ext === 'pdf') {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $document_text = $pdf->getText();
        } elseif ($ext === 'docx') {
            // .docx is essentially a ZIP file containing XML documents
            $zip = new ZipArchive;
            if ($zip->open($file_path) === true) {
                if (($index = $zip->locateName('word/document.xml')) !== false) {
                    $data = $zip->getFromIndex($index);
                    $zip->close();
                    // Safely strip XML tags and try to preserve paragraph breaks
                    $document_text = strip_tags(str_replace(['<w:p ', '</w:p>'], ["\n<w:p ", "\n"], $data));
                } else {
                    $zip->close();
                    echo json_encode(['success' => false, 'error' => 'Invalid docx file structure.']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to open docx archive.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'AI Pre-scoring currently only supports PDF and DOCX formats.']);
            exit;
        }
        
        // Truncate text to avoid token limits (Reads the first ~15,000 characters / ~4,000 words)
        $document_text = substr($document_text, 0, 15000); 
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to extract text: ' . $e->getMessage()]);
        exit;
    }

    // 3. Prepare OpenAI Prompt
    $prompt = "You are an expert academic research evaluator. Review the following research proposal extract and score it based on 22 criteria.
For each criterion, respond with 'YES' if it satisfies the requirement or 'NO' if it doesn't. Also provide a brief 1-sentence comment explaining why.
Respond ONLY in valid JSON format with keys q1 through q22. Example:
{
  \"q1\": {\"val\": \"YES\", \"comment\": \"Objectives are clearly defined.\"},
  \"q2\": {\"val\": \"NO\", \"comment\": \"Rationale is missing key background context.\"}
}
DOCUMENT EXTRACT:
" . $document_text;

    // 4. Call OpenAI API via cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4o-mini', // Fast, cheap, and very capable
        'messages' => [
            ['role' => 'system', 'content' => 'You are a strict JSON-only response bot.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2 // Low temperature for consistent grading
    ]));
    
    // Bypass SSL verification for local XAMPP testing
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json', 
        'Authorization: Bearer ' . $openai_api_key
    ]);

    $response = curl_exec($ch);
    
    // Catch exact cURL network errors
    if(curl_errno($ch)){
        echo json_encode(['success' => false, 'error' => 'Network/cURL Error: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    $responseData = json_decode($response, true);
    
    // Catch exact OpenAI rejection errors (like billing or bad keys)
    if (isset($responseData['error'])) {
        echo json_encode(['success' => false, 'error' => 'OpenAI rejected the request: ' . $responseData['error']['message']]);
        exit;
    }

    if (isset($responseData['choices'][0]['message']['content'])) {
        echo json_encode(['success' => true, 'data' => json_decode($responseData['choices'][0]['message']['content'], true)]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to get valid response.', 'api_response' => $responseData]);
    }
}
?>
<?php
/**
 * config/form_fill.php — fill a Word master's {{TAGS}} and turn it into a PDF (2026-10-17).
 * Loaded by config/forms.php, so every page has it.
 *
 * The office keeps each RDC form as a Word file. Its blanks carry tags like {{COURSE}}; when a
 * student downloads the form, rd_docx_fill() puts their own details in, and rd_docx_to_pdf() hands
 * them a PDF — never the Word file, which prints differently on every device (the office is strict
 * about the format). When the school changes a form, the office edits the Word file, keeps the tags
 * and the Admin uploads it again: nothing in the code changes.
 *
 * Needs only PHP's zip extension. The PDF step needs LibreOffice on the server (rd_soffice()); without
 * it, or if a conversion fails, form_download.php hands out the BLANK PDF instead — a student only
 * ever receives a PDF.
 */

/**
 * One {{TAG}}, even when Word has split it across formatting runs ("{{CO" in one run, "URSE}}" in the
 * next — spellcheck and edit history do that). The XML between the braces is a balanced "close this
 * run, open the next" sequence, so replacing the whole match with plain text leaves valid XML.
 */
const RD_TAG_RE = '/\{(?:<[^>]+>)*\{((?:[^{}<]|<[^>]+>)*?)\}(?:<[^>]+>)*\}/u';

/** The parts of a .docx that can carry tags: the body, headers and footers. */
function rd_docx_parts(ZipArchive $z): array
{
    $parts = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n)) $parts[] = $n;
    }
    return $parts;
}

/** Normalise a tag's inner text: no XML, no spaces, upper case ({{ course }} = {{COURSE}}). */
function rd_tag_name(string $inner): string
{
    return strtoupper(preg_replace('/\s+/u', '', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_XML1, 'UTF-8')));
}

/** Every tag a Word file carries, in order, without duplicates. [] when it has none or cannot be read. */
function rd_docx_tags(string $file): array
{
    static $memo = [];
    $key = $file . '|' . (is_file($file) ? filemtime($file) : 0);
    if (isset($memo[$key])) return $memo[$key];
    $tags = [];
    if (class_exists('ZipArchive') && is_file($file)) {
        $z = new ZipArchive();
        if ($z->open($file) === true) {
            foreach (rd_docx_parts($z) as $part) {
                if (preg_match_all(RD_TAG_RE, (string)$z->getFromName($part), $m)) {
                    foreach ($m[1] as $inner) $tags[rd_tag_name($inner)] = true;
                }
            }
            $z->close();
        }
    }
    return $memo[$key] = array_keys($tags);
}

/**
 * Copy $src to $dst with every {{TAG}} replaced by $vals[TAG] (XML-escaped). A tag with no value is
 * left blank, so the line prints empty for the office to write on. Returns false if the file cannot
 * be read or written.
 */
function rd_docx_fill(string $src, string $dst, array $vals): bool
{
    if (!class_exists('ZipArchive') || !is_file($src) || !@copy($src, $dst)) return false;
    $z = new ZipArchive();
    if ($z->open($dst) !== true) return false;
    $vals = array_change_key_case($vals, CASE_UPPER);
    foreach (rd_docx_parts($z) as $part) {
        $xml = (string)$z->getFromName($part);
        $out = preg_replace_callback(RD_TAG_RE, function ($m) use ($vals) {
            return htmlspecialchars((string)($vals[rd_tag_name($m[1])] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }, $xml);
        if ($out !== null && $out !== $xml) $z->addFromString($part, $out);
    }
    return $z->close();
}

/**
 * The BLANK PDF of a Word master: every tag emptied, converted into $outDir as <master name>.pdf.
 * Made once when the Admin uploads a master — it is what the front page offers and what a student
 * gets if filling ever fails. Null without LibreOffice.
 */
function rd_docx_blank_pdf(string $docx, string $outDir): ?string
{
    $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'isubmit_blank_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpDir, 0700, true)) return null;
    $copy = $tmpDir . DIRECTORY_SEPARATOR . basename($docx);
    $pdf  = rd_docx_fill($docx, $copy, []) ? rd_docx_to_pdf($copy, $tmpDir) : null;
    $dest = null;
    if ($pdf) {
        $dest = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . basename($pdf);
        if (!@rename($pdf, $dest)) { $dest = @copy($pdf, $dest) ? $dest : null; @unlink($pdf); }
    }
    @unlink($copy);
    @rmdir($tmpDir);
    return $dest;
}

/** Where LibreOffice is, or '' when it is not installed. A system setting 'soffice_path' overrides. */
function rd_soffice(): string
{
    static $found = null;
    if ($found !== null) return $found;
    $set = function_exists('rd_setting') ? trim((string)rd_setting('soffice_path', '')) : '';
    foreach (array_filter([$set, '/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/lib/libreoffice/program/soffice',
                           '/opt/libreoffice/program/soffice', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                           'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe']) as $p) {
        if (is_file($p)) return $found = $p;
    }
    return $found = '';
}

/**
 * Convert a .docx to a PDF in $outDir with LibreOffice. Returns the PDF's path, or null.
 * One conversion at a time (a lock file): LibreOffice is heavy, and two at once on a small server
 * fight over memory. Its profile lives in the temp folder, because the web server's user has no
 * writable home.
 */
function rd_docx_to_pdf(string $docx, string $outDir, int $timeout = 60): ?string
{
    $bin = rd_soffice();
    if ($bin === '' || !is_file($docx) || !is_dir($outDir)) return null;

    $tmp     = rtrim(sys_get_temp_dir(), '/\\');
    $lock    = fopen($tmp . DIRECTORY_SEPARATOR . 'isubmit_soffice.lock', 'c');
    $profile = $tmp . DIRECTORY_SEPARATOR . 'isubmit_lo_profile';
    if ($lock) flock($lock, LOCK_EX);
    try {
        $url = 'file:///' . ltrim(str_replace('\\', '/', $profile), '/');
        $cmd = escapeshellarg($bin) . ' --headless --norestore --nologo --nodefault'
             . ' ' . escapeshellarg('-env:UserInstallation=' . $url)
             . ' --convert-to pdf --outdir ' . escapeshellarg($outDir) . ' ' . escapeshellarg($docx);
        $env = array_merge(getenv() ?: [], ['HOME' => $tmp]);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) return null;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $end = time() + $timeout;
        while (time() < $end) {
            $st = proc_get_status($p);
            if (!$st['running']) break;
            stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            usleep(150000);
        }
        if (proc_get_status($p)['running']) proc_terminate($p);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
    $pdf = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . pathinfo($docx, PATHINFO_FILENAME) . '.pdf';
    return (is_file($pdf) && filesize($pdf) > 0) ? $pdf : null;
}

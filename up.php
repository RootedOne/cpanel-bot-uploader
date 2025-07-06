<?php
// index.php

// Allow long-running downloads/unzips
set_time_limit(0);
ini_set('memory_limit', '512M');

/**
 * Recursively delete a directory.
 */
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $objects = array_diff(scandir($dir), ['.', '..']);
    foreach ($objects as $object) {
        $path = "$dir/$object";
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Download a GitHub repo (any branch) as ZIP and extract into destination.
 *
 * @param string $repoUrl   Full GitHub URL, e.g. https://github.com/user/repo[/tree/branch/path]
 * @param string $dest      Absolute path to deployment directory
 * @throws Exception
 */
function downloadAndExtractGitHubRepo($repoUrl, $dest) {
    // Match owner, repo, and optional branch (including slashes)
    if (!preg_match('#github\.com/([^/]+)/([^/]+)(?:/(?:tree|blob)/(.+))?$#i', trim($repoUrl), $m)) {
        throw new Exception("Invalid GitHub URL format.");
    }
    list(, $owner, $repo, $branch) = $m;
    $branch = $branch ?: 'main';

    // Build the archive URL
    $zipUrl = "https://github.com/$owner/$repo/archive/refs/heads/" . rawurlencode($branch) . ".zip";

    // Download to temp file
    $tmpZip = tempnam(sys_get_temp_dir(), 'ghzip_') . '.zip';
    $fp = fopen($tmpZip, 'w+');
    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_USERAGENT      => 'cPanel-GitHub-Deploy-Script'
    ]);
    if (!curl_exec($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        unlink($tmpZip);
        throw new Exception("Error downloading ZIP: $err");
    }
    curl_close($ch);
    fclose($fp);

    // Clear out existing directory
    if (is_dir($dest)) rrmdir($dest);
    mkdir($dest, 0755, true);

    // Extract ZIP
    $zip = new ZipArchive;
    if ($zip->open($tmpZip) !== true) {
        unlink($tmpZip);
        throw new Exception("Failed to open downloaded ZIP.");
    }
    $tmpExtract = sys_get_temp_dir() . '/ghrepo_' . uniqid();
    mkdir($tmpExtract);
    $zip->extractTo($tmpExtract);
    $zip->close();
    unlink($tmpZip);

    // Locate the extracted root folder (repo-branch)
    $rootFolders = array_diff(scandir($tmpExtract), ['.', '..']);
    $root = reset($rootFolders);
    $extractedRoot = "$tmpExtract/$root";

    // Copy contents into destination
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractedRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $relativePath = substr($item->getPathname(), strlen($extractedRoot));
        $targetPath   = $dest . $relativePath;
        if ($item->isDir()) {
            if (!is_dir($targetPath)) mkdir($targetPath, 0755, true);
        } else {
            copy($item->getPathname(), $targetPath);
        }
    }

    // Cleanup
    rrmdir($tmpExtract);
}

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['repo_url'])) {
    try {
        $deployDir = __DIR__ . '/bot';
        downloadAndExtractGitHubRepo($_POST['repo_url'], $deployDir);
        $message = "Success! Deployed to <code>/public_html/bot/</code>.";
    } catch (Exception $e) {
        $message = "Error: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Deploy GitHub Repo to /bot</title>
  <style>
    body { font-family: sans-serif; max-width: 600px; margin: 2em auto; }
    input[type=text] { width: 100%; padding: .5em; margin: .5em 0; }
    button { padding: .5em 1em; }
    .msg { margin: 1em 0; }
    .error { color: #a00; }
    code { background:#f4f4f4; padding:2px 4px; }
  </style>
</head>
<body>
  <h1>Deploy GitHub Repo</h1>
  <?php if ($message): ?>
    <div class="msg <?= strpos($message, 'Error') === 0 ? 'error' : '' ?>">
      <?= $message ?>
    </div>
  <?php endif; ?>
  <form method="post">
    <label for="repo_url">GitHub Repository URL:</label>
    <input type="text" id="repo_url" name="repo_url"
           placeholder="https://github.com/user/repo[/tree/branch/name]"
           required>
    <button type="submit">Deploy to /bot</button>
  </form>
</body>
</html>

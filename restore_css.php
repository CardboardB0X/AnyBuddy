<?php
$brainDir = 'C:\\Users\\Japoy\\.gemini\\antigravity\\brain\\';
$dirs = glob($brainDir . '*', GLOB_ONLYDIR);
foreach ($dirs as $dir) {
    $logPath = $dir . '\\.system_generated\\logs\\transcript.jsonl';
    if (file_exists($logPath)) {
        $lines = file($logPath);
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && isset($data['type']) && $data['type'] === 'TOOL_RESPONSE') {
                $content = $data['content'] ?? '';
                if (strpos($content, 'file:///c:/Users/Japoy/Downloads/AnyBuddy/theme.css') !== false) {
                    echo "Found theme.css in " . basename($dir) . "\n";
                    file_put_contents('theme_view_' . basename($dir) . '_' . uniqid() . '.txt', $content);
                }
            }
        }
    }
}
echo "Done.";

<?php
$file = '/var/www/uploads/user_1/user_1_69d2cff4d292e4.35751349.pdf';
echo "Testing file: $file\n";
echo "File exists: " . (file_exists($file) ? 'yes' : 'no') . "\n";
echo "File size: " . filesize($file) . " bytes\n\n";

// Try to extract text with pdftotext
$tempOut = tempnam(sys_get_temp_dir(), 'pdf_');
$cmd = sprintf('pdftotext %s %s 2>&1', escapeshellarg($file), escapeshellarg($tempOut));
echo "Running: $cmd\n";
$output = [];
$ret = 0;
exec($cmd, $output, $ret);
echo "Return code: $ret\n";

if ($ret === 0) {
    $text = file_get_contents($tempOut . '.txt');
    echo "Extracted text length: " . strlen($text) . "\n";
    echo "First 500 chars:\n" . substr($text, 0, 500) . "\n";
    unlink($tempOut . '.txt');
} else {
    echo "pdftotext failed. Output:\n";
    echo implode("\n", $output) . "\n";
}

unlink($tempOut);

<?php
$file = '/var/www/uploads/user_1/user_1_69d2cff4d292e4.35751349.pdf';
echo "Testing PDF to image conversion: $file\n\n";

$tempDir = '/tmp/pdf_test_' . uniqid();
mkdir($tempDir);

$cmd = sprintf('pdftoppm -png %s %s 2>&1', escapeshellarg($file), escapeshellarg($tempDir . '/page'));
echo "Running: $cmd\n";
$output = [];
$ret = 0;
exec($cmd, $output, $ret);
echo "Return code: $ret\n";

if ($ret !== 0) {
    echo "pdftoppm failed. Output:\n";
    echo implode("\n", $output) . "\n";
} else {
    $images = glob($tempDir . '/page-*.png');
    echo "Generated " . count($images) . " images\n";
    
    if (!empty($images)) {
        foreach ($images as $img) {
            echo "  - " . basename($img) . " (" . filesize($img) . " bytes)\n";
        }
    }
}

// Cleanup
system("rm -rf " . escapeshellarg($tempDir));

<?php

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    // Manually process queue for debugging
    $kernel = $context['kernel'];
    $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
    $analysisRepo = $em->getRepository('App\\Entity\\Analysis');
    $allAnalyses = $analysisRepo->findAll();
    
    echo "Total analyses: " . count($allAnalyses) . "\n";
    
    foreach ($allAnalyses as $analysis) {
        echo "Analysis ID: {$analysis->getId()}, Status: {$analysis->getStatus()}, File: {$analysis->getFilePath()}\n";
    }
};

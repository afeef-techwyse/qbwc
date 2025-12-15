<?php
/**
 * Debug script for QBWC Application
 * This script helps debug stuck QBWC applications
 */

echo "=== QBWC Debug Script ===\n";
// Check state file
$stateFile = 'C:\tmp\qbwc_app_state.json';
if (file_exists($stateFile)) {
    echo "State file exists: $stateFile\n";
    $state = json_decode(file_get_contents($stateFile), true);
    echo "Current state:\n";
    print_r($state);
    
    // Check file age
    $fileTime = filemtime($stateFile);
    $currentTime = time();
    $timeDiff = $currentTime - $fileTime;
    echo "State file age: $timeDiff seconds (" . round($timeDiff/60, 1) . " minutes)\n";
    
    if ($timeDiff > 300) {
        echo "WARNING: Application appears to be stuck for $timeDiff seconds!\n";
        echo "Options:\n";
        echo "1. Reset state to force restart\n";
        echo "2. Check QBWebConnector logs\n";
        echo "3. Check if QuickBooks is running\n";
    }
} else {
    echo "State file does not exist: $stateFile\n";
}

// Check debug log
$debugFile = 'C:\tmp\qbwc_app_debug.log';
if (file_exists($debugFile)) {
    echo "\nDebug log exists: $debugFile\n";
    $logContent = file_get_contents($debugFile);
    $lines = explode("\n", $logContent);
    echo "Last 5 log entries:\n";
    foreach (array_slice($lines, -5) as $line) {
        if (trim($line)) {
            echo "  $line\n";
        }
    }
} else {
    echo "Debug log does not exist: $debugFile\n";
}

echo "\n=== Debug Complete ===\n";
?>

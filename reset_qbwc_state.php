<?php
/**
 * Reset QBWC Application State
 * This script resets the stuck QBWC application state
 */

echo "=== QBWC State Reset Script ===\n";

$stateFile = 'C:\tmp\qbwc_app_state.json';
$debugFile = 'C:\tmp\qbwc_app_debug.log';

// Backup current state
if (file_exists($stateFile)) {
    $backupFile = 'C:\tmp\qbwc_app_state_backup_' . date('Y-m-d_H-i-s') . '.json';
    copy($stateFile, $backupFile);
    echo "Backed up state to: $backupFile\n";
    
    // Show current state
    $state = json_decode(file_get_contents($stateFile), true);
    echo "Current state before reset:\n";
    print_r($state);
    
    // Reset state
    unlink($stateFile);
    echo "State file deleted: $stateFile\n";
} else {
    echo "State file does not exist: $stateFile\n";
}

// Clear debug log
if (file_exists($debugFile)) {
    $backupLog = 'C:\tmp\qbwc_app_debug_backup_' . date('Y-m-d_H-i-s') . '.log';
    copy($debugFile, $backupLog);
    echo "Backed up debug log to: $backupLog\n";
    
    // Clear the log
    file_put_contents($debugFile, '');
    echo "Debug log cleared: $debugFile\n";
} else {
    echo "Debug log does not exist: $debugFile\n";
}

echo "\n=== State Reset Complete ===\n";
echo "The QBWC application will restart from the beginning on the next request.\n";
echo "Make sure QuickBooks is running and QBWebConnector is connected.\n";
?>

<?php

/**
 * Log Cleanup Utility
 * 
 * This script helps clean up large log files that may have accumulated.
 * Run this script to delete old log files and get statistics about your logs.
 * 
 * Usage:
 *   php cleanup-logs.php [options]
 * 
 * Options:
 *   --stats          Show log statistics only (no deletion)
 *   --days=N         Keep logs from last N days (default: 7)
 *   --dry-run        Show what would be deleted without actually deleting
 *   --all            Clean all logs (default: only logs older than N days)
 *   --help           Show this help message
 * 
 * Examples:
 *   php cleanup-logs.php --stats
 *   php cleanup-logs.php --days=30
 *   php cleanup-logs.php --dry-run
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\Logger;

// Parse command line arguments
$options = getopt('', ['stats', 'days:', 'dry-run', 'all', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$statsOnly = isset($options['stats']);
$daysToKeep = isset($options['days']) ? (int)$options['days'] : 7;
$dryRun = isset($options['dry-run']);
$cleanAll = isset($options['all']);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  LOG CLEANUP UTILITY\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Get current log statistics
echo "📊 Analyzing log files...\n\n";
$stats = Logger::getLogStats();

echo "Current Log Statistics:\n";
echo "  • Total log files: {$stats['total_files']}\n";
echo "  • Total size: {$stats['total_size_formatted']}\n\n";

if ($stats['total_files'] > 0) {
    echo "Top 10 largest log files:\n";
    $topFiles = array_slice($stats['files'], 0, 10);
    foreach ($topFiles as $file) {
        $age = getDaysOld($file['modified']);
        echo sprintf(
            "  • %-40s %10s (%d days old)\n",
            $file['name'],
            $file['size_formatted'],
            $age
        );
    }
    echo "\n";
}

if ($statsOnly) {
    echo "ℹ️  Stats only mode. No cleanup performed.\n";
    exit(0);
}

// Perform cleanup
echo "═══════════════════════════════════════════════════════════════\n";
if ($dryRun) {
    echo "  DRY RUN MODE - No files will be deleted\n";
} else {
    echo "  CLEANUP MODE - Files will be deleted\n";
}
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($cleanAll) {
    echo "⚠️  WARNING: This will delete ALL log files older than {$daysToKeep} days!\n";
} else {
    echo "🧹 Cleaning up log files older than {$daysToKeep} days...\n";
}

if (!$dryRun) {
    echo "\nPress ENTER to continue, or Ctrl+C to cancel: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
}

echo "\n";

if ($dryRun) {
    // Show what would be deleted
    $logsDir = __DIR__ . '/logs';
    $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
    $files = glob($logsDir . '/*.log*');
    $deletedCount = 0;
    $deletedSize = 0;

    foreach ($files as $file) {
        if (file_exists($file) && filemtime($file) < $cutoffTime) {
            $size = filesize($file);
            $deletedSize += $size;
            $deletedCount++;
            $age = getDaysOld(filemtime($file));
            echo sprintf(
                "  Would delete: %-40s %10s (%d days old)\n",
                basename($file),
                formatBytes($size),
                $age
            );
        }
    }

    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  DRY RUN SUMMARY\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  • Files that would be deleted: {$deletedCount}\n";
    echo "  • Space that would be freed: " . formatBytes($deletedSize) . "\n";
    echo "\n  Run without --dry-run to actually delete these files.\n\n";
} else {
    // Actually delete files
    $deletedCount = Logger::cleanupOldLogs(null, $daysToKeep);

    // Get new statistics
    $newStats = Logger::getLogStats();
    $freedSpace = $stats['total_size'] - $newStats['total_size'];

    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  CLEANUP COMPLETE\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  ✅ Files deleted: {$deletedCount}\n";
    echo "  💾 Space freed: " . formatBytes($freedSpace) . "\n";
    echo "  📊 Remaining files: {$newStats['total_files']}\n";
    echo "  📦 Remaining size: {$newStats['total_size_formatted']}\n\n";
}

// Helper functions
function showHelp(): void
{
    echo file_get_contents(__FILE__);
    exit(0);
}

function getDaysOld(int $timestamp): int
{
    return (int)((time() - $timestamp) / (24 * 60 * 60));
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = $bytes;

    for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }

    return round($size, 2) . ' ' . $units[$i];
}

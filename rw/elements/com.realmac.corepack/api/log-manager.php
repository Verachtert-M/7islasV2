<?php

/**
 * Web-Based Log Manager
 * 
 * A simple web interface to view and clean up log files.
 * Access this file directly in your browser.
 * 
 * Password is stored in .log-manager-password which persists across updates.
 */

// Check if autoloader exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Error: Composer dependencies not installed. Run: composer install');
}

require __DIR__ . '/vendor/autoload.php';

use App\Services\Logger;

// Password configuration file (persists across updates)
$passwordFile = __DIR__ . '/.log-manager-password';

// Initialize session
session_start();

// Handle first-time setup
$needsSetup = !file_exists($passwordFile);

if ($needsSetup && isset($_POST['setup_password'])) {
    $password = $_POST['setup_password'];
    $confirmPassword = $_POST['confirm_password'];

    if (empty($password)) {
        $setupError = 'Password cannot be empty';
    } elseif (strlen($password) < 6) {
        $setupError = 'Password must be at least 6 characters';
    } elseif ($password !== $confirmPassword) {
        $setupError = 'Passwords do not match';
    } else {
        // Save hashed password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if (file_put_contents($passwordFile, $hashedPassword)) {
            $needsSetup = false;
            $_SESSION['log_manager_auth'] = true;
            $_SESSION['setup_complete'] = true;
        } else {
            $setupError = 'Failed to save password. Check file permissions.';
        }
    }
}

// Load saved password
$savedPasswordHash = null;
if (file_exists($passwordFile)) {
    $savedPasswordHash = trim(file_get_contents($passwordFile));
}

// Handle authentication
$isAuthenticated = isset($_SESSION['log_manager_auth']) && $_SESSION['log_manager_auth'] === true;

if (!$needsSetup && isset($_POST['access_code'])) {
    $enteredPassword = $_POST['access_code'];
    if (password_verify($enteredPassword, $savedPasswordHash)) {
        $_SESSION['log_manager_auth'] = true;
        $isAuthenticated = true;
    } else {
        $loginError = 'Invalid password';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle actions
$action = $_GET['action'] ?? '';
$result = null;
$stats = null;

if ($isAuthenticated) {
    if ($action === 'cleanup') {
        $daysToKeep = isset($_POST['days']) ? (int)$_POST['days'] : 7;
        $deletedCount = Logger::cleanupOldLogs(null, $daysToKeep);
        $result = [
            'type' => 'success',
            'message' => "Successfully deleted {$deletedCount} old log file(s).",
            'count' => $deletedCount
        ];
    }

    if ($action === 'delete_file' && isset($_POST['file_name'])) {
        $fileName = $_POST['file_name'];
        $logsDir = __DIR__ . '/logs';
        $filePath = $logsDir . '/' . basename($fileName); // basename for security

        if (file_exists($filePath) && strpos(realpath($filePath), realpath($logsDir)) === 0) {
            if (unlink($filePath)) {
                $result = [
                    'type' => 'success',
                    'message' => "Successfully deleted {$fileName}."
                ];
            } else {
                $result = [
                    'type' => 'error',
                    'message' => "Failed to delete {$fileName}."
                ];
            }
        } else {
            $result = [
                'type' => 'error',
                'message' => "File not found or access denied."
            ];
        }
    }

    if ($action === 'view_file' && isset($_GET['file_name'])) {
        $fileName = $_GET['file_name'];
        $logsDir = __DIR__ . '/logs';
        $filePath = $logsDir . '/' . basename($fileName);

        if (file_exists($filePath) && strpos(realpath($filePath), realpath($logsDir)) === 0) {
            $fileContent = file_get_contents($filePath);
            $lines = explode("\n", $fileContent);
            // Limit to last 500 lines for performance
            $lines = array_slice($lines, -500);
            $viewingFile = [
                'name' => $fileName,
                'lines' => $lines,
                'totalLines' => count(explode("\n", $fileContent)),
                'size' => filesize($filePath)
            ];
        } else {
            $result = [
                'type' => 'error',
                'message' => "File not found or access denied."
            ];
        }
    }

    // Always get fresh stats
    $stats = Logger::getLogStats();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto p-6">
        <?php if ($needsSetup): ?>
            <!-- First-Time Setup -->
            <div class="min-h-screen flex items-center justify-center -m-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 w-full max-w-md">
                    <div class="text-center mb-8">
                        <div class="text-5xl mb-4">🔐</div>
                        <h1 class="text-2xl font-semibold text-gray-900 mb-2">First-Time Setup</h1>
                        <p class="text-gray-600">Create a password to protect your log manager</p>
                    </div>

                    <?php if (isset($_SESSION['setup_complete'])): ?>
                        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-md mb-4 text-sm">
                            Password created successfully! Redirecting...
                            <script>
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            </script>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($setupError)): ?>
                        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-md mb-4 text-sm">
                            <?= htmlspecialchars($setupError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="setup_password" class="block text-sm font-medium text-gray-700 mb-1">Choose Password</label>
                            <input type="password" id="setup_password" name="setup_password" required autofocus minlength="6"
                                placeholder="At least 6 characters"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="6"
                                placeholder="Re-enter password"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-4 rounded-md transition-colors">
                            Create Password
                        </button>
                    </form>

                    <div class="mt-6 p-3 bg-blue-50 border border-blue-100 rounded-md text-sm text-blue-900">
                        <strong>Note:</strong> Your password will be saved securely and persist across software updates.
                    </div>
                </div>
            </div>
        <?php elseif (!$isAuthenticated): ?>
            <!-- Login Form -->
            <div class="min-h-screen flex items-center justify-center -m-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 w-full max-w-md">
                    <div class="text-center mb-8">
                        <div class="text-5xl mb-4">🔒</div>
                        <h1 class="text-2xl font-semibold text-gray-900 mb-2">Log Manager</h1>
                        <p class="text-gray-600">Enter your password to continue</p>
                    </div>

                    <?php if (isset($loginError)): ?>
                        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-md mb-4 text-sm">
                            <?= htmlspecialchars($loginError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="access_code" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" id="access_code" name="access_code" required autofocus
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-4 rounded-md transition-colors">
                            Unlock
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-semibold text-gray-900">Log Manager</h1>
                        <p class="text-sm text-gray-600 mt-1">Monitor and manage your application logs</p>
                    </div>
                    <a href="?logout" class="text-sm text-gray-600 hover:text-gray-900">
                        Logout
                    </a>
                </div>
            </div>

            <?php if ($result): ?>
                <div class="<?= $result['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> border px-4 py-3 rounded-md mb-6 text-sm">
                    <?= htmlspecialchars($result['message']) ?>
                </div>
            <?php endif; ?>

            <?php if (!isset($viewingFile)): ?>
                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
                        <div class="text-3xl font-semibold text-gray-900"><?= $stats['total_files'] ?></div>
                        <div class="text-sm text-gray-600 mt-1">Total Log Files</div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
                        <div class="text-3xl font-semibold text-gray-900"><?= $stats['total_size_formatted'] ?></div>
                        <div class="text-sm text-gray-600 mt-1">Total Size</div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6">
                        <div class="text-3xl font-semibold text-gray-900">
                            <?php
                            $oldestDays = 0;
                            if (!empty($stats['files'])) {
                                $oldestFile = end($stats['files']);
                                $oldestDays = (int)((time() - $oldestFile['modified']) / (24 * 60 * 60));
                            }
                            echo $oldestDays;
                            ?>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">Oldest Log (days)</div>
                    </div>
                </div>

                <!-- Cleanup Form -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Clean Up Old Logs</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Delete log files older than the specified number of days. This action cannot be undone.
                    </p>

                    <form method="POST" action="?action=cleanup" onsubmit="return confirm('Are you sure you want to delete old log files? This cannot be undone.');" class="flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label for="days" class="block text-sm font-medium text-gray-700 mb-1">Keep logs from last</label>
                            <select id="days" name="days" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="1">1 day</option>
                                <option value="3">3 days</option>
                                <option value="7" selected>7 days (recommended)</option>
                                <option value="14">14 days</option>
                                <option value="30">30 days</option>
                                <option value="60">60 days</option>
                                <option value="90">90 days</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition-colors whitespace-nowrap">
                            Clean Up Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Log File Viewer -->
            <?php if (isset($viewingFile)): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Viewing: <?= htmlspecialchars($viewingFile['name']) ?></h3>
                        <a href="?" class="text-sm text-gray-600 hover:text-gray-900">
                            ← Back to List
                        </a>
                    </div>

                    <div class="mb-4 text-xs text-gray-600 space-x-4">
                        <span><span class="font-medium">Size:</span> <?= number_format($viewingFile['size']) ?> bytes</span>
                        <span><span class="font-medium">Total Lines:</span> <?= number_format($viewingFile['totalLines']) ?></span>
                        <?php if ($viewingFile['totalLines'] > 500): ?>
                            <span class="text-amber-600">(showing last 500 lines)</span>
                        <?php endif; ?>
                    </div>

                    <div class="bg-gray-900 rounded-md p-4 overflow-x-auto max-h-[600px] overflow-y-auto">
                        <div class="font-mono text-xs">
                            <?php
                            $startLine = max(1, $viewingFile['totalLines'] - count($viewingFile['lines']) + 1);
                            foreach ($viewingFile['lines'] as $index => $line):
                                $lineNum = $startLine + $index;
                                $line = htmlspecialchars($line);

                                // Syntax highlighting for log levels
                                $lineClass = 'text-gray-300';
                                if (preg_match('/\b(ERROR|CRITICAL|FATAL)\b/i', $line)) {
                                    $lineClass = 'text-red-400';
                                } elseif (preg_match('/\b(WARNING|WARN)\b/i', $line)) {
                                    $lineClass = 'text-amber-400';
                                } elseif (preg_match('/\b(INFO)\b/i', $line)) {
                                    $lineClass = 'text-blue-400';
                                } elseif (preg_match('/\b(DEBUG)\b/i', $line)) {
                                    $lineClass = 'text-green-400';
                                } elseif (preg_match('/^\[[\d\-:\s]+\]/', $line)) {
                                    $lineClass = 'text-purple-400';
                                }
                            ?>
                                <div class="flex hover:bg-gray-800">
                                    <span class="text-gray-600 select-none w-16 flex-shrink-0 text-right pr-4"><?= $lineNum ?></span>
                                    <span class="<?= $lineClass ?> flex-1"><?= $line ?: '&nbsp;' ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Log Files Table -->
            <?php if (!isset($viewingFile) && !empty($stats['files'])): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Log Files</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-xs font-medium text-gray-700 uppercase tracking-wider">
                                    <th class="text-left py-3 px-6">File Name</th>
                                    <th class="text-left py-3 px-6">Size</th>
                                    <th class="text-left py-3 px-6">Age</th>
                                    <th class="text-left py-3 px-6">Last Modified</th>
                                    <th class="text-right py-3 px-6">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach (array_slice($stats['files'], 0, 50) as $file):
                                    $daysOld = (int)((time() - $file['modified']) / (24 * 60 * 60));
                                    $isOld = $daysOld > 7;
                                ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-3 px-6 font-mono text-sm text-gray-900"><?= htmlspecialchars($file['name']) ?></td>
                                        <td class="py-3 px-6 text-sm text-gray-600"><?= htmlspecialchars($file['size_formatted']) ?></td>
                                        <td class="py-3 px-6">
                                            <?php if ($isOld): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800"><?= $daysOld ?> days</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800"><?= $daysOld ?> days</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-600"><?= htmlspecialchars($file['modified_formatted']) ?></td>
                                        <td class="py-3 px-6 text-right">
                                            <div class="flex gap-2 justify-end">
                                                <a href="?action=view_file&file_name=<?= urlencode($file['name']) ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                    View
                                                </a>
                                                <form method="POST" action="?action=delete_file" class="inline" onsubmit="return confirm('Delete <?= htmlspecialchars($file['name']) ?>? This cannot be undone.');">
                                                    <input type="hidden" name="file_name" value="<?= htmlspecialchars($file['name']) ?>">
                                                    <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                                        Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (count($stats['files']) > 50): ?>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 text-center text-sm text-gray-600">
                                Showing 50 of <?= count($stats['files']) ?> files
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif (!isset($viewingFile)): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                    <div class="text-5xl mb-4">📭</div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No log files found</h3>
                    <p class="text-gray-600">Your logs directory is empty or logs haven't been created yet.</p>
                </div>
            <?php endif; ?>

            <?php if (!isset($viewingFile)): ?>
                <!-- Info Card -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Information</h3>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><span class="font-medium text-gray-900">Automatic Cleanup:</span> The system automatically cleans up logs older than 7 days during normal operation.</li>
                        <li><span class="font-medium text-gray-900">Log Rotation:</span> Individual log files are automatically rotated when they reach 5MB.</li>
                        <li><span class="font-medium text-gray-900">Max Files:</span> Each logger keeps up to 5 rotated files (~25MB total per logger).</li>
                        <li><span class="font-medium text-gray-900">Safe Operation:</span> This tool only deletes log files older than the specified age.</li>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>
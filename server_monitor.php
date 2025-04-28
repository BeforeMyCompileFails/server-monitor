<?php
/**
 * Server Monitoring Script
 * 
 * Displays CPU usage, RAM usage, disk usage (GB and I/O rates),
 * temperatures, network usage, running processes, and failed cronjobs
 * by Denis (BeforeMyCompileFails) 2025
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set execution time limit to avoid timeouts
set_time_limit(60);

// Function to execute shell commands and return output
function executeCommand($command) {
    $output = array();
    exec($command, $output, $returnVar);
    
    if ($returnVar !== 0) {
        return "Error executing: $command";
    }
    
    return implode("\n", $output);
}

// Helper function to format bytes to human-readable format
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Helper function to get percentage
function getPercentage($used, $total) {
    if ($total == 0) return 0;
    return round(($used / $total) * 100, 2);
}

// Function to get CPU usage
function getCpuUsage() {
    $cpuInfo = executeCommand("top -bn1 | grep 'Cpu(s)'");
    $cpuLoad = executeCommand("cat /proc/loadavg");
    
    // Get CPU core count for context
    $cpuCores = executeCommand("nproc");
    
    return array(
        'usage' => $cpuInfo,
        'load' => $cpuLoad,
        'cores' => $cpuCores
    );
}

// Function to get RAM usage
function getMemoryUsage() {
    $memInfo = executeCommand("free -m");
    return $memInfo;
}

// Function to get disk usage
function getDiskUsage() {
    // Get all mounted filesystems
    $disks = executeCommand("df -h");
    
    // Get disk I/O statistics
    $diskStats = executeCommand("iostat -d -x 1 2 | tail -n +3");
    
    return array(
        'space' => $disks,
        'io_stats' => $diskStats
    );
}

// Function to get temperature information
function getTemperatures() {
    // Using lm-sensors if available
    $sensorInstalled = executeCommand("which sensors 2>/dev/null");
    if (!empty($sensorInstalled)) {
        return executeCommand("sensors");
    }
    
    // Alternative method using /sys filesystem
    $thermalInfo = executeCommand("find /sys/devices -type f -name 'temp*_input' 2>/dev/null | xargs -I{} sh -c 'echo {} : $(cat {})'");
    if (!empty($thermalInfo)) {
        return $thermalInfo;
    }
    
    return "Temperature sensors not found. Install lm-sensors package with: sudo apt-get install lm-sensors";
}

// Function to get network usage
function getNetworkUsage() {
    // Get network interface statistics
    $netDevs = executeCommand("cat /proc/net/dev | tail -n +3");
    $networkSpeed = "";
    
    // Method 1: Try using iftop for real-time network speed
    $iftopInstalled = executeCommand("which iftop 2>/dev/null");
    if (!empty($iftopInstalled)) {
        // This requires appropriate permissions for the PHP process
        // You may need to add the web server user to sudoers with NOPASSWD for iftop
        $iftopOutput = executeCommand("sudo iftop -t -s 2 -L 5 2>&1");
        
        // Check if iftop executed successfully
        if (strpos($iftopOutput, 'permission denied') === false && 
            strpos($iftopOutput, 'error') === false &&
            !empty($iftopOutput)) {
            $networkSpeed = "Real-time network traffic (iftop):\n$iftopOutput";
        }
    }
    
    // Method 2: Try using vnstat if iftop failed
    if (empty($networkSpeed)) {
        $vnstatInstalled = executeCommand("which vnstat 2>/dev/null");
        if (!empty($vnstatInstalled)) {
            $vnstatOutput = executeCommand("vnstat -tr 2 2>&1");
            if (!empty($vnstatOutput)) {
                $networkSpeed = "Current network traffic (vnstat):\n$vnstatOutput";
            }
        }
    }
    
    // Method 3: Calculate network speeds manually if other methods failed
    if (empty($networkSpeed)) {
        $networkSpeed = "Current network speeds (calculated):\n";
        $interfaces = executeCommand("ls -1 /sys/class/net | grep -v lo");
        $interfaces = explode("\n", $interfaces);
        
        foreach ($interfaces as $interface) {
            if (empty($interface)) continue;
            
            // Get initial bytes
            $rxBytesStart = (int)executeCommand("cat /sys/class/net/$interface/statistics/rx_bytes");
            $txBytesStart = (int)executeCommand("cat /sys/class/net/$interface/statistics/tx_bytes");
            
            // Wait briefly
            usleep(1000000); // 1 second
            
            // Get final bytes
            $rxBytesEnd = (int)executeCommand("cat /sys/class/net/$interface/statistics/rx_bytes");
            $txBytesEnd = (int)executeCommand("cat /sys/class/net/$interface/statistics/tx_bytes");
            
            // Calculate speed
            $rxSpeed = $rxBytesEnd - $rxBytesStart; // bytes per second
            $txSpeed = $txBytesEnd - $txBytesStart; // bytes per second
            
            $networkSpeed .= "$interface: Download: " . formatBytes($rxSpeed) . "/s, Upload: " . formatBytes($txSpeed) . "/s\n";
        }
    }
    
    return array(
        'interfaces' => $netDevs,
        'speed_info' => $networkSpeed
    );
}

// Function to get running processes (like top)
function getProcessList() {
    $processes = executeCommand("ps aux --sort=-%cpu | head -11"); // Top 10 + header
    return $processes;
}

// Function to check for failed cronjobs
function getFailedCronjobs() {
    // Check system logs for cron failures
    $syslogCronFails = executeCommand("grep CRON /var/log/syslog | grep -i fail 2>/dev/null | tail -10");
    
    // Check for mail to root or users that might indicate cron failures
    $mailFails = executeCommand("find /var/mail -type f -not -empty -exec head -20 {} \; 2>/dev/null | grep -A5 'Cron.*fail' 2>/dev/null");
    
    $result = "";
    if (!empty($syslogCronFails)) {
        $result .= "Recent cron failures from syslog:\n" . $syslogCronFails . "\n\n";
    }
    
    if (!empty($mailFails)) {
        $result .= "Potential cron failures from mail:\n" . $mailFails;
    }
    
    if (empty($result)) {
        $result = "No recent cron failures detected in logs.";
    }
    
    return $result;
}

// Function to get PHP errors
function getPhpErrors() {
    // Common PHP error log locations
    $possibleLogs = array(
        '/var/log/php_errors.log',
        '/var/log/php-fpm/error.log',
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        '/var/log/www/error.log',
        '/var/log/php-fpm/www-error.log'
    );
    
    $result = "";
    
    // Try to get PHP error_log path from php.ini
    $phpIniLog = executeCommand("php -i | grep error_log | grep -v 'no value' | head -1");
    if (!empty($phpIniLog) && preg_match('/=> (.+)$/', $phpIniLog, $matches)) {
        $possibleLogs[] = trim($matches[1]);
    }
    
    // Check each possible log file
    foreach ($possibleLogs as $logFile) {
        if (file_exists($logFile) && is_readable($logFile)) {
            $errors = executeCommand("tail -n 20 $logFile | grep -i 'error\\|fatal\\|warning\\|parse'");
            if (!empty($errors)) {
                $result .= "Recent PHP errors from $logFile:\n$errors\n\n";
            }
        }
    }
    
    if (empty($result)) {
        $result = "No recent PHP errors found in common log locations.";
    }
    
    return $result;
}

// Function to get SQL errors
function getSqlErrors() {
    // Common MySQL/MariaDB error log locations
    $possibleLogs = array(
        '/var/log/mysql/error.log',
        '/var/log/mariadb/mariadb.err',
        '/var/lib/mysql/*.err'
    );
    
    $result = "";
    
    // Check each possible log location
    foreach ($possibleLogs as $logPattern) {
        $logs = executeCommand("ls -1 $logPattern 2>/dev/null");
        $logFiles = explode("\n", $logs);
        
        foreach ($logFiles as $logFile) {
            if (!empty($logFile) && file_exists($logFile) && is_readable($logFile)) {
                $errors = executeCommand("tail -n 20 $logFile | grep -i 'error\\|warning\\|fail'");
                if (!empty($errors)) {
                    $result .= "Recent SQL errors from $logFile:\n$errors\n\n";
                }
            }
        }
    }
    
    if (empty($result)) {
        $result = "No recent SQL errors found in common log locations.";
    }
    
    return $result;
}

// Function to get OpenResty/Nginx errors
function getOpenRestyErrors() {
    // Common OpenResty/Nginx error log locations
    $possibleLogs = array(
        '/var/log/openresty/error.log',
        '/usr/local/openresty/nginx/logs/error.log',
        '/var/log/nginx/error.log'
    );
    
    $result = "";
    
    // Check each possible log location
    foreach ($possibleLogs as $logFile) {
        if (file_exists($logFile) && is_readable($logFile)) {
            $errors = executeCommand("tail -n 20 $logFile | grep -i 'error\\|fatal\\|emerg\\|crit'");
            if (!empty($errors)) {
                $result .= "Recent OpenResty/Nginx errors from $logFile:\n$errors\n\n";
            }
        }
    }
    
    if (empty($result)) {
        $result = "No recent OpenResty/Nginx errors found in common log locations.";
    }
    
    return $result;
}

// Function to get UFW blocked IPs
function getUfwBlocked() {
    // Check if UFW is installed and enabled
    $ufwStatus = executeCommand("which ufw 2>/dev/null && ufw status");
    if (empty($ufwStatus) || strpos($ufwStatus, 'inactive') !== false) {
        return "UFW is not installed or not active.";
    }
    
    // Get blocked connections from UFW log
    $blockedIps = executeCommand("grep -i 'UFW BLOCK' /var/log/ufw.log 2>/dev/null | tail -20");
    
    // Extract and count unique IPs
    $ips = array();
    if (!empty($blockedIps)) {
        preg_match_all('/SRC=(\d+\.\d+\.\d+\.\d+)/', $blockedIps, $matches);
        if (!empty($matches[1])) {
            $ipCounts = array_count_values($matches[1]);
            arsort($ipCounts);
            $result = "Top blocked IPs (UFW):\n";
            $i = 0;
            foreach ($ipCounts as $ip => $count) {
                $result .= "$ip - $count blocks\n";
                $i++;
                if ($i >= 10) break;
            }
            return $result;
        }
    }
    
    return "No recently blocked IPs found in UFW logs.\nCheck /var/log/ufw.log for more information.";
}

// Main function to gather all information
function gatherServerInfo() {
    $info = array();
    
    $info['cpu'] = getCpuUsage();
    $info['memory'] = getMemoryUsage();
    $info['disk'] = getDiskUsage();
    $info['temperature'] = getTemperatures();
    $info['network'] = getNetworkUsage();
    $info['processes'] = getProcessList();
    $info['failed_cronjobs'] = getFailedCronjobs();
    $info['php_errors'] = getPhpErrors();
    $info['sql_errors'] = getSqlErrors();
    $info['openresty_errors'] = getOpenRestyErrors();
    $info['ufw_blocked'] = getUfwBlocked();
    
    return $info;
}

// Refresh interval (in seconds) for auto-refresh
$refreshInterval = 60;

// Check if requested via AJAX
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// If AJAX request, return only the data
if ($isAjax) {
    $serverInfo = gatherServerInfo();
    echo json_encode($serverInfo);
    exit;
}

// Gather server information
$serverInfo = gatherServerInfo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Monitoring</title>
    <style>
        body {
            font-family: 'Ubuntu', Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .panel {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            margin-bottom: 20px;
            padding: 15px;
            overflow: hidden;
        }
        .panel-header {
            font-weight: bold;
            font-size: 18px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
            color: #2196F3;
        }
        pre {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 10px;
            overflow-x: auto;
            font-family: monospace;
            font-size: 14px;
        }
        .refresh-info {
            text-align: right;
            color: #666;
            font-size: 12px;
            margin-bottom: 10px;
        }
        .loader {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: none;
            margin-left: 10px;
            vertical-align: middle;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .btn {
            background-color: #4CAF50;
            border: none;
            color: white;
            padding: 8px 16px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 4px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Support Pal Server Monitoring</h1>
            <div>
                <span id="last-update"></span>
                <div class="loader" id="loader"></div>
                <button class="btn" onclick="refreshData()">Refresh Now</button>
            </div>
        </div>

        <!-- CPU Usage -->
        <div class="panel">
            <div class="panel-header">CPU Usage</div>
            <pre id="cpu-info"><?php 
                echo "CPU Usage: " . $serverInfo['cpu']['usage'] . "\n";
                echo "Load Average: " . $serverInfo['cpu']['load'] . "\n";
                echo "CPU Cores: " . $serverInfo['cpu']['cores'];
            ?></pre>
        </div>

        <!-- Memory Usage -->
        <div class="panel">
            <div class="panel-header">Memory Usage</div>
            <pre id="memory-info"><?php echo $serverInfo['memory']; ?></pre>
        </div>

        <!-- Disk Usage -->
        <div class="panel">
            <div class="panel-header">Disk Usage</div>
            <pre id="disk-space"><?php echo $serverInfo['disk']['space']; ?></pre>
            <div class="panel-header">Disk I/O Statistics</div>
            <pre id="disk-io"><?php echo $serverInfo['disk']['io_stats']; ?></pre>
        </div>

        <!-- Temperature Information -->
        <div class="panel">
            <div class="panel-header">Temperature Information</div>
            <pre id="temp-info"><?php echo $serverInfo['temperature']; ?></pre>
        </div>

        <!-- Network Usage -->
        <div class="panel">
            <div class="panel-header">Network Interfaces</div>
            <pre id="network-info"><?php echo $serverInfo['network']['interfaces']; ?></pre>
            <div class="panel-header">Network Speed</div>
            <pre id="network-speed"><?php echo $serverInfo['network']['speed_info']; ?></pre>
        </div>

        <!-- Running Processes -->
        <div class="panel">
            <div class="panel-header">Top Processes</div>
            <pre id="process-list"><?php echo $serverInfo['processes']; ?></pre>
        </div>

        <!-- Failed Cronjobs -->
        <div class="panel">
            <div class="panel-header">Failed Cronjobs</div>
            <pre id="cronjobs-info"><?php echo $serverInfo['failed_cronjobs']; ?></pre>
        </div>

        <!-- PHP Errors -->
        <div class="panel">
            <div class="panel-header">PHP Errors</div>
            <pre id="php-errors"><?php echo $serverInfo['php_errors']; ?></pre>
        </div>

        <!-- SQL Errors -->
        <div class="panel">
            <div class="panel-header">SQL Errors</div>
            <pre id="sql-errors"><?php echo $serverInfo['sql_errors']; ?></pre>
        </div>

        <!-- OpenResty/Nginx Errors -->
        <div class="panel">
            <div class="panel-header">OpenResty/Nginx Errors</div>
            <pre id="openresty-errors"><?php echo $serverInfo['openresty_errors']; ?></pre>
        </div>

        <!-- UFW Blocked IPs -->
        <div class="panel">
            <div class="panel-header">UFW Blocked IPs</div>
            <pre id="ufw-blocked"><?php echo $serverInfo['ufw_blocked']; ?></pre>
        </div>
    </div>
    
    <div class="panel" style="text-align: center; font-size: 12px; color: #666;">
        <p>&copy; 2025 by Denis (BeforeMyCompileFails) 2025</p>
    </div>

    <script>
        // Update the last update time
        function updateLastUpdateTime() {
            const now = new Date();
            document.getElementById('last-update').textContent = 
                `Last updated: ${now.toLocaleDateString()} ${now.toLocaleTimeString()}`;
        }
        
        // Initial update
        updateLastUpdateTime();
        
        // Function to refresh data
        function refreshData() {
            const loader = document.getElementById('loader');
            loader.style.display = 'inline-block';
            
            // Make AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('GET', '<?php echo $_SERVER["PHP_SELF"]; ?>');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        
                        // Update all sections
                        document.getElementById('cpu-info').textContent = 
                            `CPU Usage: ${data.cpu.usage}\nLoad Average: ${data.cpu.load}\nCPU Cores: ${data.cpu.cores}`;
                        
                        document.getElementById('memory-info').textContent = data.memory;
                        document.getElementById('disk-space').textContent = data.disk.space;
                        document.getElementById('disk-io').textContent = data.disk.io_stats;
                        document.getElementById('temp-info').textContent = data.temperature;
                        document.getElementById('network-info').textContent = data.network.interfaces;
                        document.getElementById('network-speed').textContent = data.network.speed_info;
                        document.getElementById('process-list').textContent = data.processes;
                        document.getElementById('cronjobs-info').textContent = data.failed_cronjobs;
                        document.getElementById('php-errors').textContent = data.php_errors;
                        document.getElementById('sql-errors').textContent = data.sql_errors;
                        document.getElementById('openresty-errors').textContent = data.openresty_errors;
                        document.getElementById('ufw-blocked').textContent = data.ufw_blocked;
                        
                        updateLastUpdateTime();
                    } catch (e) {
                        console.error('Error parsing response:', e);
                    }
                }
                
                loader.style.display = 'none';
            };
            
            xhr.onerror = function() {
                loader.style.display = 'none';
                alert('Error refreshing data. Please try again.');
            };
            
            xhr.send();
        }
        
        // Set auto-refresh
        setInterval(refreshData, <?php echo $refreshInterval * 1000; ?>);
    </script>
</body>
</html>

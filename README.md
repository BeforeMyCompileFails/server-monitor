# 🚀 Server Monitoring

A comprehensive PHP monitoring script for **Ubuntu servers**.  
This tool provides a real-time web dashboard displaying critical server metrics including **CPU**, **memory**, **disk**, **network usage**, **temperatures**, **processes**, **error logs**, and **security information**.

---

## ✨ Features

### 💻 System Metrics
- 🦰 **CPU Statistics** — Usage %, load averages, and core count
- 📈 **Memory Usage** — RAM utilization and swap info
- 📥 **Disk Usage** — Space consumption across all mounted filesystems (NVRAM, SATA, etc.)
- 📊 **Disk I/O** — Read/write rates and IOPS
- 🌡️ **Temperature** — System thermal readings (requires `lm-sensors` or access to `/sys`)
- 🌐 **Network Usage** — Interface statistics and real-time bandwidth
- 📝 **Process List** — Top CPU and memory consuming processes (like `top`)

### ⚠️ Error Monitoring
- ⚠️ **PHP Errors** — Recent errors, warnings, and notices
- ❌ **SQL Errors** — Database errors from MySQL/MariaDB logs
- 🚰 **Web Server Errors** — OpenResty/Nginx logs
- ⏰ **Failed Cronjobs** — Detection of failed scheduled tasks

### 🛡️ Security Information
- 🔥 **UFW Firewall** — Last 10 blocked IPs with block counts

---

## ⚙️ Requirements

- Ubuntu Server (latest **LTS** recommended)
- PHP **7.4+** with `exec()` enabled
- Web server (OpenResty, Nginx, Apache)
- Basic system utilities (`top`, `iostat`, `free`, etc.)
- Optional: `lm-sensors`, `iftop`, `vnstat`

---

## 📦 Installation

### 1. Clone this repository

```bash
git clone https://github.com/BeforeMyCompileFails/server-monitor.git
cd server-monitor
```

### 2. Move the monitoring script to your web root

```bash
cp server_monitor.php /var/www/html/monitor.php
```

### 3. Set correct permissions

```bash
chmod 644 /var/www/html/monitor.php
chown www-data:www-data /var/www/html/monitor.php
```

### 4. (Optional) Enable network monitoring with iftop

Edit sudoers:

```bash
sudo visudo -f /etc/sudoers.d/iftop-web
```

Then add the following line:

```bash
www-data ALL=(ALL) NOPASSWD: /usr/sbin/iftop
```

### 5. Access your monitoring dashboard

Visit your browser:

```
http://your-server-ip/monitor.php
```

---

## 🔧 Configuration

The script works out-of-the-box for most setups.  
You may want to:

- 🕒 Adjust the refresh interval (default: 60 seconds)
- 🛠️ Modify log file paths if your system uses non-standard locations
- 🔐 Protect the monitoring page with authentication

---

### 🔐 Securing Access

Create an `.htaccess` file:

```bash
cat > /var/www/html/.htaccess << EOF
AuthType Basic
AuthName "Server Monitoring"
AuthUserFile /etc/apache2/.htpasswd
Require valid-user
EOF
```

Create a password file:

```bash
sudo htpasswd -c /etc/apache2/.htpasswd admin
```

Restart Apache if needed:

```bash
sudo systemctl restart apache2
```

---

## 🛠️ Troubleshooting

### ⚡ Permission Issues

If you see "Error executing" messages:

- Ensure the web server user (`www-data`) can execute necessary system commands.
- Verify iftop sudoers configuration if using network monitoring.
- Ensure log files are readable by the web server user.

### 👵 Missing Data

Install required packages:

```bash
sudo apt-get install sysstat lm-sensors iftop vnstat
```

Start and enable services:

```bash
sudo systemctl enable sysstat
sudo systemctl start sysstat
sudo systemctl enable vnstat
sudo systemctl start vnstat
sudo sensors-detect
```

Check that log file paths are correct.

---

## 🎨 Customization

You can easily:

- Change the page title or branding
- Edit the CSS styles inside the script
- Add new monitoring panels inside the `gatherServerInfo()` function

---

## 📜 License

This project is licensed under the **MIT License**.  
See the [LICENSE](LICENSE) file for full details.

---

## 🙏 Acknowledgments

- Made with ❤️ by **Denis** ([BeforeMyCompileFails](https://github.com/BeforeMyCompileFails)) — 2025
- Built for modern **server monitoring** needs
- Powered by native **Linux utilities** and **PHP**

---

## 🛠️ Support

- 🤛 Found a bug?
- 💡 Have a feature request?
- 🎉 Want to contribute?

Open an [issue](https://github.com/BeforeMyCompileFails/server-monitor/issues) or submit a pull request!

---

# 🌟 Monitor smarter, not harder.

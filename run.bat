@echo off
setlocal
cd /d "%~dp0"

set "PHP=%~dp0..\tools\php\php.exe"
if not exist "%PHP%" set "PHP=php"

echo [闻川商城] 正在启动主系统：http://127.0.0.1:18102
start "WenchuanMall" "%PHP%" -S 127.0.0.1:18102 -t "%~dp0public"

echo [闻川商城] 正在启动物流跟踪网关：http://127.0.0.1:18103/tracking.txt
start "LogisticsGateway" "%PHP%" -S 127.0.0.1:18103 -t "%~dp0integrations\logistics-gateway" "%~dp0integrations\logistics-gateway\router.php"

timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:18102/"

echo.
echo 服务已启动。关闭对应命令行窗口即可停止服务。
echo 默认账号：admin / Wenchuan@2026
echo 默认账号：ops / Mall@2026
endlocal

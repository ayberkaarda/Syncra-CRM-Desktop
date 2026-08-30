@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

echo ============================================
echo  Syncra - Gelistirme Ortami Baslatiliyor
echo ============================================
echo.

REM --- PHP PATH kontrolu -----------------------------------------------
where php >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    set "PHP=php"
) else (
    set "PHP=C:\xampp\php\php.exe"
    echo [UYARI] "php" komutu PATH'te bulunamadi. Tam yol kullanilacak: !PHP!
)
echo.

REM ==========================================================================
REM  1) MySQL (MariaDB - XAMPP)
REM     Servis olarak kurulu degil; bu script tarafindan elle baslatilir ve
REM     3306 portunun acilmasi beklenir.
REM ==========================================================================
set "MYSQL_READY=0"
netstat -an | findstr ":3306" | findstr "LISTENING" >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    echo [OK] MySQL 3306 portunda zaten calisiyor.
    set "MYSQL_READY=1"
) else (
    echo MySQL baslatiliyor...
    if exist "C:\xampp\mysql_start.bat" (
        start "Syncra MySQL" cmd /k "C:\xampp\mysql_start.bat"
    ) else if exist "C:\xampp\mysql\bin\mysqld.exe" (
        start "Syncra MySQL" cmd /k "C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone"
    ) else (
        echo [HATA] MySQL baslatma dosyasi bulunamadi: C:\xampp\mysql_start.bat ve C:\xampp\mysql\bin\mysqld.exe yok.
        echo         XAMPP kurulumunu kontrol edin.
    )

    echo MySQL'in 3306 portunda hazir olmasi bekleniyor ^(en fazla 30 sn^)...
    call :wait_for_port 3306 30
    if "!PORT_READY!"=="1" set "MYSQL_READY=1"
)

if "!MYSQL_READY!"=="1" (
    echo [OK] MySQL hazir.
) else (
    echo [HATA] MySQL 30 saniye icinde 3306 portunda baslamadi.
    echo         API, queue worker ve scheduler veritabanina bagli oldugu icin duzgun calismayacaktir.
    echo         C:\xampp\mysql_start.bat veya XAMPP Control Panel ile MySQL'i elle baslatip bu pencereyi acik birakin.
)
echo.

REM ==========================================================================
REM  2) Redis (WSL2 Ubuntu icinde calisiyor)
REM     ONEMLI: Redis WSL icinde ayakta olsa bile, WSL2 dagitimi bosta kalinca
REM     Windows -> 127.0.0.1:6379 localhost port aktarimi DUSER ve Windows
REM     tarafindan gelen baglantilar reddedilir ("Hedef makine etkin olarak
REM     reddettiginden baglanti kurulamadi" hatasi alinir). Bu yuzden
REM     dagitimi ayakta tutmak icin ayri, uzun omurlu bir WSL penceresi
REM     aciliyor ve acik birakiliyor ("Syncra Redis (WSL)").
REM     BU PENCEREYI KAPATMAYIN - kapatirsaniz Redis Windows tarafindan
REM     erisilemez hale gelir.
REM ==========================================================================
set "REDIS_READY=0"
netstat -an | findstr ":6379" | findstr "LISTENING" >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    echo [OK] Redis 6379 portunda zaten calisiyor.
    set "REDIS_READY=1"
) else (
    echo Redis baslatiliyor ^(WSL^)...
    start "Syncra Redis (WSL)" cmd /k "wsl -e sh -c "redis-server --daemonize yes; echo Redis hazir; sleep infinity""

    echo Redis'in 6379 portunda hazir olmasi bekleniyor ^(en fazla 30 sn^)...
    call :wait_for_port 6379 30
    if "!PORT_READY!"=="1" set "REDIS_READY=1"
)

if "!REDIS_READY!"=="1" (
    echo [OK] Redis hazir.
) else (
    echo [HATA] Redis 30 saniye icinde 6379 portunda baslamadi.
    echo         Acilan "Syncra Redis (WSL)" penceresini kontrol edin.
)
echo.

REM ==========================================================================
REM  3) Uygulama surecleri - ayri pencerelerde baslatilir.
REM     Sira onemli: Reverb ve queue Redis'e, API ise MySQL'e bagimlidir; bu
REM     yuzden altyapi servislerinden sonra baslatiliyorlar.
REM ==========================================================================
echo Reverb (WebSocket) baslatiliyor (port 8080)...
start "Syncra Reverb" cmd /k "cd /d "%~dp0backend" && "%PHP%" artisan reverb:start"

echo API baslatiliyor (port 8000)...
start "Syncra API" cmd /k "cd /d "%~dp0backend" && "%PHP%" artisan serve"

echo Queue worker baslatiliyor...
start "Syncra Queue" cmd /k "cd /d "%~dp0backend" && "%PHP%" artisan queue:work"

echo Scheduler baslatiliyor (logs:prune, tasks:dispatch-reminders, tickets:scan-sla)...
start "Syncra Scheduler" cmd /k "cd /d "%~dp0backend" && "%PHP%" artisan schedule:work"

echo Frontend baslatiliyor (port 5173)...
start "Syncra Frontend" cmd /k "cd /d "%~dp0frontend" && npm run dev"

echo.
echo ============================================
echo  Tum surecler baslatildi.
if "!MYSQL_READY!"=="1" (echo    MySQL     : [OK] 127.0.0.1:3306) else (echo    MySQL     : [HATA] baslamadi, yukariya bakin)
if "!REDIS_READY!"=="1" (echo    Redis     : [OK] 127.0.0.1:6379 - WSL penceresini kapatmayin) else (echo    Redis     : [HATA] baslamadi, yukariya bakin)
echo    API       : http://localhost:8000
echo    Frontend  : http://localhost:5173
echo    Reverb    : ws://localhost:8080
echo    Scheduler : calisiyor - Syncra Scheduler penceresi
echo ============================================
echo.
echo Bu pencereyi kapatabilirsiniz; servisler kendi pencerelerinde calismaya devam eder.
pause >nul

endlocal
exit /b 0

REM ==========================================================================
REM  Yardimci fonksiyon: verilen portun LISTENING olmasini bekler.
REM  Kullanim : call :wait_for_port <port> <max_saniye>
REM  Sonuc    : PORT_READY = 1 (hazir) veya 0 (zaman asimi)
REM ==========================================================================
:wait_for_port
set "WFP_PORT=%~1"
set "WFP_MAX=%~2"
set "PORT_READY=0"
for /l %%i in (1,1,%WFP_MAX%) do (
    netstat -an | findstr ":%WFP_PORT%" | findstr "LISTENING" >nul 2>nul
    if not errorlevel 1 set "PORT_READY=1"
    if "!PORT_READY!"=="1" goto :wfp_done
    timeout /t 1 /nobreak >nul 2>nul
)
:wfp_done
exit /b 0

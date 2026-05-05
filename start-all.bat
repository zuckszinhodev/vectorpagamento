@echo off
title Vector Pagamentos Gateway - Sistema Completo

echo ========================================
echo     Vector Pagamentos Gateway - Iniciar Tudo
echo ========================================
echo.
echo Este script vai iniciar:
echo  - PostgreSQL local (servico)
echo  - Backend (Laravel) em http://127.0.0.1:8000
echo  - Frontend (React) em http://localhost:5173
echo.
echo Aguarde...
echo.

REM Tenta iniciar servico PostgreSQL local (se existir)
net start postgresql-x64-16 >nul 2>&1

REM Inicia o backend em uma nova janela
start "Vector Pagamentos - Backend" cmd /k "cd backend && start.bat"

REM Aguarda 3 segundos para o backend iniciar
timeout /t 3 /nobreak > nul

REM Inicia o frontend em uma nova janela
start "Vector Pagamentos - Frontend" cmd /k "cd frontend && start.bat"

echo.
echo ========================================
echo Sistema iniciado com sucesso!
echo ========================================
echo.
echo Backend: http://127.0.0.1:8000
echo Frontend: http://localhost:5173
echo.
echo Pressione qualquer tecla para abrir o navegador...
pause > nul

REM Abre o navegador
start http://localhost:5173

echo.
echo Para parar os servidores, feche as janelas do:
echo  - Vector Pagamentos - Backend
echo  - Vector Pagamentos - Frontend
echo.

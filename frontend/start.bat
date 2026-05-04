@echo off
echo ========================================
echo   GatePro Gateway - Start Frontend
echo ========================================
echo.

cd /d "%~dp0"

echo [1/3] Verificando configuracao...
if not exist ".env" (
    echo AVISO: Arquivo .env nao encontrado!
    echo Usando configuracao padrao...
    echo.
)

echo [2/3] Verificando dependencias...
if not exist "node_modules" (
    echo Instalando dependencias...
    call npm install
)

echo [3/3] Iniciando servidor Vite...
echo.
echo Frontend rodando em: http://localhost:5173
echo.
echo Pressione Ctrl+C para parar o servidor
echo.

call npm run dev

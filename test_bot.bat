@echo off
SETLOCAL ENABLEDELAYEDEXPANSION

:: Base URL for your local PHP server
set URL=http://localhost:8080/chat

:: Array of test messages
set MESSAGES[0]=Merit list for CS BS at FAST Islamabad 2024
set MESSAGES[1]=Merit list for Software Engineering MS at NUST Islamabad 2023
set MESSAGES[2]=Merit list for Electrical BS at COMSATS Lahore 2022
set MESSAGES[3]=Merit list for Cyber Security MS at Air University Islamabad 2024
set MESSAGES[4]=Merit list for Mechanical BS at PIEAS Islamabad 2023

:: Loop through messages
for /L %%i in (0,1,4) do (
    set MSG=!MESSAGES[%%i]!
    echo.
    echo Sending message: !MSG!
    curl -X POST "!URL!" -H "Content-Type: application/json" -d "{\"message\":\"!MSG!\"}"
    echo.
    timeout /t 1 >nul
)

echo.
echo All tests completed.
pause

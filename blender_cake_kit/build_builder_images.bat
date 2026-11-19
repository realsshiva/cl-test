@echo off
rem Rebuilds the Cake Builder's 3D images (invtest\assets\cake-renders).
rem Run it again after adding or changing Custom Cake recipe sizes. Takes about an hour with a GPU.
rem Extra options are passed to the renderer, e.g.:  build_builder_images.bat --rigs none --samples 32
cd /d "%~dp0"
echo Reading cake sizes from the database...
"D:\Xampp\php\php.exe" list_cake_sizes.php > cake_sizes.txt || goto :error
type cake_sizes.txt
echo.
echo Rendering (Blender runs in the background, this window shows progress)...
"D:\SteamLibrary\steamapps\common\Blender\blender.exe" -b -P render_builder_assets.py -- %* || goto :error
echo.
echo Finished. Reload the Cake Builder page to see the new images.
pause
exit /b 0

:error
echo.
echo Something went wrong - see the messages above. Is MySQL running in XAMPP?
pause
exit /b 1

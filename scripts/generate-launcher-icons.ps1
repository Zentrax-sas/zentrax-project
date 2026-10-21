# Resize the official artwork without redrawing it. Run from any directory.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$root = Split-Path $PSScriptRoot -Parent
$source = [Drawing.Bitmap]::new((Join-Path $root 'src/assets/logo_sin_fondo2.png'))
$minX = $source.Width
$minY = $source.Height
$maxX = -1
$maxY = -1
for ($y = 0; $y -lt $source.Height; $y++) {
    for ($x = 0; $x -lt $source.Width; $x++) {
        if ($source.GetPixel($x, $y).A -gt 0) {
            $minX = [Math]::Min($minX, $x)
            $minY = [Math]::Min($minY, $y)
            $maxX = [Math]::Max($maxX, $x)
            $maxY = [Math]::Max($maxY, $y)
        }
    }
}
if ($maxX -lt $minX -or $maxY -lt $minY) { throw 'Launcher artwork has no visible pixels.' }
$artwork = $source.Clone(
    [Drawing.Rectangle]::new($minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1),
    $source.PixelFormat
)
$background = [Drawing.ColorTranslator]::FromHtml('#0B0F0D')
try {
    foreach ($density in @(@('mdpi',1), @('hdpi',1.5), @('xhdpi',2), @('xxhdpi',3), @('xxxhdpi',4))) {
        foreach ($kind in @('ic_launcher','ic_launcher_round','ic_launcher_foreground')) {
            $adaptive = $kind -eq 'ic_launcher_foreground'
            $size = [int]($(if ($adaptive) {108} else {48}) * $density[1])
            $bitmap = [Drawing.Bitmap]::new($size,$size)
            $graphics = [Drawing.Graphics]::FromImage($bitmap)
            try {
                $graphics.Clear([Drawing.Color]::Transparent)
                $graphics.SmoothingMode = [Drawing.Drawing2D.SmoothingMode]::AntiAlias
                if (-not $adaptive) {
                    $brush = [Drawing.SolidBrush]::new($background)
                    try {
                        if ($kind -eq 'ic_launcher_round') { $graphics.FillEllipse($brush,0,0,$size,$size) }
                        else { $graphics.Clear($background) }
                    } finally { $brush.Dispose() }
                }
                $drawSource = if ($adaptive) { $artwork } else { $source }
                # Crop transparent source padding, then keep all adaptive artwork inside the safe circle.
                $scale = if ($adaptive) {
                    (64 * $density[1]) / [Math]::Sqrt($drawSource.Width*$drawSource.Width + $drawSource.Height*$drawSource.Height)
                } else { 0.82 * $size / [Math]::Max($source.Width,$source.Height) }
                $width = [single]($drawSource.Width * $scale)
                $height = [single]($drawSource.Height * $scale)
                $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
                $graphics.PixelOffsetMode = [Drawing.Drawing2D.PixelOffsetMode]::HighQuality
                $graphics.DrawImage($drawSource,[single](($size-$width)/2),[single](($size-$height)/2),$width,$height)
                $bitmap.Save((Join-Path $root "android/app/src/main/res/mipmap-$($density[0])/$kind.png"),[Drawing.Imaging.ImageFormat]::Png)
            } finally { $graphics.Dispose(); $bitmap.Dispose() }
        }
    }
} finally { $artwork.Dispose(); $source.Dispose() }

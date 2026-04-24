$bytes = [System.IO.File]::ReadAllBytes('D:\laragon\www\stamgast\public\uploads\logos\tenant_1_1776966468.png')
Write-Host "File size: $($bytes.Length) bytes"
Write-Host "ColorType byte (offset 25): $($bytes[25])"
Write-Host "BitDepth byte (offset 24): $($bytes[24])"
$ct = $bytes[25]
switch ($ct) {
    2 { Write-Host "ColorType 2 = RGB (NO alpha)" }
    3 { Write-Host "ColorType 3 = Indexed/Palette" }
    4 { Write-Host "ColorType 4 = Grayscale+Alpha" }
    6 { Write-Host "ColorType 6 = RGBA (TRUE alpha)" }
    default { Write-Host "ColorType $ct = Unknown" }
}

$bytes = [System.IO.File]::ReadAllBytes('D:\laragon\www\stamgast\public\uploads\logos\tenant_1_1776967311.webp')
Write-Host "File size: $($bytes.Length) bytes"
# Check RIFF/WEBP header
$header = [System.Text.Encoding]::ASCII.GetString($bytes[0..3])
$format = [System.Text.Encoding]::ASCII.GetString($bytes[8..11])
Write-Host "Header: $header ($format)"

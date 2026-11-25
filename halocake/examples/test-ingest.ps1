# Jalankan dari PowerShell: .\examples\test-ingest.ps1
# Jika mengganti kunci di config/app.php, sesuaikan -ApiKey di sini.
param(
    [string]$Url = 'http://localhost/halocake/api/ingest.php',
    [string]$ApiKey = 'halocake-dev-2026-GANTI-DENGAN-KUNCI-RAHASIA'
)
$headers = @{ 'X-API-Key' = $ApiKey }
$steps = @(
    @{statusSistem='Idle'; statusPalang='Terbuka'; sensor1=$false; sensor2=$false; jumlahGerbong=0},
    @{statusSistem='Warning'; statusPalang='Terbuka'; sensor1=$true; sensor2=$false; jumlahGerbong=0},
    @{statusSistem='Warning'; statusPalang='Menutup'; sensor1=$true; sensor2=$false; jumlahGerbong=0},
    @{statusSistem='Closed'; statusPalang='Tertutup'; sensor1=$true; sensor2=$false; jumlahGerbong=0},
    @{statusSistem='Closed'; statusPalang='Tertutup'; sensor1=$false; sensor2=$false; jumlahGerbong=1},
    @{statusSistem='Closed'; statusPalang='Tertutup'; sensor1=$false; sensor2=$false; jumlahGerbong=2},
    @{statusSistem='Closed'; statusPalang='Tertutup'; sensor1=$false; sensor2=$true; jumlahGerbong=3},
    @{statusSistem='Warning'; statusPalang='Terbuka'; sensor1=$false; sensor2=$true; jumlahGerbong=3},
    @{statusSistem='Idle'; statusPalang='Terbuka'; sensor1=$false; sensor2=$false; jumlahGerbong=0}
)
foreach ($step in $steps) {
    $body = $step | ConvertTo-Json -Compress
    $response = Invoke-RestMethod -Uri $Url -Method Post -ContentType 'application/json' -Headers $headers -Body $body
    Write-Host "$($step.statusSistem) | $($step.statusPalang) | Gerbong $($step.jumlahGerbong) => $($response.message)"
    Start-Sleep -Seconds 1
}
Write-Host 'Simulasi selesai. Buka http://localhost/halocake/ untuk melihat data.'

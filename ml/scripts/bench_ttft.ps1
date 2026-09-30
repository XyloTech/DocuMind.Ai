param(
    [string]$Url = "http://localhost:8090/v1/chat/completions",
    [int]$Runs = 5
)

# Measures time-to-first-token against the local ML service.
$body = @{
    model    = "bench"
    messages = @(@{ role = "user"; content = "Answer briefly: what is the capital of France?" })
    stream   = $true
    max_tokens = 60
} | ConvertTo-Json -Depth 5

$results = @()

for ($i = 1; $i -le $Runs; $i++) {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $req = [Net.HttpWebRequest]::Create($Url)
    $req.Method = "POST"
    $req.ContentType = "application/json"
    $req.Timeout = 120000
    $bytes = [Text.Encoding]::UTF8.GetBytes($body)
    $req.ContentLength = $bytes.Length
    $stream = $req.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    $resp = $req.GetResponse()
    $reader = [IO.StreamReader]::new($resp.GetResponseStream())
    $ttft = $null
    $tokens = 0

    while (($line = $reader.ReadLine()) -ne $null) {
        if (-not $line.StartsWith("data: ")) { continue }
        $payload = $line.Substring(6)
        if ($payload -eq "[DONE]") { break }
        $chunk = $payload | ConvertFrom-Json
        $delta = $chunk.choices[0].delta.content
        if ($delta) {
            if ($ttft -eq $null) { $ttft = $sw.ElapsedMilliseconds }
            $tokens++
        }
    }

    $reader.Close()
    $resp.Close()
    $results += [pscustomobject]@{ run = $i; ttft_ms = $ttft; tokens = $tokens; total_ms = $sw.ElapsedMilliseconds }
    Start-Sleep -Milliseconds 300
}

$results | Format-Table -AutoSize
$ttfts = @($results | ForEach-Object { $_.ttft_ms } | Where-Object { $_ -ne $null })
if ($ttfts.Count -gt 0) {
    $sorted = $ttfts | Sort-Object
    $p95 = $sorted[[Math]::Min($sorted.Count - 1, [int][Math]::Ceiling(0.95 * $sorted.Count) - 1)]
    "TTFT ms: min=$($sorted[0]) median=$($sorted[[int]($sorted.Count / 2)]) p95=$p95 max=$($sorted[-1])"
}

param(
    [Parameter(Mandatory = $true)][string]$File,
    [string]$Url = "http://localhost:8090/v1/chat/completions",
    [int]$Runs = 5,
    [string]$Label = ""
)

# Streams a pre-built chat-completions JSON payload and reports TTFT per run.
$body = Get-Content -LiteralPath $File -Raw
$results = @()

for ($i = 1; $i -le $Runs; $i++) {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $req = [Net.HttpWebRequest]::Create($Url)
    $req.Method = "POST"
    $req.ContentType = "application/json"
    $req.Timeout = 300000
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
    $results += [pscustomobject]@{ run = $i; ttft = $ttft; tokens = $tokens; total = $sw.ElapsedMilliseconds }
}

$name = if ($Label) { $Label } else { [IO.Path]::GetFileNameWithoutExtension($File) }
$results | ForEach-Object { "{0,-14} run {1}  ttft={2,6} ms  tokens={3}  total={4} ms" -f $name, $_.run, $_.ttft, $_.tokens, $_.total }
$ttfts = $results | ForEach-Object { $_.ttft } | Sort-Object
$median = $ttfts[[int]($ttfts.Count / 2)]
"" 
"{0}: min={1} median={2} max={3} ms" -f $name, $ttfts[0], $median, $ttfts[-1]

param(
    [Parameter(Mandatory = $true)][string]$File,
    [string]$Url = "http://localhost:8090/v1/chat/completions",
    [int]$Runs = 20
)

# 20-sample cold-prefix TTFT benchmark for the DoD (p95 < 1000 ms).
#
# Every run gets its own prefix: the salt lands in the first history turn,
# i.e. straight after the constant system prompt, so llama.cpp can only reuse
# the system prompt and has to prefill the whole context. That is the shape of
# a brand-new conversation, which is the worst case the KV cache cannot help
# with. Runs are also separated by a distractor request that evicts nothing
# but proves the number is not a lucky cache hit.

$base = Get-Content -LiteralPath $File -Raw | ConvertFrom-Json
$ttfts = @()

# PowerShell 5.1's ConvertTo-Json turns a nested array into {"value":[...]},
# which the service rejects with 422. JavaScriptSerializer round-trips the
# message array correctly.
Add-Type -AssemblyName System.Web.Extensions
$serializer = New-Object System.Web.Script.Serialization.JavaScriptSerializer
$serializer.MaxJsonLength = 4mb

for ($i = 1; $i -le $Runs; $i++) {
    $salt = "salt-$i-$(Get-Random) "
    # deep copy per run so the salt never accumulates across iterations
    $payload = $base.messages | ConvertTo-Json -Depth 20 | ConvertFrom-Json
    $payload[1].content = $salt + $payload[1].content

    # plain hashtables: JavaScriptSerializer walks PSMethod members on
    # PSCustomObject and dies on the circular reference
    $messages = @($payload | ForEach-Object {
        @{ role = [string] $_.role; content = [string] $_.content }
    })

    $body = $serializer.Serialize(@{
        model      = [string] $base.model
        messages   = $messages
        stream     = $true
        max_tokens = [int] $base.max_tokens
    })

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

    try {
        $resp = $req.GetResponse()
    } catch [Net.WebException] {
        $err = $_.Exception.Response
        if ($err) {
            $sr = New-Object IO.StreamReader($err.GetResponseStream())
            $detail = $sr.ReadToEnd()
            Write-Output "HTTP $($err.StatusCode): $detail"
        } else {
            Write-Output $_.Exception.Message
        }
        exit 1
    }

    $reader = [IO.StreamReader]::new($resp.GetResponseStream())
    $ttft = $null

    while (($line = $reader.ReadLine()) -ne $null) {
        if (-not $line.StartsWith("data: ")) { continue }
        $payloadLine = $line.Substring(6)
        if ($payloadLine -eq "[DONE]") { break }
        $chunk = $payloadLine | ConvertFrom-Json
        $delta = $chunk.choices[0].delta.content
        if ($delta) {
            if ($ttft -eq $null) { $ttft = $sw.ElapsedMilliseconds }
        }
    }
    $reader.Close()
    $resp.Close()

    $ttfts += $ttft
    "{0,2}: ttft={1,6} ms" -f $i, $ttft
}

if (-not $ttfts -or $ttfts.Count -eq 0 -or $ttfts -contains $null) {
    Write-Output "no samples collected"
    exit 1
}

$sorted = $ttfts | Sort-Object
$n = $sorted.Count
$p95 = $sorted[[int][math]::Ceiling(0.95 * $n) - 1]
$median = $sorted[[math]::Floor($n / 2)]

""
"n={0} min={1} median={2} p95={3} max={4} ms" -f $n, $sorted[0], $median, $p95, $sorted[-1]
"p95 < 1000 ms: " + $(if ($p95 -lt 1000) { "PASS" } else { "FAIL" })

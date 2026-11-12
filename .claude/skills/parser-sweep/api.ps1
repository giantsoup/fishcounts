<#
.SYNOPSIS
    Call the fishcounts agent API with the token stored in Windows Credential Manager.

.DESCRIPTION
    Reads the generic credential named by $env:FISHCOUNTS_API_CREDENTIAL (default
    "fishcounts-agent-api"), sends one request, and prints the HTTP status line
    followed by the JSON response body. The token is never printed or written
    anywhere.

    Store the token once with:
        cmdkey /generic:fishcounts-agent-api /user:agent /pass:<token>

.EXAMPLE
    .claude/skills/parser-sweep/api.ps1 GET parser-errors -Query @{ status = 'open'; per_page = 100 }

.EXAMPLE
    .claude/skills/parser-sweep/api.ps1 POST species/12/aliases -Body @{ alias = 'Yellowfin Tuna'; parser_error_id = 345 }
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('GET', 'POST', 'PATCH', 'DELETE')]
    [string] $Method,

    [Parameter(Mandatory = $true, Position = 1)]
    [string] $Path,

    [hashtable] $Query = @{},

    [hashtable] $Body
)

$ErrorActionPreference = 'Stop'

if (-not ('FishcountsCredential' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class FishcountsCredential
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct CREDENTIAL
    {
        public int Flags;
        public int Type;
        public string TargetName;
        public string Comment;
        public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten;
        public int CredentialBlobSize;
        public IntPtr CredentialBlob;
        public int Persist;
        public int AttributeCount;
        public IntPtr Attributes;
        public string TargetAlias;
        public string UserName;
    }

    [DllImport("advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CredRead(string target, int type, int flags, out IntPtr credential);

    [DllImport("advapi32.dll")]
    private static extern void CredFree(IntPtr buffer);

    public static string Read(string target)
    {
        IntPtr pointer;
        if (!CredRead(target, 1, 0, out pointer))
        {
            return null;
        }

        try
        {
            CREDENTIAL credential = (CREDENTIAL)Marshal.PtrToStructure(pointer, typeof(CREDENTIAL));
            if (credential.CredentialBlobSize == 0)
            {
                return null;
            }

            return Marshal.PtrToStringUni(credential.CredentialBlob, credential.CredentialBlobSize / 2);
        }
        finally
        {
            CredFree(pointer);
        }
    }
}
'@
}

$credentialName = if ($env:FISHCOUNTS_API_CREDENTIAL) { $env:FISHCOUNTS_API_CREDENTIAL } else { 'fishcounts-agent-api' }
$baseUrl = if ($env:FISHCOUNTS_API_BASE) { $env:FISHCOUNTS_API_BASE.TrimEnd('/') } else { 'https://fish.tayloroyer.com/api' }

$token = [FishcountsCredential]::Read($credentialName)
if (-not $token) {
    Write-Output "NO_TOKEN: no generic credential named '$credentialName' in Windows Credential Manager."
    exit 2
}

$uri = "$baseUrl/$($Path.TrimStart('/'))"
if ($Query.Count -gt 0) {
    $pairs = foreach ($key in $Query.Keys) {
        '{0}={1}' -f [uri]::EscapeDataString([string] $key), [uri]::EscapeDataString([string] $Query[$key])
    }
    $uri = "$uri`?$($pairs -join '&')"
}

$request = @{
    Method             = $Method
    Uri                = $uri
    Headers            = @{ Authorization = "Bearer $token"; Accept = 'application/json' }
    UseBasicParsing    = $true
    MaximumRedirection = 0
}
if ($null -ne $Body) {
    $request.ContentType = 'application/json'
    $request.Body = $Body | ConvertTo-Json -Depth 10 -Compress
}

try {
    $response = Invoke-WebRequest @request
    $status = [int] $response.StatusCode
    $content = $response.Content
} catch [System.Net.WebException] {
    $errorResponse = $_.Exception.Response
    if ($null -eq $errorResponse) {
        Write-Output "NETWORK_ERROR: $($_.Exception.Message)"
        exit 3
    }
    $status = [int] $errorResponse.StatusCode
    $reader = New-Object System.IO.StreamReader($errorResponse.GetResponseStream())
    $content = $reader.ReadToEnd()
}

Write-Output "HTTP $status"
Write-Output $content

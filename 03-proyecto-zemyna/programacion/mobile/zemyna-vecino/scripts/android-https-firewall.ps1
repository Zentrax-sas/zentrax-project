param([switch]$CheckOnly)
$ErrorActionPreference = 'Stop'
$demoNode = (Get-Command node.exe -ErrorAction Stop).Source
$demoRule = 'Zemyna-LAN-HTTPS-Debug'
$demoAddress = Get-NetIPAddress -InterfaceAlias 'Wi-Fi' -AddressFamily IPv4 | Where-Object IPAddress -EQ '172.28.57.181'
if (-not $demoAddress -or $demoAddress.PrefixLength -ne 24) { throw 'Wi-Fi debe tener 172.28.57.181/24.' }
$rule = Get-NetFirewallRule -Name $demoRule -ErrorAction SilentlyContinue
$ready = $false
if ($rule) {
    $address = $rule | Get-NetFirewallAddressFilter
    $port = $rule | Get-NetFirewallPortFilter
    $application = $rule | Get-NetFirewallApplicationFilter
    $interface = $rule | Get-NetFirewallInterfaceFilter
    $ready = $rule.Enabled -eq 'True' -and $rule.Direction -eq 'Inbound' -and $rule.Action -eq 'Allow' -and
        @($address.LocalAddress).Count -eq 1 -and $address.LocalAddress -contains '172.28.57.181' -and
        @($address.RemoteAddress).Count -eq 1 -and ($address.RemoteAddress -contains '172.28.57.0/255.255.255.0' -or $address.RemoteAddress -contains '172.28.57.0/24') -and
        $port.Protocol -eq 'TCP' -and @($port.LocalPort).Count -eq 1 -and $port.LocalPort -contains '8443' -and
        $application.Program -ieq $demoNode -and @($interface.InterfaceAlias).Count -eq 1 -and $interface.InterfaceAlias -contains 'Wi-Fi'
}
if ($CheckOnly) { return $ready }
if ($ready) { exit 0 }
$settings = @{
    Direction='Inbound'; Action='Allow'; Enabled='True'; Program=$demoNode; Protocol='TCP';
    LocalPort=8443; LocalAddress='172.28.57.181'; RemoteAddress='172.28.57.0/24'; InterfaceAlias='Wi-Fi'; Profile='Any'
}
if ($rule) { Set-NetFirewallRule -Name $demoRule @settings | Out-Null }
else { New-NetFirewallRule -Name $demoRule -DisplayName 'Zemyna LAN HTTPS Debug' @settings | Out-Null }

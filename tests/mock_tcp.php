<?php
// Fake Ethernet relay board:  php mock_tcp.php PORT LOGFILE [modbus]
// Logs each received chunk as hex. With "modbus" it answers Modbus TCP write-coil requests with an echo (like real boards).
[$_, $port, $log, $mode] = $argv + [null, null, null, null];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es);
while ($c = stream_socket_accept($srv, 3600)) {
    stream_set_timeout($c, 1);
    $d = fread($c, 512);
    if ($d !== false && $d !== '') {
        file_put_contents($log, sprintf("%.3f %s\n", microtime(true), bin2hex($d)), FILE_APPEND);
        if ($mode === 'modbus') { fwrite($c, $d); }
    }
    fclose($c);
}

<?php
include 'vendor/autoload.php';

use Async\Task\Syscall;
use Async\Task\Scheduler;

function server($port) {
    echo "Starting server at port $port...\n";

    $socket = @stream_socket_server("tcp://localhost:$port", $errNo, $errStr);
    if (!$socket) throw new \Exception($errStr, $errNo);

    stream_set_blocking($socket, 0);

    while (true) {
        yield Syscall::waitForRead($socket);
        $clientSocket = stream_socket_accept($socket, 0);
        yield Syscall::coroutine(handleClient($clientSocket));
    }
}

function handleClient($socket) {
    yield Syscall::waitForRead($socket);
    $data = fread($socket, 8192);

    $msg = "Received following request:\n\n$data";
    $msgLength = strlen($msg);

    $response = <<<RES
HTTP/1.1 200 OK\r
Content-Type: text/plain\r
Content-Length: $msgLength\r
Connection: close\r
\r
$msg
RES;

    yield Syscall::waitForWrite($socket);
    fwrite($socket, $response);

    fclose($socket);
}

$scheduler = new Scheduler;
$scheduler->coroutine(server(8000));
$scheduler->run();

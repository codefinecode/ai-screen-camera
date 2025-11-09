<?php

namespace App\Console\Commands;

use App\WebSocket\PlayerWsServer;
use Illuminate\Console\Command;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer as RatchetWsServer;
use Ratchet\Http\HttpServer;
use React\EventLoop\Factory as LoopFactory;

class StartWsServer extends Command
{
    protected $signature = 'ws:serve {--host=0.0.0.0} {--port=8081}';
    protected $description = 'Start the WebSocket server for player communication';

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        $loop = LoopFactory::create();
        $wsComponent = new PlayerWsServer($loop);
        $server = new IoServer(
            new HttpServer(
                new RatchetWsServer($wsComponent)
            ),
            \React\Socket\SocketServer::create($host . ':' . $port, [], $loop),
            $loop
        );

        $this->info("WebSocket server listening on ws://{$host}:{$port}");
        $server->run();
        return 0;
    }
}

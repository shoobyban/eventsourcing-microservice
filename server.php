<?php

use React\EventLoop\Factory;
use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\Http\Request;
use React\Http\Response;

require __DIR__ . '/vendor/autoload.php';

class Service
{
    /**
     * get request matcher
     */
    protected function isMatch($query, $eventData): bool
    {
        $parts = explode('/', $query);
        if (count($parts) < 2) {
            $this->log($query . ' less than 2 parts');
            return true;
        }
        if (isset($parts[3])) {
            $this->log($query . ' more than 3 parts');
            if ($eventData[$parts[0]] === $parts[1]) {
                $this->log($query . ' normal match for '.$parts[0]);
                foreach ($eventData as $index => $value) {
                    if (is_array($value) && $eventData[$index][$parts[2]] === $parts[3]) {
                        $this->log($query . ' parent match and submatch with ' . $index);
                        return true;
                    }
                }
            }
        } elseif (isset($parts[2]) && $parts[0] == '*') {
            $this->log($query . ' *');
            foreach ($eventData as $index => $value) {
                if (is_array($value) && $eventData[$index][$parts[1]] === $parts[2]) {
                    $this->log($query . ' submatch with ' . $index);
                    return true;
                }
            }
        } elseif ($eventData[$parts[0]] === $parts[1]) {
            $this->log($query . ' normal match');
            return true;
        }
        $this->log(json_encode($parts));
        return false;
    }

    /**
     * Log
     * @param string $msg
     */
    protected function log($msg)
    {
     //   file_put_contents('./log.log', $msg . "\n", FILE_APPEND);
    }

    /**
     * Main
     * @param StreamSelectLoop $loop
     * @param string $uri
     * @param string $filePath
     */
    public function run($loop, $uri, $filePath)
    {
        $socket = new Server(isset($argv[1]) ? $argv[1] : $uri, $loop);

        $server = new \React\Http\Server($socket);
        $newid = 0;
        $server->on('request', function (Request $request, Response $response) use (&$newid, $filePath, $uri) {
            switch ($request->getMethod()) {
                case "GET":
                    $eventStream = file($filePath);
                    $result = [];
                    $query = ltrim(urldecode($request->getPath()), '/');
                    $this->log($query);
                    foreach ($eventStream as $eventLine) {
                        list($id, $eventData) = explode('::', $eventLine);
                        $eventData = unserialize(base64_decode($eventData));
                        if (!$this->isMatch($query, $eventData)) continue;
                        $this->log($query . ' matched');
                        $result[$id] = $eventData;
                    }
                    $response->writeHead(200, array('Content-Type' => 'text/plain'));
                    $response->end(json_encode($result));
                    break;
                case "POST":
                    $request->on('data', function ($data) use (&$newid, $filePath) {
                        parse_str($data, $event);
                        $newid++;
                        file_put_contents($filePath, $newid . '::' . base64_encode(serialize($event)) . "\n", FILE_APPEND);
                    });

                    $request->on('end', function () use ($response, &$newid, $uri) {
                        $response->writeHead(201, array('Content-Type' => 'text/plain', 'Location' => 'http://' . $uri . '/event/' . $newid));
                        $response->end("");
                    });

                    // an error occures e.g. on invalid chunked encoded data or an unexpected 'end' event
                    $request->on('error', function (\Exception $exception) use ($response, $newid) {
                        $response->writeHead(400, array('Content-Type' => 'text/plain'));
                        $response->end("An error occured while saving");
                    });
                    break;
            }

        });

        echo 'Listening on http://' . $socket->getAddress() . PHP_EOL;

        $loop->run();

    }
}

$filePath = getenv('TM_DATA');
if (!$filePath) {
    die("No TM_DATA environment variable defined");
}

$port = getenv('TM_PORT');
if (!$port) {
    die("No TM_PORT environment variable defined");
}

/** @var StreamSelectLoop $loop */
$loop = Factory::create();
$uri = '127.0.0.1:' . $port;

$service = new Service();
$service->run($loop, $uri, $filePath);

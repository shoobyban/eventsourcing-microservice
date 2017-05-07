<?php

class PostTest extends \PHPUnit_Framework_TestCase
{

    const TESTURI = 'http://127.0.0.1:1234/';

    protected $pid;

    const CURL_TIMEOUT = 1;

    public function setUp()
    {
        @unlink('test.data');
        $this->startService();
    }

    public function tearDown()
    {
        $this->stopService();
        @unlink('test.data');
    }

    public function testServerListensForPostOnPort1234()
    {
        $ch = curl_init(self::TESTURI);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $res = curl_exec($ch);
        curl_close($ch);
        $this->assertTrue($res, "Server doesn't listen on port 1234");
    }

    /**
     * @dataProvider hiredEventProvider
     */
    public function testServerReturns201OnPost($event)
    {
        $result = $this->postRequest($event);
        $this->assertSame(201, $result->status, "Server did not return 201");
    }

    /**
     * @dataProvider redirectCheckProvider
     */
    public function testServerRedirectsToGet($events)
    {
        foreach ($events as $eventData) {
            $event = $eventData[0];
            $redirectLocation = $eventData[1];
            $result = $this->postRequest($event);
            $this->assertSame($redirectLocation, $result->location, "Server did not redirect to $redirectLocation");
        }
    }

    /**
     * @dataProvider getReturnsEventStreamProvider
     */
    public function testGetFiltersEventStream($events, $requests)
    {
        foreach ($events as $event) {
            $this->postRequest($event);
        }
        foreach ($requests as $item) {
            $result = $this->getRequest(self::TESTURI.urlencode($item['request']));
            $this->assertSame(json_decode($result,true),$item['response'],
                "Event stream doesn't return values for ".$item['request']);
        }
    }

    /**
     * @dataProvider getReturnsEventStreamProvider
     */
    public function testServerPersistsData($events, $requests)
    {
        foreach ($events as $event) {
            $this->postRequest($event);
        }

        // Restart service
        $this->stopService();
        $this->startService();

        foreach ($requests as $item) {
            $result = $this->getRequest(self::TESTURI.urlencode($item['request']));
            $this->assertSame(json_decode($result,true),$item['response'],
                "Event stream doesn't persist values for ".$item['request']);
        }
    }

    protected function getRequest($url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    protected function postRequest($data): StdClass
    {
        $ch = curl_init(self::TESTURI);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $result = new StdClass();
        $result->body = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        $result->headers = substr($result->body, 0, $curl_info["header_size"]);
        $result->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        preg_match("!\r\n(?:Location|URI): *(.*?) *\r\n!", $result->headers, $matches);
        $result->location = $matches ? $matches[1] : '';
        curl_close($ch);
        return $result;
    }

    public function hiredEventProvider()
    {
        return [[[
            ['name' => 'developer', 'version' => '1.0', 'date' => date('c'), 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
        ]]];
    }

    public function redirectCheckProvider()
    {
        $nowDate = date('c');
        return [[[
            [['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => "John\n Doe"]],
                'http://127.0.0.1:1234/event/1'],
            [['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
                'http://127.0.0.1:1234/event/2']
        ]]];
    }

    public function getReturnsEventStreamProvider()
    {
        $nowDate = date('c');
        return [[
            [
                ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
                ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'left', 'payload' => ['name' => 'John Doe']],
                ['name' => 'squad', 'version' => '1.0', 'date' => $nowDate, 'event' => 'created', 'payload' => ['name' => 'Suicide Squad']],
            ],
            [
                [
                    'request' => '/name/developer',
                    'response' =>  [
                        '1' => ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
                        '2' => ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'left', 'payload' => ['name' => 'John Doe']],
                    ]
                ],
                [
                    'request' => '/name/squad',
                    'response' =>  [
                        '3' => ['name' => 'squad', 'version' => '1.0', 'date' => $nowDate, 'event' => 'created', 'payload' => ['name' => 'Suicide Squad']],
                    ]
                ],
                [
                    'request' => '/event/created',
                    'response' =>  [
                        '3' => ['name' => 'squad', 'version' => '1.0', 'date' => $nowDate, 'event' => 'created', 'payload' => ['name' => 'Suicide Squad']],
                    ]
                ],
                [
                    'request' => '/*/name/John Doe',
                    'response' =>  [
                        '1' => ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
                        '2' => ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'left', 'payload' => ['name' => 'John Doe']],
                    ]
                ],
                [
                    'request' => '/event/hired/name/John Doe',
                    'response' =>  [
                        '1' => ['name' => 'developer', 'version' => '1.0', 'date' => $nowDate, 'event' => 'hired', 'payload' => ['name' => 'John Doe']],
                    ]
                ],
            ]
        ]];
    }

    protected function startService()
    {
        $cmd = "TM_DATA=test.data TM_PORT=1234 exec php ./server.php";
        $this->pid = exec(sprintf("%s > /dev/null 2>&1 & echo $!", $cmd));
        sleep(3);
    }

    protected function stopService()
    {
        exec(sprintf("%s 2>&1", "kill {$this->pid}"));
    }

}

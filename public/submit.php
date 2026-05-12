<?php
use think\App;
require __DIR__ . '/../vendor/autoload.php';

$_SERVER['PATH_INFO'] = '/submit.php';

$http = (new App())->http;
$response = $http->run();
$response->send();
$http->end($response);

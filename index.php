<?php
use Silex\Application;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

//loader do Composer
$loader = require_once __DIR__.'/vendor/autoload.php';
$isinside = true;
require_once __DIR__.'/bd/banco.php';

$app = new Application();

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));


// ======================== GET ========================
$app->get('/', function () {
    $sql = new selectQuery('usuarios','*');
    $sql->execute();
    $result = $sql->resGetRowAssoc();
    return new Response (json_encode($result), 200);
})->value('id', null);

$app->get('/hello/{name}', function ($name) use ($app) {
    return $app['twig']->render('hello.twig', array(
        'name' => $name,
    ));
});

// ======================== POST ========================

// ======================== PUT ========================

// ======================== DELETE ========================


// $app->after(function (Request $request, Response $response) {
//     $response->headers->set('Content-Type', 'text/json');
// });
$app['debug'] = true;
$app->run();
